<?php

namespace App\Modules\Service\Events;

use App\Models\User;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use Illuminate\Foundation\Events\Dispatchable;

/** أحداث وحدة الصيانة (04 §3)، حدث واحد بنوعه */
final class ServiceEvent
{
    use Dispatchable;

    public const SUBMITTED = 'service.submitted';           // N-08 لمنسقي الفرع (والعاجل SMS)

    public const DECLINED = 'service.declined';             // N-25

    public const CANCELLED = 'service.cancelled';

    public const AUTO_APPROVED = 'service.auto_approved';   // N-24

    public const RECEIVED = 'service.received';             // N-32 مع إيصال الاستلام

    public const SLA_BREACHED = 'service.sla_breached';     // N-09

    public const WO_SCHEDULED = 'work_order.scheduled';     // N-11 للعميل، N-18 للفني

    public const WO_CANCELLED = 'work_order.cancelled';     // N-29

    public const WO_ON_HOLD = 'work_order.on_hold';         // N-13 للمنسق

    public const WO_COMPLETED = 'work_order.completed';     // N-28 للمنسق

    public const WO_RETURNED = 'work_order.returned';       // N-18 للفني بملاحظة المنسق

    public const ADDITIONAL_REQUESTED = 'additional.requested'; // N-13 للمنسق

    public const ADDITIONAL_DECIDED = 'additional.decided';     // N-18 للفني (approved أو rejected في note)

    public const COMPLETED = 'service.completed';           // N-14 التقرير، و N-12 جاهز للاستلام

    public const PICKUP_REMINDER = 'service.pickup_reminder'; // N-12 تذكير (J-12)

    public const HANDED_OVER = 'service.handed_over';       // إيصال التسليم للعميل

    public const REVIEW_INVITE = 'service.review_invite';   // N-15 (J-05)

    public function __construct(
        public string $type,
        public ServiceRequest $request,
        public ?User $actor = null,
        public ?string $note = null,
        public ?WorkOrder $workOrder = null,
    ) {}
}
