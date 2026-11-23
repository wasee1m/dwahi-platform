<?php

namespace App\Modules\Service\Listeners;

use App\Modules\Billing\Events\InvoiceIssued;
use App\Modules\Service\Services\VerificationService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** SM-5 5.13: إصدار فاتورة طلب الصيانة يغلق أوامر العمل المعتمدة */
class ServiceInvoices implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly VerificationService $verification) {}

    public function handle(InvoiceIssued $e): void
    {
        $this->verification->onInvoiceIssued($e->invoice);
    }
}
