<?php

namespace App\Modules\Service\Http;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\Rfq;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Modules\Service\Models\WorkOrderEvent;
use App\Modules\Service\Services\ExecutionService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** شكل طلب الصيانة وأمر العمل في الـ API. للعميل تسمية الحالة كما في 02 SM-4، وللموظف التفاصيل الداخلية. */
final class ServicePresenter
{
    public const WITH = ['equipment.equipmentModel.brand', 'branch', 'organization', 'customer', 'intake.receipt', 'workOrders.technicians', 'attachments', 'estimates.quotations'];

    /**
     * ما يراه العميل (02 SM-4 "تسميات الحالة"): in_service يُفصَّل من أمر العمل النشط.
     *
     * @return array{key: string, date: string|null, reason: string|null}
     */
    public static function customerStatus(ServiceRequest $sr): array
    {
        $date = null;
        $reason = null;
        $key = $sr->status;
        if ($sr->status === 'in_service') {
            $active = $sr->workOrders->first(fn (WorkOrder $w) => ! in_array($w->status, ['cancelled', 'closed', 'verified'], true));
            $extraQuoted = $sr->estimates->contains(fn (Rfq $r) => $r->is_additional && $r->status === 'quoted');
            $key = match (true) {
                ! $sr->intake => 'awaiting_dropoff',
                $extraQuoted => 'additional_awaiting',
                ! $active || $active->status === 'unassigned' => 'scheduling',
                $active->status === 'scheduled' => 'scheduled',
                $active->status === 'in_progress' => 'in_progress',
                $active->status === 'on_hold' => 'on_hold',
                $active->status === 'work_completed' => 'final_review',
                default => 'in_progress',
            };
            $date = $key === 'scheduled' ? $active?->scheduled_start?->toIso8601ZuluString() : null;
            $reason = $key === 'on_hold' ? $active?->on_hold_reason : null;
        }
        if ($sr->status === 'declined') {
            $reason = $sr->decline_reason;
        }

        return ['key' => $key, 'date' => $date, 'reason' => $reason];
    }

    public static function row(ServiceRequest $sr, bool $staff = false): array
    {
        return [
            'id' => $sr->id,
            'number' => $sr->number,
            'status' => $sr->status,
            'customer_status' => self::customerStatus($sr),
            'urgency' => $sr->urgency,
            'source' => $sr->source,
            /** @var array{id: string, name: string, serial_number: string|null}|null */
            'equipment' => $sr->equipment ? ['id' => $sr->equipment->id, 'name' => $sr->equipment->displayName(), 'serial_number' => $sr->equipment->serial_number] : null,
            'component_type' => $sr->component_type,
            'component_details' => $sr->component_details,
            'preferred_date' => $sr->preferred_date?->toDateString(),
            'received' => $sr->intake !== null,
            /** @var array{id: string, name: string}|null */
            'account' => $sr->organization ? ['id' => $sr->organization->id, 'name' => $sr->organization->name] : null,
            'customer' => $staff ? ['id' => $sr->customer?->id, 'name' => $sr->customer?->full_name, 'phone' => $sr->customer?->phone_e164, 'organization' => $sr->organization?->name] : null,
            'branch' => $sr->branch ? ['id' => $sr->branch->id, 'name' => $sr->branch->localized('name')] : null,
            'sla_due_at' => $staff ? $sr->sla_due_at?->toIso8601ZuluString() : null,
            'created_at' => $sr->created_at?->toIso8601ZuluString(),
        ];
    }

    public static function detail(ServiceRequest $sr, bool $staff = false): array
    {
        $estimate = $sr->estimates->first(fn (Rfq $r) => ! $r->is_additional);
        $quote = $estimate?->quotations->first(fn (Quotation $q) => $staff || $q->status !== 'draft');
        $branch = $sr->branch;

        return self::row($sr, $staff) + [
            'problem_description' => $sr->problem_description,
            'inspection_fee' => $sr->inspection_fee === null ? null : Money::of((string) $sr->inspection_fee),
            'route' => $staff ? $sr->route : null,
            'triaged_at' => $sr->triaged_at?->toIso8601ZuluString(),
            'decline_reason' => $sr->decline_reason,
            'cancel_reason' => $sr->cancel_reason,
            'branch_detail' => $branch ? [
                'name' => $branch->localized('name'), 'address' => $branch->localized('address'), 'phone' => $branch->phone,
                'whatsapp' => $branch->whatsapp_service, 'working_hours' => $branch->working_hours,
            ] : null,
            'attachments' => $sr->attachments->map(fn (Attachment $a) => [
                'id' => $a->id, 'kind' => $a->pivot->kind, 'mime_type' => $a->mime_type, 'url' => $a->accessUrl(),
            ])->values(),
            /** @var array{number: string, received_at: string|null, receipt_url: string|null}|null */
            'intake' => $sr->intake ? [
                'number' => $sr->intake->number, 'received_at' => $sr->intake->received_at?->toIso8601ZuluString(),
                'receipt_url' => $sr->intake->receipt?->accessUrl(),
            ] : null,
            /** @var array{rfq_id: string, rfq_status: string, quotation: array{id: string, number: string|null, status: string, total: array{amount: string, currency: string}|null}|null}|null */
            'estimate' => $estimate ? [
                'rfq_id' => $estimate->id, 'rfq_status' => $estimate->status,
                'quotation' => $quote ? ['id' => $quote->id, 'number' => $quote->number, 'status' => $quote->status, 'total' => Money::of((string) $quote->total)] : null,
            ] : null,
            'work_orders' => $sr->workOrders->map(fn (WorkOrder $w) => self::workOrderRow($w, $staff))->values(),
            'can_cancel' => ! $staff && in_array($sr->status, ['submitted', 'triaged', 'awaiting_approval', 'approved', 'in_service'], true)
                && ! $sr->workOrders->contains(fn (WorkOrder $w) => in_array($w->status, ['in_progress', 'work_completed'], true)),
        ];
    }

    public static function workOrderRow(WorkOrder $w, bool $staff = true): array
    {
        return [
            'id' => $w->id,
            'number' => $w->number,
            'type' => $w->type,
            'status' => $w->status,
            'scheduled_start' => $w->scheduled_start?->toIso8601ZuluString(),
            'scheduled_end' => $w->scheduled_end?->toIso8601ZuluString(),
            'on_hold_reason' => $w->on_hold_reason,
            'technicians' => $staff && $w->relationLoaded('technicians')
                ? $w->technicians->map(fn (User $u) => ['id' => $u->id, 'name' => $u->full_name, 'role' => $u->pivot->role])->values() : [],
            'instructions' => $staff ? $w->instructions : null,
            'cancel_reason' => $w->cancel_reason,
        ];
    }

    public static function workOrderDetail(WorkOrder $w): array
    {
        $sr = $w->serviceRequest;

        return self::workOrderRow($w) + [
            'service_request' => self::row($sr, true) + ['problem_description' => $sr->problem_description],
            'intake' => $sr->intake ? ['number' => $sr->intake->number, 'received_at' => $sr->intake->received_at?->toIso8601ZuluString()] : null,
            'quotation' => $w->quotation ? ['id' => $w->quotation->id, 'number' => $w->quotation->number, 'total' => Money::of((string) $w->quotation->total)] : null,
            'events' => $w->events->map(fn (WorkOrderEvent $e) => [
                'from' => $e->from_status, 'to' => $e->to_status, 'reason' => $e->reason, 'by' => $e->actor?->full_name,
                'at' => $e->occurred_at?->toIso8601ZuluString(),
            ])->values(),
            'execution' => self::execution($w),
        ];
    }

    /**
     * ما سجله الفريق من التطبيق (FR-WO-03، FR-WO-04، FR-TEC-04): الوقت، والصور بمراحلها، والقطع بأسعارها المحفوظة، والملاحظات، والعداد.
     *
     * @return array{worked_seconds: int, on_hold_reason: string|null, photos: list<array{id: string, phase: string, caption: string|null, url: string|null, by: string|null, from_previous_technician: bool, at: string}>, parts: list<array{id: string, part_number: string|null, name: string|null, quantity: string, source: string, unit_price: Money|null, removed: bool, by: string|null}>, notes: list<array{id: string, body: string|null, by: string|null, from_previous_technician: bool, at: string}>, readings: list<array{reading: int, needs_review: bool, at: string|null}>}
     */
    public static function execution(WorkOrder $w): array
    {
        $iso = fn (?string $ts) => $ts ? str_replace(' ', 'T', substr($ts, 0, 19)).'Z' : null;
        $photos = DB::table('work_order_photos as p')->leftJoin('users', 'users.id', '=', 'p.taken_by')->where('p.work_order_id', $w->id)
            ->orderBy('p.taken_at')->get(['p.attachment_id', 'p.phase', 'p.caption', 'p.taken_at', 'p.from_previous_technician', 'users.full_name']);
        $files = Attachment::query()->whereIn('id', $photos->pluck('attachment_id'))->get()->keyBy('id');
        $en = app()->getLocale() === 'en';

        return [
            'worked_seconds' => ExecutionService::workedSeconds($w->id),
            'on_hold_reason' => $w->on_hold_reason,
            'photos' => $photos->map(fn ($p) => [
                'id' => (string) $p->attachment_id, 'phase' => (string) $p->phase, 'caption' => $p->caption, 'url' => $files[$p->attachment_id]?->accessUrl(),
                'by' => $p->full_name, 'from_previous_technician' => (bool) $p->from_previous_technician, 'at' => (string) $iso($p->taken_at),
            ])->values()->all(),
            'parts' => DB::table('work_order_parts as l')->leftJoin('parts', 'parts.id', '=', 'l.part_id')->leftJoin('users', 'users.id', '=', 'l.recorded_by')
                ->where('l.work_order_id', $w->id)->orderBy('l.recorded_at')
                ->get(['l.id', 'l.description', 'l.quantity', 'l.source', 'l.unit_price', 'l.removed_at', 'parts.part_number', 'parts.name_ar', 'parts.name_en', 'users.full_name'])
                ->map(fn ($l) => [
                    'id' => (string) $l->id, 'part_number' => $l->part_number,
                    'name' => $l->part_number ? ($en ? ($l->name_en ?: $l->name_ar) : $l->name_ar) : $l->description,
                    'quantity' => (string) $l->quantity, 'source' => (string) $l->source,
                    'unit_price' => $l->unit_price !== null ? Money::of((string) $l->unit_price) : null,
                    'removed' => $l->removed_at !== null, 'by' => $l->full_name,
                ])->values()->all(),
            'notes' => DB::table('work_order_notes as n')->leftJoin('users', 'users.id', '=', 'n.author_id')->where('n.work_order_id', $w->id)->orderBy('n.created_at')
                ->get(['n.id', 'n.body', 'n.from_previous_technician', 'n.created_at', 'users.full_name'])
                ->map(fn ($n) => ['id' => (string) $n->id, 'body' => $n->body, 'by' => $n->full_name, 'from_previous_technician' => (bool) $n->from_previous_technician,
                    'at' => (string) $iso($n->created_at)])->values()->all(),
            'readings' => DB::table('hour_meter_readings')->where('work_order_id', $w->id)->orderBy('recorded_at')->get(['reading', 'needs_review', 'recorded_at'])
                ->map(fn ($r) => ['reading' => (int) $r->reading, 'needs_review' => (bool) $r->needs_review, 'at' => $iso($r->recorded_at)])->values()->all(),
        ];
    }
}
