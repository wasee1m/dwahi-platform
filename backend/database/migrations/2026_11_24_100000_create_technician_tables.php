<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §9 و 04 §6: تنفيذ أمر العمل من تطبيق الفني (S8: FR-TEC-01..04، FR-TEC-07، FR-WO-03، FR-WO-04).
// الاختبار والتقرير والعمل الإضافي مع S9.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) { // يحسبها الخادم من أحداث الحالة (04 §6.4)
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->constrained('work_orders');
            $table->foreignUuid('technician_user_id')->constrained('users');
            $table->string('kind', 30)->default('work');
            $table->dateTime('started_at', 6);
            $table->dateTime('ended_at', 6)->nullable();
            $table->string('source', 30)->default('auto');
            $table->text('edited_reason')->nullable();
            $table->index(['work_order_id', 'ended_at']);
            $table->index(['technician_user_id', 'started_at']);
        });
        Check::in('time_entries', 'kind', ['work']);
        Check::in('time_entries', 'source', ['auto', 'manual']);
        Check::add('time_entries', 'time_entries_window_chk', 'ended_at IS NULL OR ended_at >= started_at');
        Check::add('time_entries', 'time_entries_manual_chk', "source = 'auto' OR edited_reason IS NOT NULL");

        Schema::create('work_order_parts', function (Blueprint $table) { // FR-WO-04
            $table->uuid('id')->primary();                       // يولده التطبيق بدون اتصال
            $table->foreignUuid('work_order_id')->constrained('work_orders');
            $table->foreignUuid('part_id')->nullable()->constrained('parts');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2);
            $table->string('source', 30);
            $table->foreignUuid('stock_location_id')->nullable()->constrained('stock_locations');
            $table->decimal('unit_price', 12, 3)->nullable();    // نسخة ثابتة للفوترة
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->dateTime('recorded_at', 6);
            $table->dateTime('removed_at', 6)->nullable();       // part_removed: يبقى السجل ويُعاد المخزون
            $table->foreignUuid('removed_by')->nullable()->constrained('users');
            $table->index(['work_order_id', 'removed_at']);
        });
        Check::add('work_order_parts', 'work_order_parts_qty_chk', 'quantity > 0');
        Check::in('work_order_parts', 'source', ['store', 'customer_supplied', 'purchased']);
        Check::add('work_order_parts', 'work_order_parts_subject_chk', 'part_id IS NOT NULL OR description IS NOT NULL');
        Check::add('work_order_parts', 'work_order_parts_store_chk', "source <> 'store' OR (part_id IS NOT NULL AND stock_location_id IS NOT NULL)");

        Schema::create('work_order_photos', function (Blueprint $table) {
            $table->foreignUuid('work_order_id')->constrained('work_orders');
            $table->foreignUuid('attachment_id')->constrained('attachments');
            $table->string('phase', 30);
            $table->text('caption')->nullable();
            $table->dateTime('taken_at', 6);
            $table->foreignUuid('taken_by')->constrained('users');
            $table->boolean('from_previous_technician')->default(false); // 04 §6.4: أمر أُعيد إسناده أثناء الانقطاع
            $table->primary(['work_order_id', 'attachment_id']);
        });
        Check::in('work_order_photos', 'phase', ['before', 'during', 'after', 'hour_meter', 'damage', 'test']);

        Schema::create('work_order_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->constrained('work_orders');
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body')->nullable();
            $table->foreignUuid('voice_id')->nullable()->constrained('attachments');
            $table->boolean('is_internal')->default(true);       // لا يظهر للعميل
            $table->boolean('from_previous_technician')->default(false);
            $table->dateTime('created_at', 6);
        });
        Check::add('work_order_notes', 'work_order_notes_body_chk', 'body IS NOT NULL OR voice_id IS NOT NULL');

        // ADR-05: كل حدث من الجهاز مرة واحدة بمعرّفه، ونتيجته محفوظة فيُعاد نفس الرد عند التكرار
        Schema::create('tech_sync_events', function (Blueprint $table) {
            $table->uuid('client_event_id')->primary();
            $table->foreignUuid('device_id')->nullable()->constrained('devices');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('work_order_id')->nullable()->constrained('work_orders');
            $table->string('type', 40);
            $table->string('result', 20);                        // applied | rejected
            $table->string('error_code', 60)->nullable();
            $table->dateTime('occurred_at', 6);
            $table->boolean('clock_adjusted')->default(false);   // 04 §6.4: انحراف ساعة الجهاز
            $table->dateTime('received_at', 6);
            $table->index(['user_id', 'received_at']);
        });
        Check::in('tech_sync_events', 'result', ['applied', 'rejected']);

        // المفاتيح المؤجلة لأمر العمل: قراءات العداد (S4) وحركات المخزون (S5)
        foreach (['hour_meter_readings', 'stock_movements'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->foreign('work_order_id')->references('id')->on('work_orders'));
        }
    }

    public function down(): void
    {
        foreach (['hour_meter_readings', 'stock_movements'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropForeign(['work_order_id']));
        }
        foreach (['tech_sync_events', 'work_order_notes', 'work_order_photos', 'work_order_parts', 'time_entries'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
