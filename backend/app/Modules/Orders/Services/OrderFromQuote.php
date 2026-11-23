<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Billing\Support\DocumentNumber;
use App\Modules\Orders\Events\OrderEvent;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Services\StockService;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Models\Quotation;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * طلب القطع من عرض قطع مقبول (SM-3a انتقال 3.14، FR-RFQ-05): البنود والأسعار من العرض لا من الكتالوج (BR-17)،
 * ولا يخضع لحد الطلبات المفتوحة. البند غير المدرج يُربط بقطعة في الكتالوج أولاً (map: رقم السطر => معرّف القطعة).
 * عند القبول يُنشأ آلياً إن كانت كل البنود قطعاً مدرجة ومتوفرة، وإلا ينشئه موظف القطع من A-05.
 */
final class OrderFromQuote
{
    public function __construct(private readonly StockService $stock) {}

    /** @param array<int, string> $map */
    public function create(Quotation $q, User $actor, array $map = []): SalesOrder
    {
        $order = DB::transaction(function () use ($q, $actor, $map) {
            $q = Quotation::query()->lockForUpdate()->with(['items', 'rfq'])->findOrFail($q->id);
            $rfq = $q->rfq;
            if ($q->status !== 'accepted' || $rfq->type !== 'parts') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['status' => $q->status, 'type' => $rfq->type]);
            }
            if (SalesOrder::query()->where('rfq_id', $rfq->id)->exists()) {
                throw new ApiProblem('ORDER_EXISTS', 409);
            }

            $errors = [];
            $lines = [];
            foreach ($q->items as $item) {
                $partId = $item->part_id ?? ($map[$item->line_no] ?? null);
                $part = ($item->item_type === 'part' && $partId) ? Part::query()->find($partId) : null;
                if (! $part) {
                    $errors["map.{$item->line_no}"] = [__($item->item_type === 'part' ? 'orders.line_needs_part' : 'orders.line_not_part', ['line' => $item->line_no])];

                    continue;
                }
                $lines[] = [$item, $part];
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $address = $rfq->delivery_address_text ? ['text' => $rfq->delivery_address_text] : null;
            $order = SalesOrder::query()->create([
                'number' => DocumentNumber::next('SO'),
                'customer_user_id' => $rfq->customer_user_id,
                'organization_id' => $rfq->organization_id,
                'rfq_id' => $rfq->id,
                'quotation_id' => $q->id,
                'status' => 'confirmed',
                'fulfillment' => $address ? 'delivery' : 'pickup',
                'branch_id' => $rfq->branch_id,
                'delivery_address' => $address,
                'payment_method' => 'pay_on_fulfillment',
                'subtotal' => bcsub((string) $q->subtotal, (string) $q->discount_total, 3),
                'vat_total' => $q->vat_total,
                'total' => $q->total,
                'created_by' => $actor->id,
            ]);
            foreach ($lines as $n => [$item, $part]) {
                $location = $this->stock->locationFor($part->id, $rfq->branch_id, (string) $item->quantity);
                if (! $location) {
                    throw new ApiProblem('STOCK_INSUFFICIENT', 422, null, ['part_id' => $part->id, 'line' => $item->line_no]);
                }
                $this->stock->reserve($part->id, $location, (string) $item->quantity);
                $order->items()->create(CheckoutService::itemRow($part, $n + 1, (string) $item->quantity, (string) $item->unit_price,
                    (string) $item->discount_amount, $location, (string) $item->vat_rate));
            }
            $order->events()->create(['status' => 'confirmed', 'note' => $q->number, 'actor_id' => $actor->id]);
            Audit::record('order.created_from_quote', 'sales_order', $order->id, null, ['number' => $order->number, 'quotation' => $q->number], $actor);

            return $order;
        });
        OrderEvent::dispatch(OrderEvent::CONFIRMED, $order, $actor);

        return $order;
    }
}
