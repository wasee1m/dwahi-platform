<?php

namespace App\Modules\Service\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Service\Http\ServicePresenter;
use App\Modules\Service\Models\WorkOrder;
use App\Modules\Service\Services\WorkOrderService;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A-09 أوامر العمل و A-10 لوحة التوزيع (FR-WO-01، FR-WO-02، US-33) */
class WorkOrderAdminController
{
    private const WITH = ['serviceRequest.customer', 'serviceRequest.organization', 'serviceRequest.equipment.equipmentModel.brand', 'serviceRequest.intake',
        'serviceRequest.branch', 'serviceRequest.workOrders', 'serviceRequest.estimates', 'technicians'];

    public function __construct(private readonly WorkOrderService $workOrders) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...WorkOrder::STATUSES, 'open'])],
            'technician_id' => ['nullable', 'uuid'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $status = $f['status'] ?? 'open';
        $base = WorkOrder::query()
            ->when($f['technician_id'] ?? null, fn ($q, $t) => $q->whereIn('id', DB::table('work_order_assignments')->where('technician_user_id', $t)->whereNull('unassigned_at')->select('work_order_id')));
        $counts = collect(WorkOrder::STATUSES)->mapWithKeys(fn ($s) => [$s => (clone $base)->where('status', $s)->count()]);
        $rows = (clone $base)->whereIn('status', $status === 'open' ? ['unassigned', 'scheduled', 'in_progress', 'on_hold', 'work_completed'] : [$status])
            ->with(self::WITH)->orderByRaw('scheduled_start IS NULL DESC')->orderBy('scheduled_start')->offset($offset)->limit(51)->get();

        return response()->json([
            'data' => $rows->take(50)->map(fn (WorkOrder $w) => ServicePresenter::workOrderRow($w) + [
                'service_request' => ServicePresenter::row($w->serviceRequest, true),
            ])->values(),
            'counts' => $counts,
            'next_cursor' => $rows->count() > 50 ? OffsetCursor::encode($offset + 50) : null,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $wo = WorkOrder::query()->with([...self::WITH, 'events.actor', 'quotation'])->findOrFail($id);

        return response()->json(['data' => ServicePresenter::workOrderDetail($wo)]);
    }

    /** 5.2 و 5.3: الإسناد والنافذة، مع كشف التعارض وتأكيده (force) */
    public function schedule(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $data = $request->validate([
            'lead_id' => ['required', 'uuid'],
            'assistant_ids' => ['nullable', 'array', 'max:5'],
            'assistant_ids.*' => ['uuid', 'distinct', 'different:lead_id'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'force' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        /** @var User $staff */
        $staff = $request->user();
        $this->workOrders->schedule(WorkOrder::query()->findOrFail($id), $staff, $data['lead_id'], $data['assistant_ids'] ?? [],
            Carbon::parse($data['start'])->utc(), Carbon::parse($data['end'])->utc(), (bool) ($data['force'] ?? false), $data['reason'] ?? null);

        return $this->show($id);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        /** @var User $staff */
        $staff = $request->user();
        $this->workOrders->cancel(WorkOrder::query()->findOrFail($id), $staff, $data['reason']);

        return $this->show($id);
    }

    /**
     * A-10: الفنيون بمهاراتهم ومهامهم في الفترة، وأوامر العمل غير المسندة مع حالة استلام المعدة.
     * الفترة بتوقيت مسقط، يوم أو أسبوع.
     */
    public function board(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:7'],
            'skill' => ['nullable', 'string', 'max:60'],
            'branch_id' => ['nullable', 'uuid'],
        ]);
        $from = Carbon::parse($f['from'] ?? now('Asia/Muscat')->toDateString(), 'Asia/Muscat')->startOfDay();
        $to = $from->copy()->addDays((int) ($f['days'] ?? 1));

        $technicians = User::query()->where('user_type', 'staff')->where('status', 'active')
            ->whereIn('id', DB::table('user_roles')->where('role', 'technician')->select('user_id'))
            ->with('staffProfile')->orderBy('full_name')->get()
            ->filter(fn (User $u) => (! ($f['branch_id'] ?? null) || $u->staffProfile?->branch_id === $f['branch_id'])
                && (! ($f['skill'] ?? null) || in_array(mb_strtolower($f['skill']), array_map('mb_strtolower', $u->staffProfile?->skills ?? []), true)));

        $tasks = DB::table('work_orders as w')
            ->join('work_order_assignments as a', 'a.work_order_id', '=', 'w.id')
            ->join('service_requests as s', 's.id', '=', 'w.service_request_id')
            ->whereNull('a.unassigned_at')->whereIn('a.technician_user_id', $technicians->pluck('id'))
            ->whereIn('w.status', ['scheduled', 'in_progress', 'on_hold', 'work_completed'])
            ->where('w.scheduled_start', '<', $to->copy()->utc())->where('w.scheduled_end', '>', $from->copy()->utc())
            ->get(['w.id', 'w.number', 'w.type', 'w.status', 'w.scheduled_start', 'w.scheduled_end', 'a.technician_user_id', 'a.role', 's.number as sr_number'])
            ->groupBy('technician_user_id');
        $iso = fn (?string $v) => $v ? str_replace(' ', 'T', substr($v, 0, 19)).'Z' : null;

        $unassigned = WorkOrder::query()->where('status', 'unassigned')
            ->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->with(['serviceRequest.customer', 'serviceRequest.organization', 'serviceRequest.equipment.equipmentModel.brand', 'serviceRequest.intake',
                'serviceRequest.branch', 'serviceRequest.workOrders', 'serviceRequest.estimates'])
            ->orderBy('created_at')->get();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'days' => (int) ($f['days'] ?? 1),
            'technicians' => $technicians->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->full_name, 'skills' => $u->staffProfile?->skills ?? [],
                'tasks' => ($tasks[$u->id] ?? collect())->map(fn ($t) => [
                    'id' => $t->id, 'number' => $t->number, 'type' => $t->type, 'status' => $t->status, 'role' => $t->role,
                    'service_request' => $t->sr_number, 'start' => $iso($t->scheduled_start), 'end' => $iso($t->scheduled_end),
                ])->values(),
            ])->values(),
            'unassigned' => $unassigned->map(fn (WorkOrder $w) => ServicePresenter::workOrderRow($w) + [
                'service_request' => ServicePresenter::row($w->serviceRequest, true),
            ])->values(),
        ]]);
    }
}
