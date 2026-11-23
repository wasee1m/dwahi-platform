<?php

namespace App\Modules\Service\Services;

use App\Models\User;
use App\Modules\Billing\Support\DocumentNumber;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\Rfq;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use App\Support\ApiProblem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** إنشاء أوامر العمل وجدولتها وإلغاؤها (SM-5 5.1 إلى 5.3، 5.14). التنفيذ مع تطبيق الفني (S8). */
final class WorkOrderService
{
    /** 5.1: أمر فحص من الفرز، أو أمر إصلاح لطلب معتمد بتقديره (4.11) */
    public function create(ServiceRequest $sr, User $staff, string $type, ?string $instructions = null): WorkOrder
    {
        $quotation = null;
        if ($type === 'repair') {
            $rfqId = Rfq::query()->where('service_request_id', $sr->id)->where('is_additional', false)->where('status', 'won')->value('id');
            $quotation = $rfqId ? Quotation::query()->where('rfq_id', $rfqId)->where('status', 'accepted')->value('id') : null;
        }
        $wo = WorkOrder::query()->create([
            'number' => DocumentNumber::next('WO'),
            'service_request_id' => $sr->id,
            'type' => $type,
            'status' => 'unassigned',
            'quotation_id' => $quotation,
            'branch_id' => $sr->branch_id,
            'instructions' => $instructions,
            'created_by' => $staff->id,
        ]);
        $this->event($wo, $staff, '', 'unassigned', null);
        Audit::record('work_order.created', 'work_order', $wo->id, null, ['number' => $wo->number, 'type' => $type, 'service_request' => $sr->number], $staff);

        return $wo;
    }

    /** 4.11: أمر الإصلاح لطلب معتمد، فيصبح الطلب in_service */
    public function createRepair(ServiceRequest $sr, User $staff, ?string $instructions): WorkOrder
    {
        return DB::transaction(function () use ($sr, $staff, $instructions) {
            $sr = ServiceRequest::query()->lockForUpdate()->findOrFail($sr->id);
            if ($sr->status !== 'approved') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $sr->status, 'to' => 'in_service']);
            }
            $wo = $this->create($sr, $staff, 'repair', $instructions);
            $sr->update(['status' => 'in_service']);
            Audit::record('service.in_service', 'service_request', $sr->id, ['status' => 'approved'], ['status' => 'in_service'], $staff);

            return $wo;
        });
    }

    /**
     * 5.2 و 5.3: فني رئيسي ومساعدون ونافذة زمنية. التعارض مع مهمة نشطة لأي منهم يُرفض إلا بتأكيد،
     * وإعادة الجدولة تحتاج سبباً (US-33). أمر جارٍ لا يُنقل لموعد آخر.
     *
     * @param  list<string>  $assistants
     */
    public function schedule(WorkOrder $wo, User $staff, string $leadId, array $assistants, CarbonInterface $start, CarbonInterface $end, bool $force, ?string $reason): WorkOrder
    {
        $wo = DB::transaction(function () use ($wo, $staff, $leadId, $assistants, $start, $end, $force, $reason) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            if (! in_array($wo->status, ['unassigned', 'scheduled'], true)) {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $wo->status, 'to' => 'scheduled']);
            }
            if ($wo->status === 'scheduled' && blank($reason)) {
                throw ValidationException::withMessages(['reason' => [__('service.reschedule_reason')]]);
            }
            $team = array_values(array_unique([$leadId, ...$assistants]));
            $technicians = User::query()->whereIn('id', $team)->where('user_type', 'staff')->where('status', 'active')
                ->whereIn('id', DB::table('user_roles')->where('role', 'technician')->select('user_id'))->pluck('id');
            if ($technicians->count() !== count($team)) {
                throw ValidationException::withMessages(['lead_id' => [__('service.not_technicians')]]);
            }
            $conflicts = $this->conflicts($team, $start, $end, $wo->id);
            if ($conflicts->isNotEmpty() && ! $force) {
                throw new ApiProblem('SCHEDULE_CONFLICT', 409, null, ['conflicts' => $conflicts->all()]);
            }

            $from = $wo->status;
            // نفس الفريق بنفس الأدوار يبقى، وإلا يُنهى الإسناد الحالي ويُسجل الجديد (السجل يحفظ من عمل على الأمر)
            $current = DB::table('work_order_assignments')->where('work_order_id', $wo->id)->whereNull('unassigned_at')->pluck('role', 'technician_user_id')->all();
            $wanted = collect($team)->mapWithKeys(fn ($id) => [$id => $id === $leadId ? 'lead' : 'assistant'])->all();
            ksort($current);
            ksort($wanted);
            if ($current !== $wanted) {
                $at = now()->format('Y-m-d H:i:s.u');
                DB::table('work_order_assignments')->where('work_order_id', $wo->id)->whereNull('unassigned_at')->update(['unassigned_at' => $at]);
                foreach ($wanted as $id => $role) {
                    DB::table('work_order_assignments')->insert(['work_order_id' => $wo->id, 'technician_user_id' => $id, 'role' => $role, 'assigned_at' => $at]);
                }
            }
            $wo->update(['status' => 'scheduled', 'scheduled_start' => $start, 'scheduled_end' => $end]);
            $this->event($wo, $staff, $from, 'scheduled', $reason);
            Audit::record('work_order.scheduled', 'work_order', $wo->id, ['status' => $from],
                ['status' => 'scheduled', 'start' => $start->toIso8601ZuluString(), 'lead' => $leadId, 'conflict_overridden' => $conflicts->isNotEmpty()], $staff);

            return $wo;
        });
        ServiceEvent::dispatch(ServiceEvent::WO_SCHEDULED, $wo->serviceRequest, $staff, $reason, $wo);

        return $wo;
    }

    /** 5.14 و 5.15: الإلغاء بسبب إلزامي (N-29) */
    public function cancel(WorkOrder $wo, User $staff, string $reason): WorkOrder
    {
        $wo = DB::transaction(function () use ($wo, $staff, $reason) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);

            return $this->cancelInside($wo, $staff, $reason);
        });
        ServiceEvent::dispatch(ServiceEvent::WO_CANCELLED, $wo->serviceRequest, $staff, $reason, $wo);

        return $wo;
    }

    /** داخل معاملة قائمة (إلغاء العميل 4.4) */
    public function cancelInside(WorkOrder $wo, User $actor, string $reason): WorkOrder
    {
        if (! in_array($wo->status, ['unassigned', 'scheduled', 'on_hold'], true)) {
            throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $wo->status, 'to' => 'cancelled']);
        }
        $from = $wo->status;
        $wo->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'on_hold_reason' => null]);
        DB::table('work_order_assignments')->where('work_order_id', $wo->id)->whereNull('unassigned_at')->update(['unassigned_at' => now()->format('Y-m-d H:i:s.u')]);
        $this->event($wo, $actor, $from, 'cancelled', $reason);
        Audit::record('work_order.cancelled', 'work_order', $wo->id, ['status' => $from], ['status' => 'cancelled', 'reason' => $reason], $actor);

        return $wo;
    }

    /**
     * مهام نشطة متداخلة مع النافذة لأي فني من الفريق
     *
     * @param  list<string>  $team
     * @return Collection<int, array{work_order: string, technician: string, start: string|null, end: string|null}>
     */
    public function conflicts(array $team, CarbonInterface $start, CarbonInterface $end, ?string $except = null): Collection
    {
        return DB::table('work_orders as w')
            ->join('work_order_assignments as a', 'a.work_order_id', '=', 'w.id')
            ->join('users as u', 'u.id', '=', 'a.technician_user_id')
            ->whereIn('a.technician_user_id', $team)->whereNull('a.unassigned_at')
            ->whereIn('w.status', WorkOrder::ACTIVE)
            ->when($except, fn ($q) => $q->where('w.id', '!=', $except))
            ->where('w.scheduled_start', '<', $end)
            ->where(fn ($q) => $q->where('w.scheduled_end', '>', $start)->orWhereNull('w.scheduled_end'))
            ->get(['w.number', 'u.full_name', 'w.scheduled_start', 'w.scheduled_end'])
            ->map(fn ($r) => ['work_order' => $r->number, 'technician' => $r->full_name,
                'start' => $r->scheduled_start ? str_replace(' ', 'T', substr($r->scheduled_start, 0, 19)).'Z' : null,
                'end' => $r->scheduled_end ? str_replace(' ', 'T', substr($r->scheduled_end, 0, 19)).'Z' : null]);
    }

    private function event(WorkOrder $wo, User $actor, string $from, string $to, ?string $reason): void
    {
        DB::table('work_order_status_events')->insert([
            'id' => (string) Str::uuid7(), 'work_order_id' => $wo->id, 'from_status' => $from, 'to_status' => $to,
            'actor_user_id' => $actor->id, 'reason' => $reason, 'occurred_at' => now()->format('Y-m-d H:i:s.u'), 'received_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
