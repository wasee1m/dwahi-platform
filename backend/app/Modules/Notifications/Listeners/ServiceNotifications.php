<?php

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Models\ServiceRequest;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** إشعارات الصيانة (01 §8): N-08، N-09، N-11، N-13، N-18، N-24، N-25، N-29، N-32. بعد الالتزام فقط. */
class ServiceNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(ServiceEvent $e): void
    {
        $sr = $e->request->loadMissing(['customer', 'organization', 'equipment.equipmentModel.brand']);
        $link = "/account/service/{$sr->id}";
        $staffLink = "/admin/service-requests/{$sr->id}";
        $item = fn (string $l) => $sr->equipment ? $sr->equipment->displayName() : __('service.components.'.$sr->component_type, [], $l);
        $customer = $sr->organization?->name ?? $sr->customer?->full_name ?? '';
        $wo = $e->workOrder;
        $when = fn (string $l) => $wo?->scheduled_start?->timezone('Asia/Muscat')->format('Y-m-d H:i') ?? '';

        match ($e->type) {
            ServiceEvent::SUBMITTED => $this->notifier->notify($this->coordinators($sr), $sr->urgency === 'urgent' ? 'N-08-urgent' : 'N-08',
                ['number' => $sr->number, 'customer' => $customer, 'item' => $item,
                    'urgency' => fn (string $l) => __("service.urgency.{$sr->urgency}", [], $l)], $staffLink),
            ServiceEvent::SLA_BREACHED => $this->notifier->notify($this->coordinators($sr, true), 'N-09', ['number' => $sr->number, 'customer' => $customer], $staffLink),
            ServiceEvent::DECLINED => $this->notifier->notify($sr->customerRecipients(), 'N-25', ['number' => $sr->number, 'reason' => (string) $e->note], $link),
            ServiceEvent::AUTO_APPROVED => $this->notifier->notify($sr->customerRecipients(), 'N-24', ['number' => $sr->number, 'total' => number_format((float) $e->note, 3)], $link),
            ServiceEvent::RECEIVED => $this->notifier->notify($sr->customerRecipients(), 'N-32', ['number' => $sr->number, 'card' => (string) $e->note, 'item' => $item], $link),
            ServiceEvent::WO_SCHEDULED => $this->scheduled($sr, $e, $link, $item, $when),
            ServiceEvent::WO_CANCELLED => $this->cancelled($sr, $e, $link),
            ServiceEvent::WO_ON_HOLD => $this->onHold($sr, $e, $item),
            default => null,
        };
    }

    /** N-11 للعميل (الإصلاح والفحص)، و N-18 للفريق المسند */
    private function scheduled(ServiceRequest $sr, ServiceEvent $e, string $link, \Closure $item, \Closure $when): void
    {
        $wo = $e->workOrder;
        $this->notifier->notify($sr->customerRecipients(), 'N-11', ['number' => $sr->number, 'when' => $when, 'item' => $item], $link);
        $this->notifier->notify($this->team($wo->id), 'N-18', ['number' => $wo->number, 'when' => $when, 'item' => $item], "/tech/tasks/{$wo->id}");
    }

    /** N-29 للعميل والفريق */
    private function cancelled(ServiceRequest $sr, ServiceEvent $e, string $link): void
    {
        $wo = $e->workOrder;
        $this->notifier->notify($sr->customerRecipients(), 'N-29', ['number' => $sr->number, 'reason' => (string) $e->note], $link);
        $team = User::query()->whereIn('id', DB::table('work_order_assignments')->where('work_order_id', $wo->id)
            ->orderByDesc('assigned_at')->select('technician_user_id'))->get();
        $this->notifier->notify($team, 'N-29', ['number' => $wo->number, 'reason' => (string) $e->note], "/tech/tasks/{$wo->id}", [], 'N-29-tech');
    }

    /** N-13 لمنسقي الفرع. نهاية الدوام إيقاف عادي لا يحتاج تدخلاً فلا يُرسل له. */
    private function onHold(ServiceRequest $sr, ServiceEvent $e, \Closure $item): void
    {
        $wo = $e->workOrder;
        if ($e->note === 'end_of_shift') {
            return;
        }
        $this->notifier->notify($this->coordinators($sr), 'N-13', [
            'number' => $wo->number, 'item' => $item, 'technician' => $e->actor?->full_name ?? '',
            'reason' => fn (string $l) => __("service.hold_reasons.{$e->note}", [], $l),
        ], "/admin/work-orders/{$wo->id}");
    }

    /** منسقو الفرع (وإن لم يوجد أحد فكل المنسقين)، ومع تجاوز المهلة المدير أيضاً بدل "رئيس المنسقين" */
    private function coordinators(ServiceRequest $sr, bool $escalate = false): Collection
    {
        $roles = $escalate ? ['service_coordinator', 'admin'] : ['service_coordinator'];
        $base = User::query()->where('user_type', 'staff')->where('status', 'active')
            ->whereIn('id', DB::table('user_roles')->whereIn('role', $roles)->select('user_id'));
        $inBranch = (clone $base)->whereIn('id', DB::table('staff_profiles')->where('branch_id', $sr->branch_id)->select('user_id'))->get();

        return $inBranch->isNotEmpty() ? $inBranch : $base->get();
    }

    private function team(string $workOrderId): Collection
    {
        return User::query()->whereIn('id', DB::table('work_order_assignments')->where('work_order_id', $workOrderId)->whereNull('unassigned_at')
            ->select('technician_user_id'))->get();
    }
}
