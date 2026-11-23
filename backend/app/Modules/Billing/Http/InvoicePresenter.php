<?php

namespace App\Modules\Billing\Http;

use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Refund;
use App\Support\Money;

/** شكل الفاتورة في الـ API (C-18، M-23، A-13) */
final class InvoicePresenter
{
    public const WITH = ['pdf', 'creditNotes.pdf', 'salesOrder', 'quotation', 'organization', 'customer'];

    public static function row(Invoice $i, bool $staff = false): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'overdue' => $i->isOverdue(),
            /** @var array{type: string, id: string|null, number: string|null} */
            'source' => match (true) {
                (bool) $i->sales_order_id => ['type' => 'sales_order', 'id' => $i->sales_order_id, 'number' => $i->salesOrder?->number],
                (bool) $i->quotation_id => ['type' => 'quotation', 'id' => $i->quotation?->rfq_id, 'number' => $i->quotation?->number],
                default => ['type' => 'service_request', 'id' => $i->service_request_id, 'number' => null],
            },
            'issue_date' => $i->issue_date?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'total' => Money::of((string) $i->total),
            'paid' => Money::of((string) $i->amount_paid),
            'balance' => Money::of($i->balance()),
            'pdf_url' => $i->relationLoaded('pdf') ? $i->pdf?->accessUrl() : null,
            /** @var array{id: string, name: string}|null */
            'account' => $i->relationLoaded('organization') && $i->organization ? ['id' => $i->organization->id, 'name' => $i->organization->name] : null,
            'customer' => $staff ? ['id' => $i->customer?->id, 'name' => $i->buyer_snapshot['name'] ?? $i->customer?->full_name, 'phone' => $i->customer?->phone_e164] : null,
            'credit_notes' => $i->relationLoaded('creditNotes') ? $i->creditNotes->map(fn (CreditNote $c) => self::creditNote($c))->values() : [],
        ];
    }

    public static function detail(Invoice $i): array
    {
        return self::row($i, true) + [
            'seller' => $i->seller_snapshot,
            'buyer' => $i->buyer_snapshot,
            'subtotal' => Money::of((string) $i->subtotal),
            'discount_total' => Money::of((string) $i->discount_total),
            'vat_total' => Money::of((string) $i->vat_total),
            'void_reason' => $i->void_reason,
            'items' => $i->items->map(fn (InvoiceItem $l) => [
                'line_no' => $l->line_no, 'item_type' => $l->item_type,
                'description' => app()->getLocale() === 'en' ? $l->description_en : $l->description_ar,
                'quantity' => (string) $l->quantity, 'unit' => $l->unit, 'unit_price' => Money::of((string) $l->unit_price),
                'discount' => Money::of((string) $l->discount_amount), 'vat_rate' => (string) $l->vat_rate,
                'line_vat' => Money::of((string) $l->line_vat), 'line_total' => Money::of((string) $l->line_total),
                'warranty_until' => $l->warranty_until?->toDateString(),
            ])->values(),
            'payments' => $i->payments->map(fn (Payment $p) => self::payment($p))->values(),
        ];
    }

    public static function creditNote(CreditNote $c): array
    {
        return [
            'id' => $c->id, 'number' => $c->number, 'reason' => $c->reason, 'total' => Money::of((string) $c->total),
            'issued_at' => $c->issued_at?->toIso8601ZuluString(), 'pdf_url' => $c->relationLoaded('pdf') ? $c->pdf?->accessUrl() : null,
            'invoice' => $c->relationLoaded('invoice') ? ['id' => $c->invoice->id, 'number' => $c->invoice->number] : null,
        ];
    }

    public static function payment(Payment $p): array
    {
        return [
            'id' => $p->id, 'method' => $p->method, 'status' => $p->status,
            'amount' => Money::of((string) $p->amount), 'refunded' => Money::of((string) $p->refunded_amount),
            'reference' => $p->reference_text, 'received_at' => $p->received_at?->toIso8601ZuluString(),
            'by' => $p->relationLoaded('recorder') ? $p->recorder?->full_name : null,
            'branch' => $p->relationLoaded('branch') && $p->branch ? $p->branch->localized('name') : null,
            'invoice' => $p->relationLoaded('invoice') && $p->invoice ? ['id' => $p->invoice->id, 'number' => $p->invoice->number] : null,
            'order' => $p->relationLoaded('salesOrder') && $p->salesOrder ? ['id' => $p->salesOrder->id, 'number' => $p->salesOrder->number] : null,
            'refunds' => $p->relationLoaded('refunds') ? $p->refunds->map(fn (Refund $r) => [
                'id' => $r->id, 'amount' => Money::of((string) $r->amount), 'method' => $r->method, 'reason' => $r->reason,
                'reference' => $r->reference_text, 'at' => $r->created_at?->toIso8601ZuluString(),
            ])->values() : [],
        ];
    }
}
