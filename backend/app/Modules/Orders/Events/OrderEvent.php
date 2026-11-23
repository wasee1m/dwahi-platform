<?php

namespace App\Modules\Orders\Events;

use App\Models\User;
use App\Modules\Orders\Models\ReturnRequest;
use App\Modules\Orders\Models\SalesOrder;
use Illuminate\Foundation\Events\Dispatchable;

/** أحداث وحدة الطلبات (04 §3)، حدث واحد بنوعه كما في QuotingEvent */
final class OrderEvent
{
    use Dispatchable;

    public const CONFIRMED = 'order.confirmed';             // N-05 للعميل، N-23 لموظفي قطع الفرع

    public const READY = 'order.ready_for_pickup';          // N-06

    public const OUT_FOR_DELIVERY = 'order.out_for_delivery'; // N-06

    public const DELIVERED = 'order.delivered';             // N-06

    public const DELIVERY_FAILED = 'order.delivery_failed'; // N-26

    public const CANCELLED = 'order.cancelled';             // N-07 (آلياً أو من الموظف بسبب)

    public const PICKUP_REMINDER = 'order.pickup_reminder'; // N-07: قبل انتهاء المهلة بيوم

    public const RETURN_REQUESTED = 'return.requested';     // N-27 لموظفي القطع

    public const RETURN_DECIDED = 'return.decided';         // N-27 للعميل

    public function __construct(
        public string $type,
        public SalesOrder $order,
        public ?User $actor = null,
        public ?string $note = null,
        public ?ReturnRequest $return = null,
    ) {}
}
