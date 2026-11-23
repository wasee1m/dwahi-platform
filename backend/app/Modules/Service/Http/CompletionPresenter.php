<?php

namespace App\Modules\Service\Http;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Modules\Service\Services\CompletionService;
use App\Modules\Service\Services\HandoverService;
use App\Modules\Service\Services\ReviewService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** الإنجاز والتسليم في الـ API (S9): التقرير والاختبارات والعمل الإضافي لكل أمر، والاستلام والتقييم لكل طلب */
final class CompletionPresenter
{
    private static function iso(?string $ts): ?string
    {
        return $ts ? str_replace(' ', 'T', substr($ts, 0, 19)).'Z' : null;
    }

    private static function url(?string $attachmentId): ?string
    {
        return $attachmentId ? Attachment::query()->find($attachmentId)?->accessUrl() : null;
    }

    /**
     * @return array{findings: string, actions_taken: string, recommendations: string|null, hour_meter_reading: int|null, no_parts_used: bool, submitted_at: string|null, pdf_url: string|null}|null
     */
    public static function report(string $workOrderId): ?array
    {
        $r = DB::table('service_reports')->where('work_order_id', $workOrderId)->first();

        return $r ? [
            'findings' => (string) $r->findings, 'actions_taken' => (string) $r->actions_taken, 'recommendations' => $r->recommendations,
            'hour_meter_reading' => $r->hour_meter_reading === null ? null : (int) $r->hour_meter_reading, 'no_parts_used' => (bool) $r->no_parts_used,
            'submitted_at' => self::iso($r->submitted_at), 'pdf_url' => self::url($r->pdf_id),
        ] : null;
    }

    /**
     * @return list<array{id: string, template: string|null, result: string, values: array<string, mixed>, fields: list<array<string, mixed>>, notes: string|null, tested_at: string|null, media_url: string|null}>
     */
    public static function tests(string $workOrderId): array
    {
        $en = app()->getLocale() === 'en';

        return DB::table('work_order_tests as t')->leftJoin('test_templates as tt', 'tt.id', '=', 't.template_id')->where('t.work_order_id', $workOrderId)
            ->orderBy('t.tested_at')->get(['t.id', 't.values', 't.result', 't.notes', 't.tested_at', 't.media_id', 'tt.name_ar', 'tt.name_en', 'tt.fields'])
            ->map(fn ($t) => [
                'id' => (string) $t->id, 'template' => $en ? $t->name_en : $t->name_ar, 'result' => (string) $t->result,
                'values' => json_decode((string) $t->values, true) ?: [], 'fields' => json_decode((string) $t->fields, true) ?: [],
                'notes' => $t->notes, 'tested_at' => self::iso($t->tested_at), 'media_url' => self::url($t->media_id),
            ])->values()->all();
    }

    /**
     * @return list<array{id: string, description: string, parts_needed: string|null, estimated_amount: array{amount: string, currency: string}|null, status: string, blocks_work: bool, rejection_reason: string|null, requested_at: string|null, by: string|null, rfq_id: string|null, photos: list<string|null>, quotation: array{id: string, number: string|null, status: string, total: array{amount: string, currency: string}}|null}>
     */
    public static function additional(string $workOrderId, bool $customer = false): array
    {
        $rows = DB::table('additional_work_requests as a')->leftJoin('users', 'users.id', '=', 'a.requested_by')->where('a.work_order_id', $workOrderId)
            ->orderBy('a.requested_at')->get(['a.*', 'users.full_name']);
        $quotes = Quotation::query()->whereIn('id', $rows->pluck('quotation_id')->filter())->get()->keyBy('id');

        return $rows->map(function ($a) use ($quotes, $customer) {
            $q = $a->quotation_id ? $quotes[$a->quotation_id] ?? null : null;

            return [
                'id' => (string) $a->id, 'description' => (string) $a->description, 'parts_needed' => $a->parts_needed,
                'estimated_amount' => ! $customer && $a->estimated_amount !== null ? Money::of((string) $a->estimated_amount) : null,
                'status' => (string) $a->status, 'blocks_work' => (bool) $a->blocks_work, 'rejection_reason' => $a->rejection_reason,
                'requested_at' => self::iso($a->requested_at), 'by' => $customer ? null : $a->full_name, 'rfq_id' => $a->rfq_id,
                'photos' => DB::table('additional_work_request_attachments')->where('additional_work_request_id', $a->id)->pluck('attachment_id')
                    ->map(fn ($id) => self::url($id))->values()->all(),
                'quotation' => $q ? ['id' => $q->id, 'number' => $q->number, 'status' => $q->status, 'total' => Money::of((string) $q->total)] : null,
            ];
        })->values()->all();
    }

    /** قالب الاختبار لأمر العمل (T-09) */
    public static function template(ServiceRequest $sr): ?array
    {
        $t = CompletionService::templateFor($sr);
        $en = app()->getLocale() === 'en';

        return $t ? ['id' => $t->id, 'name' => $en ? $t->name_en : $t->name_ar, 'fields' => $t->fields] : null;
    }

    /** للموظف والفني: التقرير والاختبارات والعمل الإضافي وقائمة BR-07 */
    public static function workOrder(WorkOrder $w): array
    {
        $open = in_array($w->status, ['in_progress', 'on_hold', 'work_completed'], true);

        return [
            'report' => self::report($w->id),
            'tests' => self::tests($w->id),
            'additional' => self::additional($w->id),
            /** @var array<string, bool>|null */
            'checklist' => $open ? CompletionService::checklist($w) : null,
            'no_charge_reason' => $w->no_charge_reason,
        ];
    }

    /**
     * للعميل (C-17، M-17): تقارير الأوامر المعتمدة، والعمل الإضافي، والاستلام والمبلغ المستحق، وإيصال التسليم، والفاتورة، والتقييم.
     */
    public static function customer(ServiceRequest $sr): array
    {
        $done = $sr->workOrders->filter(fn (WorkOrder $w) => in_array($w->status, ['verified', 'closed'], true));
        $photos = fn (string $woId, string $phase) => DB::table('work_order_photos')->where('work_order_id', $woId)->where('phase', $phase)->orderBy('taken_at')
            ->pluck('attachment_id')->map(fn ($id) => self::url($id))->filter()->values()->all();
        $invoice = Invoice::query()->where('service_request_id', $sr->id)->where('status', '!=', 'void')->first();
        $handover = HandoverService::handoverOf($sr->id);
        $review = DB::table('reviews')->where('service_request_id', $sr->id)->first();
        $due = app(HandoverService::class)->due($sr);

        return [
            'reports' => $done->map(fn (WorkOrder $w) => [
                'work_order' => $w->number, 'type' => $w->type, 'no_charge' => (bool) $w->no_charge,
                'report' => self::report($w->id), 'tests' => array_map(fn ($t) => collect($t)->except('media_url')->all(), self::tests($w->id)),
                'before' => $photos($w->id, 'before'), 'after' => $photos($w->id, 'after'),
            ])->values()->all(),
            'additional' => $sr->workOrders->flatMap(fn (WorkOrder $w) => self::additional($w->id, true))
                ->reject(fn ($a) => $a['status'] === 'pending')->values()->all(),
            /** @var array{due: array{amount: string, currency: string}, invoice_id: string|null, invoice_number: string|null, ready_since: string|null}|null */
            'pickup' => ! $handover && $sr->intake && in_array($sr->status, ['completed', 'cancelled', 'declined'], true) ? [
                'due' => Money::of($due['due']), 'invoice_id' => $invoice?->id, 'invoice_number' => $invoice?->number,
                'ready_since' => $sr->completed_at?->toIso8601ZuluString(),
            ] : null,
            /** @var array{handed_over_at: string|null, receiver_name: string, receipt_url: string|null}|null */
            'handover' => $handover ? ['handed_over_at' => self::iso($handover->handed_over_at), 'receiver_name' => (string) $handover->receiver_name,
                'receipt_url' => self::url($handover->receipt_pdf_id)] : null,
            /** @var array{id: string, number: string|null, status: string, total: array{amount: string, currency: string}}|null */
            'invoice' => $invoice && $invoice->status !== 'draft' ? ['id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status, 'total' => Money::of((string) $invoice->total)] : null,
            /** @var array{rating: int, comment: string|null}|null */
            'review' => $review ? ['rating' => (int) $review->rating, 'comment' => $review->comment] : null,
            'can_review' => ReviewService::open($sr),
            'review_until' => $sr->closed_at?->copy()->addDays(ReviewService::windowDays())->toIso8601ZuluString(),
        ];
    }
}
