<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 03 §9: وحدة service (S7: FR-SRV-01، FR-SRV-02، FR-SRV-03، FR-SRV-08، FR-WO-01، FR-WO-02).
// جداول التنفيذ (الوقت، القطع، الصور، التقرير، الاختبار، العمل الإضافي) مع تطبيق الفني (S8)، والتسليم مع S9.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) { // SM-4
            $table->uuid('id')->primary();
            $table->string('number', 50)->unique();
            $table->foreignUuid('customer_user_id')->constrained('users');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignUuid('customer_equipment_id')->nullable()->constrained('customer_equipment'); // المعدة كاملة
            $table->string('component_type', 30)->nullable();   // أو مكوّن منفصل يُسلَّم للورشة
            $table->text('component_details')->nullable();
            $table->string('status', 30);
            $table->string('urgency', 30);
            $table->text('problem_description')->nullable();
            $table->date('preferred_date')->nullable();          // الموعد المفضل لإحضار المعدة للورشة
            $table->string('source', 30)->default('customer');
            $table->uuid('contract_id')->nullable();             // P2
            $table->boolean('fees_acknowledged');                // BR-11
            $table->decimal('inspection_fee', 12, 3)->nullable(); // نسخة الرسوم المعلنة عند التقديم
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->foreignUuid('coordinator_id')->nullable()->constrained('users');
            $table->string('route', 30)->nullable();
            $table->dateTime('sla_due_at', 6);                   // BR-09
            $table->dateTime('sla_alerted_at', 6)->nullable();   // J-04: تنبيه واحد
            $table->dateTime('triaged_at', 6)->nullable();
            $table->text('decline_reason')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->datetimes(6);
            $table->index(['status', 'branch_id', 'sla_due_at']);
            $table->index(['customer_user_id', 'status']);
        });
        Check::exactlyOne('service_requests', 'service_requests_subject_chk', ['customer_equipment_id', 'component_type']);
        Check::in('service_requests', 'component_type', ['diesel_engine', 'hydraulic_pump', 'hydraulic_cylinder', 'transmission', 'other'], true);
        Check::in('service_requests', 'status', ['submitted', 'triaged', 'awaiting_approval', 'approved', 'in_service', 'completed', 'closed', 'cancelled', 'declined']);
        Check::in('service_requests', 'urgency', ['normal', 'urgent']);
        Check::in('service_requests', 'source', ['customer', 'walk_in', 'contract']);
        Check::in('service_requests', 'route', ['direct_estimate', 'inspection', 'no_approval'], true);
        Check::add('service_requests', 'service_requests_fees_chk', "fees_acknowledged OR source = 'contract'");

        Schema::create('service_request_attachments', function (Blueprint $table) {
            $table->foreignUuid('service_request_id')->constrained('service_requests');
            $table->foreignUuid('attachment_id')->constrained('attachments');
            $table->string('kind', 30);
            $table->primary(['service_request_id', 'attachment_id']);
        });
        Check::in('service_request_attachments', 'kind', ['photo', 'video', 'voice']);

        Schema::table('rfqs', function (Blueprint $table) {
            $table->foreign('service_request_id')->references('id')->on('service_requests');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('service_request_id')->references('id')->on('service_requests');
        });

        Schema::create('work_orders', function (Blueprint $table) { // SM-5
            $table->uuid('id')->primary();
            $table->string('number', 50)->unique();
            $table->foreignUuid('service_request_id')->constrained('service_requests');
            $table->string('type', 30);
            $table->string('status', 30);
            $table->foreignUuid('quotation_id')->nullable()->constrained('quotations'); // التقدير المعتمد (BR-06)
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->dateTime('scheduled_start', 6)->nullable();
            $table->dateTime('scheduled_end', 6)->nullable();
            $table->string('on_hold_reason', 30)->nullable();
            $table->boolean('no_charge')->default(false);
            $table->string('instructions', 2000)->nullable();     // ما يطلبه المنسق من الفني
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->dateTime('verified_at', 6)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->foreignUuid('created_by')->constrained('users');
            $table->datetimes(6);
            $table->index(['branch_id', 'status', 'scheduled_start']);
        });
        Check::in('work_orders', 'type', ['inspection', 'repair', 'preventive', 'warranty']);
        Check::in('work_orders', 'status', ['unassigned', 'scheduled', 'in_progress', 'on_hold', 'work_completed', 'verified', 'closed', 'cancelled']);
        Check::in('work_orders', 'on_hold_reason', ['awaiting_parts', 'awaiting_approval', 'awaiting_customer', 'end_of_shift', 'other'], true);
        Check::add('work_orders', 'work_orders_schedule_chk', "status IN ('unassigned','cancelled') OR scheduled_start IS NOT NULL");
        Check::add('work_orders', 'work_orders_window_chk', 'scheduled_end IS NULL OR scheduled_end > scheduled_start');
        Check::add('work_orders', 'work_orders_hold_chk', "(status = 'on_hold') = (on_hold_reason IS NOT NULL)");

        Schema::create('work_order_assignments', function (Blueprint $table) {
            $table->foreignUuid('work_order_id')->constrained('work_orders');
            $table->foreignUuid('technician_user_id')->constrained('users');
            $table->string('role', 30);
            $table->dateTime('assigned_at', 6);
            $table->dateTime('unassigned_at', 6)->nullable();
            $table->primary(['work_order_id', 'technician_user_id', 'assigned_at']);
            $table->index(['technician_user_id', 'unassigned_at']);
        });
        Check::in('work_order_assignments', 'role', ['lead', 'assistant']);
        // فني رئيسي نشط واحد لكل أمر عمل
        DB::statement("ALTER TABLE work_order_assignments ADD COLUMN active_lead_key CHAR(36) GENERATED ALWAYS AS
            (CASE WHEN role = 'lead' AND unassigned_at IS NULL THEN work_order_id END) STORED,
            ADD UNIQUE KEY one_active_lead (active_lead_key)");

        Schema::create('work_order_status_events', function (Blueprint $table) { // FR-WO-03، دفتر إلحاقي
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->constrained('work_orders');
            $table->string('from_status', 50);
            $table->string('to_status', 50);
            $table->foreignUuid('actor_user_id')->constrained('users');
            $table->text('reason')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->dateTime('received_at', 6);
            $table->uuid('client_event_id')->nullable()->unique(); // ADR-05 (S8)
            $table->index(['work_order_id', 'occurred_at']);
        });

        Schema::create('workshop_intakes', function (Blueprint $table) { // FR-SRV-08، BR-20
            $table->uuid('id')->primary();
            $table->string('number', 50)->unique();              // RC-، ويُطبع على ملصق QR
            $table->foreignUuid('service_request_id')->unique()->constrained('service_requests');
            $table->dateTime('received_at', 6);
            $table->foreignUuid('received_by')->constrained('users');
            $table->string('brought_by_name');                   // قد يكون سائق اللوبد لا العميل
            $table->string('brought_by_phone', 20)->nullable();
            $table->text('condition_notes')->nullable();
            $table->text('accessories')->nullable();
            $table->integer('hour_meter_reading')->nullable();   // للمعدة كاملة
            $table->string('hour_meter_missing_reason', 500)->nullable(); // US-48: سبب تعذر القراءة
            $table->foreignUuid('signature_id')->nullable()->constrained('attachments');
            $table->foreignUuid('receipt_pdf_id')->nullable()->constrained('attachments');
            $table->datetimes(6);
        });
        Check::add('workshop_intakes', 'workshop_intakes_meter_chk', 'hour_meter_reading IS NULL OR hour_meter_reading >= 0');

        Schema::create('workshop_intake_photos', function (Blueprint $table) {
            $table->foreignUuid('intake_id')->constrained('workshop_intakes');
            $table->foreignUuid('attachment_id')->constrained('attachments');
            $table->primary(['intake_id', 'attachment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropForeign(['service_request_id']));
        Schema::table('rfqs', fn (Blueprint $t) => $t->dropForeign(['service_request_id']));
        foreach (['workshop_intake_photos', 'workshop_intakes', 'work_order_status_events', 'work_order_assignments', 'work_orders',
            'service_request_attachments', 'service_requests'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
