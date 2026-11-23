<?php

namespace App\Modules\Orders\Http;

use App\Modules\Billing\Models\Payment;
use App\Modules\Orders\Models\ReturnRequest;
use App\Modules\Orders\Models\ReturnRequestItem;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Orders\Models\SalesOrderEvent;
use App\Modules\Orders\Models\SalesOrderItem;
use App\Modules\Platform\Models\Attachment;
use App\Support\Money;

/** شكل طلب القطع في الـ API. رمز الاستلام للعميل وحده، وبيانات الاتصال والدفعات للموظف. */
final class OrderPresenter
{
    public const WITH = ['items', 'events.actor', 'branch', 'organization', 'customer', 'invoice.pdf', 'returns.items.orderItem', 'returns.attachments', 'payments.recorder'];

    public static function row(SalesOrder $o, bool $staff = false): array
    {
        return [
            'id' => $o->id,
            'number' => $o->number,
            'status' => $o->status,
            'fulfillment' => $o->fulfillment,
            'items_count' => $o->relationLoaded('items') ? $o->items->count() : null,
            'summary' => $o->relationLoaded('items') ? $o->items->take(3)->map(fn (SalesOrderItem $i) => self::name($i))->values() : [],
            'total' => Money::of((string) $o->total),
            'branch' => $o->relationLoaded('branch') && $o->branch ? ['id' => $o->branch->id, 'name' => $o->branch->localized('name')] : null,
            /** @var array{id: string, name: string}|null */
            'account' => $o->relationLoaded('organization') && $o->organization ? ['id' => $o->organization->id, 'name' => $o->organization->name] : null,
            'customer' => $staff ? ['id' => $o->customer?->id, 'name' => $o->customer?->full_name, 'phone' => $o->customer?->phone_e164, 'organization' => $o->organization?->name] : null,
            'needs_phone_confirmation' => $o->needs_phone_confirmation && ! $o->phone_confirmed_at,
            'pickup_deadline_at' => $o->pickup_deadline_at?->toIso8601ZuluString(),
            'from_quote' => $o->rfq_id !== null,
            'created_at' => $o->created_at?->toIso8601ZuluString(),
        ];
    }

    /** @param array{open: bool, until: mixed, remaining: array<string, string>} $returns */
    public static function detail(SalesOrder $o, array $returns, bool $staff = false): array
    {
        $paid = $o->relationLoaded('payments')
            ? (string) $o->payments->whereIn('status', Payment::COLLECTED)->sum('amount') : $o->amountPaid();
        $balance = bcsub((string) $o->total, $paid, 3);
        $branch = $o->branch;

        return self::row($o, $staff) + [
            'payment_method' => $o->payment_method,
            'accepted_methods' => ['cash', 'pos_card', 'bank_transfer'],
            'branch_detail' => $branch ? [
                'id' => $branch->id, 'name' => $branch->localized('name'), 'address' => $branch->localized('address'),
                'phone' => $branch->phone, 'whatsapp' => $branch->whatsapp_parts ?? $branch->whatsapp_sales, 'working_hours' => $branch->working_hours,
            ] : null,
            /** @var array{text: string|null, governorate: string|null, contact_name: string|null, contact_phone: string|null}|null */
            'delivery_address' => $o->delivery_address ? [
                'text' => $o->delivery_address['text'] ?? null, 'governorate' => $o->delivery_address['governorate'] ?? null,
                'contact_name' => $o->delivery_address['contact_name'] ?? null, 'contact_phone' => $o->delivery_address['contact_phone'] ?? null,
            ] : null,
            'items' => $o->items->map(fn (SalesOrderItem $i) => [
                'id' => $i->id, 'line_no' => $i->line_no, 'part_id' => $i->part_id, 'part_number' => $i->part_number_snapshot,
                'name' => self::name($i), 'unit' => $i->unit, 'quantity' => (string) $i->quantity,
                'unit_price' => Money::of((string) $i->unit_price), 'discount' => Money::of((string) $i->discount_amount),
                'vat_rate' => (string) $i->vat_rate, 'line_subtotal' => Money::of((string) $i->line_subtotal), 'line_total' => Money::of((string) $i->line_total),
                'is_returnable' => $i->is_returnable, 'warranty_months' => $i->warranty_months,
                'returnable_quantity' => $returns['remaining'][$i->id] ?? '0.00',
                'stock_location_id' => $staff ? $i->stock_location_id : null,
            ])->values(),
            'totals' => [
                'subtotal' => Money::of((string) $o->subtotal), 'delivery_fee' => Money::of((string) $o->delivery_fee),
                'vat_total' => Money::of((string) $o->vat_total), 'total' => Money::of((string) $o->total),
                'paid' => Money::of($paid), 'balance' => Money::of(bccomp($balance, '0', 3) > 0 ? $balance : '0.000'),
            ],
            // للعميل فقط: يعرضه للموظف في الفرع (US-15). لا يظهر للموظف فلا يُسلَّم دون حضور العميل
            'pickup_code' => ! $staff && $o->status === 'ready_for_pickup' ? $o->pickup_code_enc : null,
            'phone_confirmation' => $o->needs_phone_confirmation ? [
                'confirmed_at' => $o->phone_confirmed_at?->toIso8601ZuluString(), 'note' => $staff ? $o->phone_confirmation_note : null,
            ] : null,
            'notes' => $o->notes,
            'cancel_reason' => $o->cancel_reason,
            'delivered_at' => $o->delivered_at?->toIso8601ZuluString(),
            'timeline' => $o->events->map(fn (SalesOrderEvent $e) => [
                'status' => $e->status, 'note' => $e->note, 'at' => $e->created_at?->toIso8601ZuluString(),
                'by' => $staff ? $e->actor?->full_name : null,
            ])->values(),
            'can_cancel' => ! $staff && $o->status === 'confirmed',
            'can_return' => $returns['open'],
            'return_until' => $returns['until']?->toIso8601ZuluString(),
            'returns' => $o->returns->map(fn (ReturnRequest $r) => self::returnRow($r, $staff))->values(),
            /** @var array{id: string, number: string|null, status: string, pdf_url: string|null}|null */
            'invoice' => $o->invoice && $o->invoice->status !== 'draft' ? [
                'id' => $o->invoice->id, 'number' => $o->invoice->number, 'status' => $o->invoice->status, 'pdf_url' => $o->invoice->pdf?->accessUrl(),
            ] : null,
            'payments' => $staff ? $o->payments->map(fn (Payment $p) => [
                'id' => $p->id, 'method' => $p->method, 'status' => $p->status, 'amount' => Money::of((string) $p->amount),
                'reference' => $p->reference_text, 'by' => $p->recorder?->full_name, 'at' => $p->received_at?->toIso8601ZuluString(),
            ])->values() : [],
        ];
    }

    public static function returnRow(ReturnRequest $r, bool $staff = false): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'reason' => $r->reason,
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toIso8601ZuluString(),
            'items' => $r->items->map(fn (ReturnRequestItem $i) => [
                'order_item_id' => $i->order_item_id, 'name' => $i->orderItem ? self::name($i->orderItem) : '',
                'part_number' => $i->orderItem?->part_number_snapshot, 'quantity' => (string) $i->quantity, 'restocked' => $i->restocked,
            ])->values(),
            'photos' => $r->relationLoaded('attachments') ? $r->attachments->map(fn (Attachment $a) => $a->accessUrl())->filter()->values() : [],
            'order' => $staff && $r->relationLoaded('order') ? ['id' => $r->order->id, 'number' => $r->order->number] : null,
        ];
    }

    private static function name(SalesOrderItem $i): string
    {
        return app()->getLocale() === 'en' ? ($i->name_en_snapshot ?: $i->name_ar_snapshot) : $i->name_ar_snapshot;
    }
}
