<?php

namespace App\Modules\Billing\Events;

use App\Modules\Billing\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/** إصدار فاتورة (N-16) */
final class InvoiceIssued
{
    use Dispatchable;

    public function __construct(public Invoice $invoice) {}
}
