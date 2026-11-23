<?php

namespace App\Modules\Quoting\Http;

use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\QuotationItem;
use App\Modules\Quoting\Models\Rfq;
use App\Modules\Quoting\Models\RfqItem;
use App\Support\Money;

/** شكل الطلب والعرض في الـ API للعميل والموظف. الموظف يرى المسودات وبيانات الاتصال، والعميل لا. */
final class RfqPresenter
{
    public const WITH = ['items.listing.media', 'items.part.brand', 'attachments', 'customer', 'organization', 'assignee', 'branch', 'customerEquipment.equipmentModel.brand'];

    public static function row(Rfq $r, bool $staff = false): array
    {
        $current = $r->relationLoaded('quotations')
            ? $r->quotations->first(fn (Quotation $q) => $staff || $q->status !== 'draft')
            : null;

        return [
            'id' => $r->id,
            'number' => $r->number,
            'type' => $r->type,
            'status' => $r->status,
            'source' => $r->source,
            'items_count' => $r->relationLoaded('items') ? $r->items->count() : null,
            'summary' => $r->relationLoaded('items') ? $r->items->take(3)->map(fn (RfqItem $i) => self::itemTitle($i))->values() : [],
            'quotation' => $current ? [
                'id' => $current->id, 'number' => $current->number, 'status' => $current->status, 'version' => $current->version,
                'total' => Money::of($current->total), 'valid_until' => $current->valid_until?->toDateString(),
            ] : null,
            'customer' => $staff ? ['id' => $r->customer?->id, 'name' => $r->customer?->full_name, 'phone' => $r->customer?->phone_e164, 'organization' => $r->organization?->name] : null,
            'assigned_to' => $staff && $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->full_name] : null,
            /** @var array{id: string, name: string}|null */
            'account' => $r->relationLoaded('organization') && $r->organization ? ['id' => $r->organization->id, 'name' => $r->organization->name] : null,
            'lost_reason' => $r->lost_reason,
            'created_at' => $r->created_at?->toIso8601ZuluString(),
            'closed_at' => $r->closed_at?->toIso8601ZuluString(),
        ];
    }

    public static function detail(Rfq $r, bool $staff = false): array
    {
        $versions = $r->quotations->filter(fn (Quotation $q) => $staff || $q->status !== 'draft')->values();

        return [
            ...self::row($r, $staff),
            'notes' => $r->notes,
            'cancel_reason' => $r->cancel_reason,
            'delivery_country' => $r->delivery_country,
            'delivery_address_text' => $r->delivery_address_text,
            /** @var array{id: string, number: string, status: string}|null طلب القطع الناتج عن قبول عرض قطع (3.14) */
            'sales_order' => $r->type === 'parts' && $r->status === 'won'
                ? SalesOrder::query()->where('rfq_id', $r->id)->first(['id', 'number', 'status'])?->only(['id', 'number', 'status']) : null,
            /** @var array{id: string, name: string, serial_number: string|null}|null */
            'equipment' => $r->customerEquipment ? [
                'id' => $r->customerEquipment->id, 'name' => $r->customerEquipment->displayName(), 'serial_number' => $r->customerEquipment->serial_number,
            ] : null,
            'branch' => $r->branch ? ['code' => $r->branch->code, 'name' => $r->branch->localized('name'), 'whatsapp_sales' => $r->branch->whatsapp_sales, 'whatsapp_parts' => $r->branch->whatsapp_parts] : null,
            'items' => $r->items->map(fn (RfqItem $i) => [
                'id' => $i->id, 'line_no' => $i->line_no, 'title' => self::itemTitle($i), 'quantity' => $i->quantity, 'specs' => $i->specs,
                'listing' => $i->listing ? ['id' => $i->listing->id, 'slug' => $i->listing->slug, 'status' => $i->listing->status, 'cover_url' => $i->listing->media->first()?->url()] : null,
                'part' => $i->part ? ['id' => $i->part->id, 'part_number' => $i->part->part_number, 'brand' => $i->part->brand->name, 'brand_slug' => $i->part->brand->slug] : null,
                'free_text' => $i->free_text,
                'attachments' => $r->attachments->filter(fn ($a) => $a->pivot->rfq_item_id === $i->id)->map(fn (Attachment $a) => $a->summary())->values(),
            ])->values(),
            'attachments' => $r->attachments->filter(fn ($a) => ! $a->pivot->rfq_item_id)->map(fn (Attachment $a) => $a->summary())->values(),
            'quotations' => $versions->map(fn (Quotation $q) => self::quotation($q))->values(),
        ];
    }

    public static function quotation(Quotation $q): array
    {
        return [
            'id' => $q->id,
            'number' => $q->number,
            'version' => $q->version,
            'status' => $q->status,
            'valid_until' => $q->valid_until?->toDateString(),
            'acceptable' => $q->isAcceptable(),
            'subtotal' => Money::of($q->subtotal), 'discount_total' => Money::of($q->discount_total),
            'vat_total' => Money::of($q->vat_total), 'total' => Money::of($q->total),
            'estimated_duration' => $q->estimated_duration,
            'delivery_terms' => $q->delivery_terms, 'warranty_terms' => $q->warranty_terms, 'payment_terms' => $q->payment_terms,
            'sent_at' => $q->sent_at?->toIso8601ZuluString(),
            'responded_at' => $q->responded_at?->toIso8601ZuluString(),
            'response_note' => $q->response_note,
            'lpo_number' => $q->lpo_number,
            'approval_requested_at' => $q->approval_requested_at?->toIso8601ZuluString(),
            // رابط موقّع 15 دقيقة لمن يرى الطلب، فيفتحه التطبيق دون رمز الوصول (M-18)
            'pdf_url' => $q->relationLoaded('pdf') ? $q->pdf?->accessUrl() : null,
            'items' => $q->relationLoaded('items') ? $q->items->map(fn (QuotationItem $i) => [
                ...$i->only(['id', 'line_no', 'item_type', 'listing_id', 'part_id', 'description_ar', 'description_en', 'quantity', 'unit', 'warranty_months']),
                'unit_price' => $i->unit_price, 'discount_amount' => $i->discount_amount, 'vat_rate' => $i->vat_rate,
                'line_subtotal' => $i->line_subtotal, 'line_vat' => $i->line_vat, 'line_total' => $i->line_total,
            ])->values() : [],
        ];
    }

    /** عنوان البند باللغة الحالية: المعدة، أو رقم القطعة واسمها، أو النص الحر */
    public static function itemTitle(RfqItem $i): string
    {
        if ($i->listing) {
            return $i->listing->localized('title');
        }
        if ($i->part) {
            return "{$i->part->part_number} · {$i->part->localized('name')}";
        }

        return (string) $i->free_text;
    }
}
