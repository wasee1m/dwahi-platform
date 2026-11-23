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

    public function __construct(
        public string $type,
        public ServiceRequest $request,
        public ?User $actor = null,
        public ?string $note = null,
        public ?WorkOrder $workOrder = null,
    ) {}
}
