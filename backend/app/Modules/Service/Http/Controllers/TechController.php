<?php

namespace App\Modules\Service\Http\Controllers;

use App\Models\User;
use App\Modules\Parts\Support\PartNumber;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Quoting\Models\QuotationItem;
use App\Modules\Service\Http\CompletionPresenter;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Modules\Service\Models\WorkOrderEvent;
use App\Modules\Service\Models\WorkshopIntake;
use App\Modules\Service\Services\ExecutionService;
use App\Modules\Service\Services\SyncService;
use App\Support\ApiProblem;
use App\Support\Search;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * تطبيق الفني (T-02، T-03، T-07، T-11، FR-TEC-01، FR-TEC-02، FR-TEC-07): المهام المسندة إليّ فقط، والتنفيذ
 * عبر صندوق الأحداث الصادر (04 §6). الرد يحمل server_time ليقارن التطبيق ساعته.
 */
class TechController
{
    private const WITH = ['serviceRequest.customer', 'serviceRequest.organization', 'serviceRequest.equipment.equipmentModel.brand', 'serviceRequest.intake', 'technicians'];

    public function __construct(private readonly SyncService $sync) {}

    /** T-02: اليوم (والمتأخر والجاري والمتوقف) والقادمة، بالموعد ثم الأولوية */
    public function tasks(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $mine = DB::table('work_order_assignments')->where('technician_user_id', $user->id)->whereNull('unassigned_at')->select('work_order_id');
        $rows = WorkOrder::query()->whereIn('id', $mine)->whereIn('status', ['scheduled', 'in_progress', 'on_hold'])
            ->with(self::WITH)->orderBy('scheduled_start')->get();
        $endOfToday = now('Asia/Muscat')->endOfDay()->utc();
        $priority = fn (WorkOrder $w) => $w->serviceRequest->urgency === 'urgent' ? 0 : 1;
        $today = $rows->filter(fn (WorkOrder $w) => $w->status !== 'scheduled' || ! $w->scheduled_start || $w->scheduled_start->lte($endOfToday))
            ->sortBy(fn (WorkOrder $w) => [$w->status === 'in_progress' ? 0 : 1, $w->scheduled_start?->timestamp ?? 0, $priority($w)]);
        $upcoming = $rows->diff($today);

        return response()->json(['data' => [
            'today' => $today->map(fn (WorkOrder $w) => $this->row($w, $user))->values(),
            'upcoming' => $upcoming->map(fn (WorkOrder $w) => $this->row($w, $user))->values(),
            'server_time' => now()->toIso8601ZuluString(),
        ]]);
    }

    /** T-03: كل ما يحتاجه الفني قبل العمل وأثناءه */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $w = $this->mine($user, $id);
        $w->load([...self::WITH, 'serviceRequest.attachments', 'serviceRequest.intake.photos', 'quotation.items', 'events.actor']);
        $sr = $w->serviceRequest;
        $history = $sr->customer_equipment_id
            ? ServiceRequest::query()->where('customer_equipment_id', $sr->customer_equipment_id)->where('id', '!=', $sr->id)
                ->orderByDesc('created_at')->limit(5)->get(['id', 'number', 'status', 'problem_description', 'created_at'])
                ->map(fn ($h) => ['number' => $h->number, 'status' => $h->status, 'problem' => Str::limit((string) $h->problem_description, 120), 'at' => $h->created_at?->toIso8601ZuluString()])
            : collect();
        $photos = DB::table('work_order_photos')->where('work_order_id', $w->id)->orderBy('taken_at')->get();
        $files = Attachment::query()->whereIn('id', $photos->pluck('attachment_id'))->get()->keyBy('id');
        $parts = DB::table('work_order_parts as p')->leftJoin('parts', 'parts.id', '=', 'p.part_id')->where('p.work_order_id', $w->id)
            ->orderBy('p.recorded_at')->get(['p.id', 'p.part_id', 'p.description', 'p.quantity', 'p.source', 'p.removed_at', 'parts.part_number', 'parts.name_ar', 'parts.name_en']);
        $intake = $sr->intake;
        $notes = DB::table('work_order_notes as n')->join('users', 'users.id', '=', 'n.author_id')->where('n.work_order_id', $w->id)
            ->orderBy('n.created_at')->get(['n.id', 'n.body', 'n.from_previous_technician', 'n.created_at', 'users.full_name']);

        return response()->json(['data' => $this->row($w, $user) + [
            'problem_description' => $sr->problem_description,
            'component_details' => $sr->component_details,
            'instructions' => $w->instructions,
            'attachments' => $sr->attachments->map(fn (Attachment $a) => ['id' => $a->id, 'kind' => $a->pivot->kind, 'url' => $a->accessUrl()])->values(),
            /** @var array{number: string, received_at: string|null, hour_meter_reading: int|null, accessories: string|null, condition_notes: string|null, photos: list<string|null>}|null */
            'intake' => $intake ? [
                'number' => $intake->number, 'received_at' => $intake->received_at?->toIso8601ZuluString(), 'hour_meter_reading' => $intake->hour_meter_reading,
                'accessories' => $intake->accessories, 'condition_notes' => $intake->condition_notes,
                'photos' => $intake->photos->map(fn (Attachment $a) => $a->accessUrl())->values()->all(),
            ] : null,
            /** @var array{serial_number: string|null, current_hour_meter: int|null}|null */
            'equipment' => $sr->equipment ? ['serial_number' => $sr->equipment->serial_number, 'current_hour_meter' => $sr->equipment->current_hour_meter] : null,
            'history' => $history->values(),
            'scope' => $w->quotation ? $w->quotation->items->map(fn (QuotationItem $i) => [
                'description' => app()->getLocale() === 'en' ? $i->description_en : $i->description_ar, 'quantity' => (string) $i->quantity, 'unit' => $i->unit,
            ])->values() : [],
            'photos' => $photos->map(fn ($p) => ['id' => $p->attachment_id, 'phase' => $p->phase, 'caption' => $p->caption, 'url' => $files[$p->attachment_id]?->accessUrl(),
                'taken_at' => str_replace(' ', 'T', substr($p->taken_at, 0, 19)).'Z'])->values(),
            'parts' => $parts->map(fn ($p) => [
                'id' => $p->id, 'part_id' => $p->part_id, 'part_number' => $p->part_number,
                'name' => $p->part_id ? (app()->getLocale() === 'en' ? ($p->name_en ?: $p->name_ar) : $p->name_ar) : $p->description,
                'quantity' => (string) $p->quantity, 'source' => $p->source, 'removed' => $p->removed_at !== null,
            ])->values(),
            'notes' => $notes->map(fn ($n) => ['id' => $n->id, 'body' => $n->body, 'by' => $n->full_name, 'from_previous_technician' => (bool) $n->from_previous_technician,
                'at' => str_replace(' ', 'T', substr($n->created_at, 0, 19)).'Z'])->values(),
            'events' => $w->events->map(fn (WorkOrderEvent $e) => ['to' => $e->to_status, 'reason' => $e->reason, 'by' => $e->actor?->full_name, 'at' => $e->occurred_at?->toIso8601ZuluString()])->values(),
            // T-08 و T-09: القالب والاختبارات والتقرير والعمل الإضافي وقائمة BR-07
            'template' => CompletionPresenter::template($sr),
            'meter_recorded' => DB::table('hour_meter_readings')->where('work_order_id', $w->id)->exists(),
        ] + CompletionPresenter::workOrder($w)]);
    }

    /** T-10: المهام المنجزة، وساعات العمل اليومية والأسبوعية، والمعادة من المنسق */
    public function history(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $tz = 'Asia/Muscat';
        $mine = DB::table('work_order_assignments')->where('technician_user_id', $user->id)->select('work_order_id');
        $done = WorkOrder::query()->whereIn('id', $mine)->whereIn('status', ['work_completed', 'verified', 'closed'])->with(self::WITH)
            ->orderByDesc('updated_at')->limit(50)->get();
        $since = now($tz)->startOfWeek(CarbonInterface::SATURDAY)->subWeeks(3)->utc();
        $entries = DB::table('time_entries')->where('technician_user_id', $user->id)->where('started_at', '>=', ExecutionService::ts($since))
            ->get(['started_at', 'ended_at']);
        $days = [];
        foreach ($entries as $e) {
            $start = Carbon::parse($e->started_at, 'UTC');
            $end = $e->ended_at ? Carbon::parse($e->ended_at, 'UTC') : now();
            $day = $start->copy()->timezone($tz)->toDateString();
            $days[$day] = ($days[$day] ?? 0) + max(0, (int) $start->diffInSeconds($end));
        }
        ksort($days);
        $weeks = [];
        foreach ($days as $day => $s) {
            $week = Carbon::parse($day, $tz)->startOfWeek(CarbonInterface::SATURDAY)->toDateString();
            $weeks[$week] = ($weeks[$week] ?? 0) + $s;
        }
        $returned = DB::table('work_order_status_events as e')->join('work_orders as w', 'w.id', '=', 'e.work_order_id')
            ->whereIn('e.work_order_id', $mine)->where('e.from_status', 'work_completed')->where('e.to_status', 'in_progress')
            ->orderByDesc('e.occurred_at')->limit(20)->get(['w.id', 'w.number', 'e.reason', 'e.occurred_at']);

        return response()->json(['data' => [
            'completed' => $done->map(fn (WorkOrder $w) => $this->row($w, $user) + ['completed_at' => $w->events()->where('to_status', 'work_completed')
                ->orderByDesc('occurred_at')->value('occurred_at')])->values(),
            /** @var list<array{date: string, seconds: int}> */
            'days' => collect($days)->map(fn ($s, $d) => ['date' => (string) $d, 'seconds' => (int) $s])->values()->all(),
            /** @var list<array{week_start: string, seconds: int}> */
            'weeks' => collect($weeks)->map(fn ($s, $d) => ['week_start' => (string) $d, 'seconds' => (int) $s])->values()->all(),
            'returned' => $returned->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'note' => $r->reason,
                'at' => str_replace(' ', 'T', substr((string) $r->occurred_at, 0, 19)).'Z'])->values(),
        ]]);
    }

    /** فتح المهمة بمسح ملصق بطاقة الاستلام (FR-TEC-01) */
    public function byTag(Request $request, string $number): JsonResponse
    {
        $user = $this->user($request);
        $intake = WorkshopIntake::query()->where('number', strtoupper(trim($number)))->first();
        $wo = $intake ? WorkOrder::query()->where('service_request_id', $intake->service_request_id)->whereIn('status', ['scheduled', 'in_progress', 'on_hold'])
            ->whereIn('id', DB::table('work_order_assignments')->where('technician_user_id', $user->id)->whereNull('unassigned_at')->select('work_order_id'))
            ->orderBy('scheduled_start')->first() : null;
        if (! $wo) {
            throw new ApiProblem('NOT_YOUR_TASK', 404);
        }

        return response()->json(['data' => ['id' => $wo->id, 'number' => $wo->number]]);
    }

    /** T-07: قطع مخزن فرع الفني بالرقم أو الاسم، مع المتاح فيه */
    public function parts(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q']);
        $branch = $user->staffProfile?->branch_id;
        $normalized = PartNumber::normalize($q);
        $rows = DB::table('parts')->where('parts.is_active', true)
            ->where(fn ($w) => $w->where('part_number_normalized', 'like', addcslashes($normalized, '%_\\').'%')->orWhere('name_ar', 'like', Search::contains($q))->orWhere('name_en', 'like', Search::contains($q)))
            ->leftJoin('stock_levels', 'stock_levels.part_id', '=', 'parts.id')
            ->leftJoin('stock_locations', fn ($j) => $j->on('stock_locations.id', '=', 'stock_levels.location_id')->where('stock_locations.branch_id', '=', $branch))
            ->groupBy('parts.id', 'parts.part_number', 'parts.name_ar', 'parts.name_en', 'parts.unit')
            ->selectRaw('parts.id, parts.part_number, parts.name_ar, parts.name_en, parts.unit, COALESCE(SUM(CASE WHEN stock_locations.id IS NOT NULL THEN stock_levels.qty_on_hand - stock_levels.qty_reserved END), 0) AS free')
            ->orderBy('parts.part_number')->limit(20)->get();

        return response()->json(['data' => $rows->map(fn ($p) => [
            'id' => $p->id, 'part_number' => $p->part_number, 'name' => app()->getLocale() === 'en' ? ($p->name_en ?: $p->name_ar) : $p->name_ar,
            'unit' => $p->unit, 'available' => number_format((float) $p->free, 2, '.', ''),
        ])->values()]);
    }

    /** 04 §6.2: الأحداث بالترتيب، ولكل حدث نتيجته */
    public function syncEvents(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:200'],
            'events.*.client_event_id' => ['required', 'uuid'],
            'events.*.work_order_id' => ['required', 'uuid'],
            'events.*.type' => ['required', Rule::in(SyncService::TYPES)],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.payload' => ['nullable', 'array'],
        ]);
        $session = $request->attributes->get('auth_session');
        $results = $this->sync->apply($user, $session?->device_id, $data['events']);

        return response()->json(['data' => ['results' => $results, 'server_time' => now()->toIso8601ZuluString()]]);
    }

    /** T-12: الاسم والمهارات */
    public function me(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => [
            'id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'phone' => $user->phone_e164,
            /** @var list<string> */
            'skills' => $user->staffProfile?->skills ?? [], 'branch' => $user->staffProfile?->branch?->localized('name'),
            'preferred_locale' => $user->preferred_locale,
        ]]);
    }

    private function row(WorkOrder $w, User $user): array
    {
        $sr = $w->serviceRequest;
        $role = $w->technicians->firstWhere('id', $user->id)?->pivot->role;

        return [
            'id' => $w->id, 'number' => $w->number, 'type' => $w->type, 'status' => $w->status, 'on_hold_reason' => $w->on_hold_reason,
            'scheduled_start' => $w->scheduled_start?->toIso8601ZuluString(), 'scheduled_end' => $w->scheduled_end?->toIso8601ZuluString(),
            'urgent' => $sr->urgency === 'urgent',
            'customer' => $sr->organization?->name ?? $sr->customer?->full_name,
            'item' => $sr->equipment ? $sr->equipment->displayName() : __('service.components.'.$sr->component_type),
            'is_component' => $sr->customer_equipment_id === null,
            'service_request' => $sr->number,
            'card' => $sr->intake?->number,
            'received' => $sr->intake !== null,
            'my_role' => $role,
            'team' => $w->technicians->map(fn (User $u) => ['name' => $u->full_name, 'role' => $u->pivot->role])->values(),
            'worked_seconds' => ExecutionService::workedSeconds($w->id),
            'scope_total' => null,
        ];
    }

    private function mine(User $user, string $id): WorkOrder
    {
        abort_unless(Str::isUuid($id), 404);
        // المهمة التي أُعيد إسنادها تبقى مقروءة لمن عمل عليها، لتظهر له حالتها الحقيقية (04 §6.4)
        $ever = DB::table('work_order_assignments')->where('technician_user_id', $user->id)->select('work_order_id');

        return WorkOrder::query()->whereIn('id', $ever)->findOrFail($id);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
