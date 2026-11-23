<?php

namespace App\Modules\Service\Listeners;

use App\Modules\Quoting\Events\QuotingEvent;
use App\Modules\Service\Services\ServiceRequestService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * التقدير عرض سعر نوع "صيانة" (FR-SRV-03): إرساله والرد عليه يحركان طلب الصيانة (SM-4). يُسجَّل قبل إشعارات العروض،
 * فالاعتماد الآلي (BR-06) يسبق N-10 ويمنعه.
 */
class EstimateResponses implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly ServiceRequestService $service) {}

    public function handle(QuotingEvent $e): void
    {
        $this->service->onQuote($e);
    }
}
