<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Refund;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Orders\Events\OrderEvent;
use App\Modules\Orders\Models\ReturnRequest;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Orders\Models\SalesOrderItem;
use App\Modules\Parts\Services\StockService;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Support\QuoteMath;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الإرجاع (SM-3b، BR-12، US-16، US-35): خلال مدة من التسليم، للقطع القابلة للإرجاع فقط، وبكمية لا تتجاوز
 * المسلَّم ناقص ما طُلب إرجاعه سابقاً. الاسترداد من المحاسب بإشعار دائن (FR-PAY-06).
 */
final class ReturnService
{
    public function __construct(private readonly StockService $stock, private readonly InvoiceService $invoices) {}

    /** هل الإرجاع ما زال متاحاً لهذا الطلب، وما المتبقي القابل للإرجاع من كل بند */
    public function eligibility(SalesOrder $order): array
    {
        $days = (int) Setting::read('returns.window_days', 14);
        $until = $order->delivered_at?->copy()->addDays($days);
        $open = $order->status === 'delivered' && $until && $until->isFuture();
        $requested = DB::table('return_request_items')
            ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
            ->where('return_requests.order_id', $order->id)->where('return_requests.status', '!=', 'rejected')
            ->groupBy('order_item_id')->selectRaw('order_item_id, SUM(quantity) AS qty')->pluck('qty', 'order_item_id');

        $items = $order->items->mapWithKeys(fn (SalesOrderItem $i) => [$i->id => $i->is_returnable
            ? bcsub((string) $i->quantity, (string) ($requested[$i->id] ?? '0'), 2) : '0.00']);

        return ['open' => $open && $items->contains(fn ($q) => bccomp($q, '0', 2) > 0), 'until' => $until, 'remaining' => $items->all()];
    }

    /**
     * 3b.1: طلب العميل بالبنود والسبب والصور
     *
     * @param  list<array{order_item_id: string, quantity: string}>  $items
     * @param  list<string>  $attachmentIds
     */
    public function request(SalesOrder $order, User $user, array $items, string $reason, array $attachmentIds): ReturnRequest
    {
        $rr = DB::transaction(function () use ($order, $user, $items, $reason, $attachmentIds) {
            $o = SalesOrder::query()->lockForUpdate()->with('items')->findOrFail($order->id);
            $e = $this->eligibility($o);
            if (! $e['open']) {
                throw new ApiProblem('RETURN_WINDOW_CLOSED', 422);
            }
            $errors = [];
            foreach ($items as $n => $it) {
                $left = $e['remaining'][$it['order_item_id']] ?? null;
                if ($left === null || bccomp($left, '0', 2) <= 0) {
                    $errors["items.{$n}.order_item_id"] = [__('orders.item_not_returnable')];
                } elseif (bccomp($it['quantity'], $left, 2) > 0) {
                    $errors["items.{$n}.quantity"] = [__('orders.return_qty_over', ['max' => rtrim(rtrim($left, '0'), '.')])];
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $rr = $o->returns()->create(['status' => 'requested', 'reason' => $reason, 'created_by' => $user->id]);
            foreach ($items as $it) {
                $rr->items()->create(['order_item_id' => $it['order_item_id'], 'quantity' => $it['quantity']]);
            }
            $rr->attachments()->sync($attachmentIds);
            Audit::record('return.requested', 'return_request', $rr->id, null, ['order' => $o->number], $user);

            return $rr;
        });
        OrderEvent::dispatch(OrderEvent::RETURN_REQUESTED, $order, $user, $reason, $rr);

        return $rr;
    }

    /** 3b.2 و 3b.3: قرار موظف القطع، والرفض بسبب إلزامي */
    public function decide(ReturnRequest $rr, User $staff, bool $approve, ?string $note): ReturnRequest
    {
        if (! $approve && blank($note)) {
            throw ValidationException::withMessages(['note' => [__('orders.reject_reason_required')]]);
        }
        $rr = $this->step($rr, $staff, 'requested', $approve ? 'approved' : 'rejected', fn (ReturnRequest $r) => $r->fill([
            'decision_note' => $note, 'decided_by' => $staff->id, 'decided_at' => now(),
        ]));
        OrderEvent::dispatch(OrderEvent::RETURN_DECIDED, $rr->order, $staff, $note, $rr);

        return $rr;
    }

    /**
     * 3b.4: استلام القطعة وفحصها. الصالحة تعود للمخزون في موقعها الأصلي.
     *
     * @param  array<string, bool>  $restock  order_item_id => صالحة
     */
    public function receive(ReturnRequest $rr, User $staff, array $restock): ReturnRequest
    {
        return $this->step($rr, $staff, 'approved', 'received', function (ReturnRequest $r) use ($staff, $restock) {
            foreach ($r->items()->with('orderItem')->get() as $line) {
                $good = (bool) ($restock[$line->order_item_id] ?? false);
                DB::table('return_request_items')->where('return_request_id', $r->id)->where('order_item_id', $line->order_item_id)
                    ->update(['restocked' => $good]);
                if ($good) {
                    $this->stock->move($line->orderItem->part_id, $line->orderItem->stock_location_id, (string) $line->quantity,
                        'return', $staff, null, ['return_request_id' => $r->id]);
                }
            }
            $r->fill(['received_by' => $staff->id, 'received_at' => now()]);
        });
    }

    /** مبلغ المرتجع من بنوده: نسبة كل بند بسعره وضريبته الأصليين، ومقرّبة لأقرب بيسة */
    public function amounts(ReturnRequest $rr): array
    {
        $sub = $vat = '0.000';
        foreach ($rr->items()->with('orderItem')->get() as $line) {
            $i = $line->orderItem;
            $sub = bcadd($sub, QuoteMath::round(bcdiv(bcmul((string) $i->line_subtotal, (string) $line->quantity, 6), (string) $i->quantity, 6), 3), 3);
            $vat = bcadd($vat, QuoteMath::round(bcdiv(bcmul((string) $i->line_vat, (string) $line->quantity, 6), (string) $i->quantity, 6), 3), 3);
        }

        return ['subtotal' => $sub, 'vat_total' => $vat, 'total' => bcadd($sub, $vat, 3)];
    }

    /** 3b.5: الاسترداد من المحاسب بطريقة الدفع (نقد أو تحويل)، مع إشعار دائن */
    public function refund(ReturnRequest $rr, User $accountant, string $method, ?string $reference): Refund
    {
        return DB::transaction(function () use ($rr, $accountant, $method, $reference) {
            $r = ReturnRequest::query()->lockForUpdate()->findOrFail($rr->id);
            if ($r->status !== 'received') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $r->status, 'to' => 'refunded']);
            }
            $payment = Payment::query()->where('sales_order_id', $r->order_id)->whereIn('status', ['succeeded', 'partially_refunded'])
                ->orderByDesc('amount')->first();
            if (! $payment) {
                throw new ApiProblem('NO_PAYMENT_TO_REFUND', 409);
            }
            $a = $this->amounts($r);
            $refund = $this->invoices->refund($payment, $accountant, [
                'amount' => $a['total'], 'subtotal' => $a['subtotal'], 'vat_total' => $a['vat_total'], 'method' => $method,
                'reason' => __('orders.return_refund_reason', ['reason' => $r->reason]), 'reference_text' => $reference, 'return_request_id' => $r->id,
            ]);
            $r->update(['status' => 'refunded']);
            Audit::record('return.refunded', 'return_request', $r->id, ['status' => 'received'], ['status' => 'refunded', 'refund_id' => $refund->id], $accountant);

            return $refund;
        });
    }

    private function step(ReturnRequest $rr, User $staff, string $from, string $to, callable $fn): ReturnRequest
    {
        return DB::transaction(function () use ($rr, $staff, $from, $to, $fn) {
            $r = ReturnRequest::query()->lockForUpdate()->findOrFail($rr->id);
            if ($r->status !== $from) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $r->status, 'to' => $to]);
            }
            $fn($r);
            $r->status = $to;
            $r->save();
            Audit::record("return.{$to}", 'return_request', $r->id, ['status' => $from], ['status' => $to], $staff);

            return $r->fresh(['order']);
        });
    }
}
