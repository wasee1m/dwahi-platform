<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** فهارس الفترة للوحة المؤشرات والتقارير (A-01، A-16، NFR الأداء): كل استعلام يقيد بالتاريخ ثم الفرع */
return new class extends Migration
{
    private const INDEXES = [
        'invoices' => ['issued_at', 'branch_id'],
        'sales_orders' => ['delivered_at', 'branch_id'],
        'service_requests' => ['created_at', 'branch_id'],
        'work_orders' => ['closed_at', 'branch_id'],
        'reviews' => ['created_at'],
        'quotations' => ['rfq_id', 'sent_at'],
        'rfqs' => ['created_at', 'branch_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, "{$table}_report_idx"));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex("{$table}_report_idx"));
        }
    }
};
