<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Accounts\Models\Address;
use App\Modules\Accounts\Support\AccountChoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Support\DocumentNumber;
use App\Modules\Orders\Events\OrderEvent;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Services\StockService;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Support\QuoteMath;
use App\Support\ApiProblem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إتمام الطلب (FR-ORD-02، C-11، M-11، SM-3a انتقال 3.0): التوصيل لعنوان محفوظ برسوم محافظته أو الاستلام من فرع،
 * والدفع عند الاستلام أو التسليم (BR-18). الطلب يحجز المخزون من فرع واحد يكفي كل البنود.
 */
final class CheckoutService
{
    public function __construct(private readonly CartService $cart, private readonly StockService $stock) {}

    /**
     * ملخص الطلب قبل التأكيد: البنود والرسوم والإجمالي والتنبيهات، وما يتاح من فروع ومحافظات.
     *
     * @param  array{fulfillment?: string|null, branch_id?: string|null, address_id?: string|null, organization_id?: string|null}  $data
     * @return array<string, mixed>
     */
    public function preview(User $user, array $data): array
    {
        $orgId = AccountChoice::resolve($user, $data, false);
        $view = $this->cart->view($user);
        $lines = $this->lines($user);
        $branches = Branch::query()->where('is_active', true)->orderBy('code')->get();
        $fees = (array) Setting::read('delivery.fee_by_governorate', []);
        $plan = $this->plan($user, $orgId, $data, $lines, false);

        return [
            'cart' => $view,
            'fulfillment' => $plan['fulfillment'],
            'branch_id' => $plan['branch']?->id,
            'delivery_fee' => Money::of($plan['fee']),
            'totals' => ['subtotal' => Money::of($plan['subtotal']), 'vat_total' => Money::of($plan['vat_total']),
                'delivery_fee' => Money::of($plan['fee']), 'total' => Money::of($plan['total'])],
            'needs_phone_confirmation' => $plan['needs_phone'],
            'problem' => $plan['problem'],
            'open_orders' => $this->openOrders($user),
            'branches' => $branches->map(fn (Branch $b) => [
                'id' => $b->id, 'name' => $b->localized('name'), 'address' => $b->localized('address'),
                'can_fulfill' => $lines !== [] && $this->branchCanFulfill($b->id, $lines),
            ])->values(),
            'delivery_fees' => collect($fees)->map(fn ($v) => $v === null ? null : Money::of((string) $v)),
            'accepted_methods' => ['cash', 'pos_card', 'bank_transfer'],
        ];
    }

    /**
     * 3.0: التأكيد. السلة يجب ألا تتغير منذ رآها العميل، وحد الطلبات المفتوحة غير المدفوعة (BR-05).
     *
     * @param  array{fulfillment: string, branch_id?: string|null, address_id?: string|null, organization_id?: string|null, notes?: string|null}  $data
     */
    public function place(User $user, array $data): SalesOrder
    {
        $orgId = AccountChoice::resolve($user, $data, false);

        $order = DB::transaction(function () use ($user, $data, $orgId) {
            DB::table('carts')->where('user_id', $user->id)->lockForUpdate()->first();
            $view = $this->cart->view($user);
            if ($view['items'] === []) {
                throw new ApiProblem('CART_EMPTY', 422);
            }
            if ($view['has_changes']) {
                throw new ApiProblem('CART_CHANGED', 409);
            }
            $open = $this->openOrders($user);
            if ($open['blocked']) {
                throw new ApiProblem('ORDER_LIMIT', 422, null, ['limit' => $open['limit']]);
            }
            $lines = $this->lines($user);
            $plan = $this->plan($user, $orgId, $data, $lines, true);

            $order = SalesOrder::query()->create([
                'number' => DocumentNumber::next('SO'),
                'customer_user_id' => $user->id,
                'organization_id' => $orgId,
                'status' => 'confirmed',
                'fulfillment' => $plan['fulfillment'],
                'branch_id' => $plan['branch']->id,
                'delivery_address' => $plan['address'],
                'delivery_governorate' => $plan['address']['governorate'] ?? null,
                'payment_method' => 'pay_on_fulfillment',
                'needs_phone_confirmation' => $plan['needs_phone'],
                'subtotal' => $plan['subtotal'],
                'vat_total' => $plan['vat_total'],
                'delivery_fee' => $plan['fee'],
                'delivery_vat' => $plan['fee_vat'],
                'total' => $plan['total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            foreach ($lines as $n => $l) {
                $location = $this->stock->locationFor($l['part']->id, $plan['branch']->id, $l['quantity']);
                if (! $location) {
                    throw new ApiProblem('STOCK_INSUFFICIENT', 422, null, ['part_id' => $l['part']->id]);
                }
                $this->stock->reserve($l['part']->id, $location, $l['quantity']);
                $order->items()->create(self::itemRow($l['part'], $n + 1, $l['quantity'], (string) $l['part']->price, '0', $location));
            }
            $order->events()->create(['status' => 'confirmed', 'actor_id' => $user->id]);
            $this->cart->clear($user);
            Audit::record('order.created', 'sales_order', $order->id, null, ['number' => $order->number, 'total' => $order->total], $user);

            return $order;
        });
        OrderEvent::dispatch(OrderEvent::CONFIRMED, $order, $user);

        return $order;
    }

    /** @return array<string, mixed> صف بند بنسخة ثابتة من القطعة (BR-17) */
    public static function itemRow(Part $part, int $lineNo, string $qty, string $unitPrice, string $discount, string $locationId, ?string $vatRate = null): array
    {
        $rate = $vatRate ?? (string) $part->vat_rate;

        return [
            'line_no' => $lineNo, 'part_id' => $part->id,
            'part_number_snapshot' => $part->part_number, 'name_ar_snapshot' => $part->name_ar, 'name_en_snapshot' => $part->name_en ?: $part->name_ar,
            'unit' => $part->unit, 'is_returnable' => $part->is_returnable, 'warranty_months' => $part->warranty_months,
            'quantity' => $qty, 'unit_price' => $unitPrice, 'discount_amount' => $discount, 'vat_rate' => $rate,
            'stock_location_id' => $locationId,
        ] + QuoteMath::line($qty, $unitPrice, $discount, $rate);
    }

    /** طلبات العميل المفتوحة غير المدفوعة من السلة، والطلب الناتج عن عرض لا يُحسب (BR-05، 3.14) */
    public function openOrders(User $user): array
    {
        $limit = (int) Setting::read('order.max_open_unpaid_per_customer', 3);
        $count = SalesOrder::query()->where('customer_user_id', $user->id)->whereIn('status', SalesOrder::OPEN)->whereNull('rfq_id')
            ->whereNotExists(fn ($q) => $q->from('payments')->whereColumn('payments.sales_order_id', 'sales_orders.id')->whereIn('payments.status', Payment::COLLECTED))
            ->count();

        return ['count' => $count, 'limit' => $limit, 'blocked' => $count >= $limit];
    }

    /** @return list<array{part: Part, quantity: string}> */
    private function lines(User $user): array
    {
        $rows = DB::table('cart_items')->where('cart_id', $this->cart->cartId($user))->orderBy('created_at')->get();
        $parts = Part::query()->whereIn('id', $rows->pluck('part_id'))->where('is_active', true)->get()->keyBy('id');

        return $rows->filter(fn ($r) => isset($parts[$r->part_id]))
            ->map(fn ($r) => ['part' => $parts[$r->part_id], 'quantity' => (string) $r->quantity])->values()->all();
    }

    private function branchCanFulfill(string $branchId, array $lines): bool
    {
        foreach ($lines as $l) {
            if (! $this->stock->locationFor($l['part']->id, $branchId, $l['quantity'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * الفرع والعنوان والرسوم والإجماليات. strict يرمي أخطاء التحقق، والمعاينة تعيد problem بدلها.
     *
     * @return array{fulfillment: string|null, branch: Branch|null, address: array<string, mixed>|null, fee: string, fee_vat: string, subtotal: string, vat_total: string, total: string, needs_phone: bool, problem: string|null}
     */
    private function plan(User $user, ?string $orgId, array $data, array $lines, bool $strict): array
    {
        $fulfillment = $data['fulfillment'] ?? null;
        $problem = null;
        $fail = function (string $field, string $key) use ($strict, &$problem) {
            if ($strict) {
                throw ValidationException::withMessages([$field => [__("orders.{$key}")]]);
            }
            $problem ??= $key;
        };

        $branch = null;
        $address = null;
        $fee = '0.000';
        if ($fulfillment === 'pickup') {
            $branch = Branch::query()->where('is_active', true)->find($data['branch_id'] ?? null);
            if (! $branch) {
                $fail('branch_id', 'branch_required');
            } elseif (! $this->branchCanFulfill($branch->id, $lines)) {
                $fail('branch_id', 'branch_cannot_fulfill');
            }
        } elseif ($fulfillment === 'delivery') {
            $a = ($data['address_id'] ?? null)
                ? Address::query()->ownedBy($user, $orgId)->whereNull('archived_at')->withCoordinates()->find($data['address_id']) : null;
            if (! $a) {
                $fail('address_id', 'address_required');
            } else {
                $configured = (array) Setting::read('delivery.fee_by_governorate', []);
                $governorateFee = $configured[$a->governorate] ?? null;
                if ($governorateFee === null) {
                    $fail('address_id', 'no_delivery_to_governorate');
                } else {
                    $fee = QuoteMath::round((string) $governorateFee, 3);
                }
                $address = [
                    'label' => $a->label, 'governorate' => $a->governorate, 'wilayat' => $a->wilayat, 'description' => $a->description,
                    'contact_name' => $a->contact_name, 'contact_phone' => $a->contact_phone,
                    'latitude' => $a->latitude, 'longitude' => $a->longitude, 'text' => $a->snapshot($user->preferred_locale ?? 'ar'),
                ];
                // الفرع الذي يغطي محافظة العنوان أولاً، ثم أي فرع يكفي مخزونه كل البنود
                $branch = Branch::query()->where('is_active', true)->orderBy('code')->get()
                    ->sortByDesc(fn (Branch $b) => in_array($a->governorate, $b->covered_governorates ?? [], true))
                    ->first(fn (Branch $b) => $this->branchCanFulfill($b->id, $lines));
                if (! $branch && $lines !== []) {
                    $fail('fulfillment', 'no_branch_can_fulfill');
                }
            }
        } else {
            $fail('fulfillment', 'fulfillment_required');
        }

        $rows = array_map(fn ($l) => QuoteMath::line($l['quantity'], (string) $l['part']->price, '0', (string) $l['part']->vat_rate)
            + ['discount_amount' => '0'], $lines);
        $t = QuoteMath::totals($rows);
        $feeVat = bccomp($fee, '0', 3) > 0 ? QuoteMath::line('1', $fee, '0', (string) Setting::read('vat.default_rate', '5.00'))['line_vat'] : '0.000';
        $vat = bcadd($t['vat_total'], $feeVat, 3);
        $total = bcadd(bcadd($t['subtotal'], $fee, 3), $vat, 3);
        $needsPhone = $fulfillment === 'delivery' && bccomp($total, (string) Setting::read('order.phone_confirm_above_amount', '100.000'), 3) > 0;

        return ['fulfillment' => $fulfillment, 'branch' => $branch, 'address' => $address, 'fee' => $fee, 'fee_vat' => $feeVat,
            'subtotal' => $t['subtotal'], 'vat_total' => $vat, 'total' => $total, 'needs_phone' => $needsPhone, 'problem' => $problem];
    }
}
