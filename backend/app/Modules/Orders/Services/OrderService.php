<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Orders\Events\OrderEvent;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Orders\Support\WorkingDays;
use App\Modules\Parts\Services\StockService;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\Audit;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * انتقالات طلب القطع (SM-3a). كل انتقال بقفل صف الطلب، وسطر في الخط الزمني، وتدقيق،
 * والحدث بعد الالتزام للإشعارات.
 */
final class OrderService
{
    /** محاولات رمز الاستلام الخاطئة قبل القفل المؤقت */
    private const CODE_ATTEMPTS = 5;

    public function __construct(private readonly StockService $stock, private readonly InvoiceService $invoices) {}

    /** 3.5: بدء التجهيز. للتوصيل فوق المبلغ المحدد يلزم تسجيل التأكيد الهاتفي أولاً (BR-18). */
    public function startProcessing(SalesOrder $order, User $staff): SalesOrder
    {
        return $this->transition($order, $staff, ['confirmed'], 'processing', function (SalesOrder $o) {
            if ($o->needs_phone_confirmation && ! $o->phone_confirmed_at) {
                throw new ApiProblem('PHONE_CONFIRMATION_REQUIRED', 409);
            }
        });
    }

    /** نتيجة الاتصال قبل التجهيز (BR-18، US-35). confirmed=false يلغي الطلب بالسبب. */
    public function recordPhoneConfirmation(SalesOrder $order, User $staff, bool $confirmed, string $note): SalesOrder
    {
        if (! $confirmed) {
            return $this->cancel($order, $staff, __('orders.phone_not_confirmed', ['note' => $note]));
        }

        return DB::transaction(function () use ($order, $staff, $note) {
            $o = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($o->status, ['confirmed', 'processing'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $o->status]);
            }
            $o->update(['phone_confirmed_at' => now(), 'phone_confirmation_note' => $note]);
            $o->events()->create(['status' => 'phone_confirmed', 'note' => $note, 'actor_id' => $staff->id]);
            Audit::record('order.phone_confirmed', 'sales_order', $o->id, null, ['note' => $note], $staff);

            return $o;
        });
    }

    /** 3.8: جاهز للاستلام برمز من 6 أرقام ومهلة أيام عمل (BR-05) */
    public function markReady(SalesOrder $order, User $staff): SalesOrder
    {
        return $this->transition($order, $staff, ['processing'], 'ready_for_pickup', function (SalesOrder $o) {
            if ($o->fulfillment !== 'pickup') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['fulfillment' => $o->fulfillment]);
            }
            $o->pickup_code_enc = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $o->pickup_deadline_at = WorkingDays::deadline(now(), (int) Setting::read('order.pickup_hold_working_days', 3));
            $o->pickup_reminded_at = null;
        }, OrderEvent::READY);
    }

    /** 3.9: خرج للتوصيل */
    public function dispatchOut(SalesOrder $order, User $staff): SalesOrder
    {
        return $this->transition($order, $staff, ['processing'], 'out_for_delivery', function (SalesOrder $o) {
            if ($o->fulfillment !== 'delivery') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['fulfillment' => $o->fulfillment]);
            }
        }, OrderEvent::OUT_FOR_DELIVERY);
    }

    /** 3.10: تعذر التسليم أو رفض العميل الدفع، بسبب، فيعود للتجهيز (N-26) */
    public function deliveryFailed(SalesOrder $order, User $staff, string $reason): SalesOrder
    {
        return $this->transition($order, $staff, ['out_for_delivery'], 'processing', null, OrderEvent::DELIVERY_FAILED, $reason, 'delivery_failed');
    }

    /**
     * 3.11 و 3.12: التسليم. من الفرع برمز الاستلام الصحيح، وبالتوصيل بصورة إثبات، ومع تسجيل الدفعة بالمتبقي
     * في نفس المعاملة (BR-18). ثم خصم المخزون وإصدار الفاتورة مدفوعة.
     *
     * @param  array{code?: string|null, proof_id?: string|null, payment?: array{method: string, amount: string, reference_text?: string|null, proof_id?: string|null}|null}  $data
     */
    public function deliver(SalesOrder $order, User $staff, array $data): SalesOrder
    {
        $key = "pickup-code:{$order->id}";
        if (RateLimiter::tooManyAttempts($key, self::CODE_ATTEMPTS)) {
            throw new ApiProblem('PICKUP_CODE_LOCKED', 429, RateLimiter::availableIn($key));
        }

        $order = DB::transaction(function () use ($order, $staff, $data, $key) {
            $o = SalesOrder::query()->lockForUpdate()->with('items')->findOrFail($order->id);
            if (! in_array($o->status, ['ready_for_pickup', 'out_for_delivery'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $o->status, 'to' => 'delivered']);
            }
            if ($o->status === 'ready_for_pickup' && ! hash_equals((string) $o->pickup_code_enc, (string) ($data['code'] ?? ''))) {
                RateLimiter::hit($key, 900);
                throw new ApiProblem('PICKUP_CODE_INVALID', 422);
            }
            if ($o->status === 'out_for_delivery' && empty($data['proof_id'])) {
                throw ValidationException::withMessages(['proof_id' => [__('orders.proof_required')]]);
            }

            $balance = bcsub((string) $o->total, $o->amountPaid(), 3);
            if (bccomp($balance, '0', 3) > 0 && $o->payment_method !== 'credit') {
                $p = $data['payment'] ?? null;
                if (! $p) {
                    throw new ApiProblem('PAYMENT_REQUIRED', 422, null, ['balance' => $balance]);
                }
                if (bccomp($p['amount'], $balance, 3) !== 0) {
                    throw ValidationException::withMessages(['payment.amount' => [__('orders.payment_must_equal', ['balance' => $balance])]]);
                }
                $this->invoices->payment(['sales_order_id' => $o->id, 'branch_id' => $o->branch_id], $staff, $p);
            }

            foreach ($o->items as $item) {
                $this->stock->sell($item->part_id, $item->stock_location_id, (string) $item->quantity, $staff, $o->id);
            }
            $from = $o->status;
            $o->update(['status' => 'delivered', 'delivered_at' => now(), 'delivered_by' => $staff->id,
                'proof_id' => $data['proof_id'] ?? null, 'pickup_code_enc' => null]);
            $o->events()->create(['status' => 'delivered', 'actor_id' => $staff->id]);
            Audit::record('order.delivered', 'sales_order', $o->id, ['status' => $from], ['status' => 'delivered'], $staff);

            // الفاتورة تصدر في نفس المعاملة (BR-18): لا تسليم بلا فاتورة، ولا رقم INV بلا تسليم
            $this->invoices->issue($this->invoices->draftFromOrder($o), $staff);

            return $o;
        });
        RateLimiter::clear($key);
        OrderEvent::dispatch(OrderEvent::DELIVERED, $order, $staff);

        return $order->fresh();
    }

    /**
     * 3.6 و 3.7 و 3.13: الإلغاء. العميل قبل التجهيز فقط (BR-13)، والموظف حتى نهاية التجهيز بسبب إلزامي،
     * والنظام (actor = null) عند انتهاء مهلة الاستلام. يُحرَّر الحجز دائماً.
     */
    public function cancel(SalesOrder $order, ?User $actor, string $reason, bool $byCustomer = false): SalesOrder
    {
        $allowed = match (true) {
            $actor === null => ['ready_for_pickup'],
            $byCustomer => ['confirmed'],
            default => ['confirmed', 'processing'],
        };

        return $this->transition($order, $actor, $allowed, 'cancelled', function (SalesOrder $o) use ($reason, $actor) {
            if (Payment::query()->where('sales_order_id', $o->id)->whereIn('status', Payment::COLLECTED)->exists()) {
                // الدفع يسبق التسليم فقط في P3 (إلكتروني) أو بالآجل: الاسترداد من المحاسب قبل الإلغاء
                throw new ApiProblem('REFUND_FIRST', 409);
            }
            foreach ($o->items as $item) {
                $this->stock->release($item->part_id, $item->stock_location_id, (string) $item->quantity);
            }
            $o->cancel_reason = $reason;
            $o->cancelled_at = now();
            $o->pickup_code_enc = null;
            if ($actor === null) {
                DB::table('users')->where('id', $o->customer_user_id)->increment('pickup_no_show_count'); // 02 §10
            }
        }, OrderEvent::CANCELLED, $reason);
    }

    /** J-01 (كل 5 دقائق): تذكير قبل انتهاء المهلة بيوم مرة واحدة، ثم الإلغاء بعد انتهائها (BR-05، 3.13) */
    public function expireDue(): array
    {
        $reminded = 0;
        SalesOrder::query()->where('status', 'ready_for_pickup')->whereNull('pickup_reminded_at')
            ->where('pickup_deadline_at', '>', now())->where('pickup_deadline_at', '<=', now()->addDay())
            ->pluck('id')->each(function ($id) use (&$reminded) {
                $o = DB::transaction(function () use ($id) {
                    $o = SalesOrder::query()->lockForUpdate()->find($id);
                    if (! $o || $o->status !== 'ready_for_pickup' || $o->pickup_reminded_at) {
                        return null;
                    }
                    $o->update(['pickup_reminded_at' => now()]);

                    return $o;
                });
                if ($o) {
                    $reminded++;
                    OrderEvent::dispatch(OrderEvent::PICKUP_REMINDER, $o);
                }
            });

        $cancelled = 0;
        SalesOrder::query()->where('status', 'ready_for_pickup')->where('pickup_deadline_at', '<=', now())
            ->pluck('id')->each(function ($id) use (&$cancelled) {
                try {
                    $this->cancel(SalesOrder::query()->findOrFail($id), null, __('orders.not_collected'));
                    $cancelled++;
                } catch (ApiProblem) {
                    // تغيرت حالته بين الاستعلام والقفل: لا شيء
                }
            });

        return ['reminded' => $reminded, 'cancelled' => $cancelled];
    }

    /**
     * @param  list<string>  $from
     * @param  (callable(SalesOrder): void)|null  $guard  يعدّل الطلب قبل الحفظ أو يرفض
     */
    private function transition(SalesOrder $order, ?User $actor, array $from, string $to, ?callable $guard = null,
        ?string $event = null, ?string $note = null, ?string $eventStatus = null): SalesOrder
    {
        $o = DB::transaction(function () use ($order, $actor, $from, $to, $guard, $note, $eventStatus) {
            $o = SalesOrder::query()->lockForUpdate()->with('items')->findOrFail($order->id);
            if (! in_array($o->status, $from, true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $o->status, 'to' => $to]);
            }
            $before = $o->status;
            if ($guard) {
                $guard($o);
            }
            $o->status = $to;
            $o->save();
            $o->events()->create(['status' => $eventStatus ?? $to, 'note' => $note, 'actor_id' => $actor?->id]);
            Audit::record("order.{$to}", 'sales_order', $o->id, ['status' => $before], ['status' => $to, 'note' => $note], $actor);

            return $o;
        });
        if ($event) {
            OrderEvent::dispatch($event, $o, $actor, $note);
        }

        return $o;
    }
}
