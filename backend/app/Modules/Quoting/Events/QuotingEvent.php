<?php

namespace App\Modules\Quoting\Events;

use App\Models\User;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\Rfq;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * أحداث وحدة العروض (04 §3). حدث واحد بنوعه بدل كلاس لكل انتقال، فالمستمعون في الوحدات الأخرى
 * (الإشعارات، والكتالوج للحجز BR-19، ولاحقاً الصيانة والطلبات والفوترة) يختارون الأنواع التي تهمهم.
 */
final class QuotingEvent
{
    use Dispatchable;

    public const RFQ_SUBMITTED = 'rfq.submitted';           // N-01

    public const RFQ_LOST = 'rfq.lost';                     // N-25

    public const RENEWAL_REQUESTED = 'rfq.renewal_requested'; // N-03 (SM-1 1.9)

    public const QUOTATION_SENT = 'quotation.sent';         // N-02

    public const QUOTATION_ACCEPTED = 'quotation.accepted'; // N-03، BR-19، FR-RFQ-05

    public const QUOTATION_REJECTED = 'quotation.rejected'; // N-03

    public const REVISION_REQUESTED = 'quotation.revision_requested'; // N-03

    public const APPROVAL_REQUESTED = 'quotation.approval_requested'; // N-31

    public const QUOTATION_EXPIRING = 'quotation.expiring'; // N-04

    public const QUOTATION_EXPIRED = 'quotation.expired';

    public function __construct(
        public string $type,
        public Rfq $rfq,
        public ?Quotation $quotation = null,
        public ?User $actor = null,
        public ?string $note = null,
    ) {}
}
