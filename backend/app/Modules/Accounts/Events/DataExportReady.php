<?php

namespace App\Modules\Accounts\Events;

use App\Modules\Accounts\Models\DataExport;
use Illuminate\Foundation\Events\Dispatchable;

/** نسخة البيانات جاهزة (N-33) */
class DataExportReady
{
    use Dispatchable;

    public function __construct(public DataExport $export) {}
}
