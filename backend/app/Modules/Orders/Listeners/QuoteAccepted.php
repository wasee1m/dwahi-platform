<?php

namespace App\Modules\Orders\Listeners;

use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Orders\Services\OrderFromQuote;
use App\Modules\Quoting\Events\QuotingEvent;
use App\Support\ApiProblem;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ما يلي قبول العرض (FR-RFQ-05): عرض القطع يصبح طلب قطع (3.14)، وعرض المعدة فاتورة مسودة يصدرها المحاسب (6.1).
 * إن تعذّر طلب القطع آلياً (بند غير مدرج أو مخزون ناقص) يبقى العرض مقبولاً وينشئه موظف القطع من A-05.
 * عروض الصيانة تتبع أوامر العمل (S7).
 */
class QuoteAccepted implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly OrderFromQuote $orders, private readonly InvoiceService $invoices) {}

    public function handle(QuotingEvent $e): void
    {
        if ($e->type !== QuotingEvent::QUOTATION_ACCEPTED || ! $e->quotation || ! $e->actor) {
            return;
        }
        match ($e->rfq->type) {
            'parts' => $this->order($e),
            'equipment' => $this->invoices->draftFromQuotation($e->quotation),
            default => null,
        };
    }

    private function order(QuotingEvent $e): void
    {
        try {
            $this->orders->create($e->quotation, $e->actor);
        } catch (ApiProblem|ValidationException $x) {
            Log::info('order from accepted quote left to staff', ['rfq' => $e->rfq->number, 'reason' => $x->getMessage()]);
        }
    }
}
