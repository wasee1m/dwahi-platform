<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * الإنجاز والتسليم (S9، 03 §9): قوالب الاختبار ونتائجه، وتقرير الفني، والعمل الإضافي (SM-4 جدول 4a)،
 * وإيصال التسليم (BR-20)، والتقييم (BR-10)، وأعمدة المهام J-05 و J-12.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_templates', function (Blueprint $table) { // FR-TEC-06، تُكمّل من ورقة الشركة (Q-20)
            $table->uuid('id')->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('applies_to', 50);   // component_type، أو equipment للمعدة كاملة، أو general
            $table->json('fields');             // [{key, label_ar, label_en, type: number|boolean, unit, min, max, expect}]
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
            $table->index(['applies_to', 'is_active']);
        });

        Schema::create('work_order_tests', function (Blueprint $table) { // FR-TEC-06، BR-07
            $table->uuid('id')->primary();                 // يولده التطبيق
            $table->foreignUuid('work_order_id')->constrained('work_orders');
            $table->foreignUuid('template_id')->nullable()->constrained('test_templates');
            $table->json('values');
            $table->string('result', 10);
            $table->text('notes')->nullable();
            $table->foreignUuid('media_id')->nullable()->constrained('attachments'); // صورة أو فيديو الاختبار
            $table->foreignUuid('tested_by')->constrained('users');
            $table->dateTime('tested_at', 6);
            $table->index(['work_order_id', 'tested_at']);
        });
        Check::in('work_order_tests', 'result', ['pass', 'fail']);

        Schema::create('service_reports', function (Blueprint $table) { // FR-SRV-05، BR-07
            $table->foreignUuid('work_order_id')->primary()->constrained('work_orders');
            $table->text('findings');
            $table->text('actions_taken');
            $table->text('recommendations')->nullable();
            $table->unsignedInteger('hour_meter_reading')->nullable();
            $table->boolean('no_parts_used')->default(false);
            $table->foreignUuid('submitted_by')->constrained('users');
            $table->dateTime('submitted_at', 6);
            $table->foreignUuid('pdf_id')->nullable()->constrained('attachments'); // يُولد عند الاعتماد (5.12)
        });

        Schema::create('additional_work_requests', function (Blueprint $table) { // FR-TEC-05، SM-4 جدول 4a
            $table->uuid('id')->primary();                 // يولده التطبيق
            $table->foreignUuid('work_order_id')->constrained('work_orders');
            $table->text('description');
            $table->text('parts_needed')->nullable();
            $table->decimal('estimated_amount', 12, 3)->nullable();
            $table->string('status', 30);
            $table->boolean('blocks_work')->default(false); // اختار الفني الإيقاف بانتظار الموافقة
            $table->foreignUuid('rfq_id')->nullable()->unique()->constrained('rfqs');
            $table->foreignUuid('quotation_id')->nullable()->constrained('quotations');
            $table->text('rejection_reason')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->dateTime('requested_at', 6);
            $table->dateTime('decided_at', 6)->nullable();
            $table->index(['work_order_id', 'status']);
        });
        Check::in('additional_work_requests', 'status', ['pending', 'quoted', 'approved', 'rejected']);
        Check::add('additional_work_requests', 'awr_quote_chk', "status IN ('pending','rejected') OR quotation_id IS NOT NULL");

        Schema::create('additional_work_request_attachments', function (Blueprint $table) {
            $table->foreignUuid('additional_work_request_id')->constrained('additional_work_requests', 'id', 'awr_attachments_request_fk');
            $table->foreignUuid('attachment_id')->constrained('attachments');
            $table->primary(['additional_work_request_id', 'attachment_id'], 'awr_attachments_pk');
        });

        Schema::create('service_handovers', function (Blueprint $table) { // FR-SRV-09، BR-20
            $table->uuid('id')->primary();
            $table->foreignUuid('service_request_id')->unique()->constrained('service_requests');
            $table->dateTime('handed_over_at', 6);
            $table->foreignUuid('handed_over_by')->constrained('users');
            $table->string('receiver_name');
            $table->string('receiver_phone', 20)->nullable();
            $table->foreignUuid('signature_id')->constrained('attachments');
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices');
            $table->foreignUuid('payment_id')->nullable()->constrained('payments');
            $table->foreignUuid('receipt_pdf_id')->nullable()->constrained('attachments');
        });

        Schema::create('reviews', function (Blueprint $table) { // BR-10
            $table->uuid('id')->primary();
            $table->foreignUuid('service_request_id')->unique()->constrained('service_requests');
            $table->foreignUuid('customer_user_id')->constrained('users');
            $table->unsignedTinyInteger('rating');
            $table->unsignedTinyInteger('timeliness_rating')->nullable();
            $table->unsignedTinyInteger('quality_rating')->nullable();
            $table->unsignedTinyInteger('communication_rating')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('publish_consent')->default(false);
            $table->datetimes(6);
        });
        foreach (['rating', 'timeliness_rating', 'quality_rating', 'communication_rating'] as $c) {
            Check::add('reviews', "reviews_{$c}_chk", "{$c} IS NULL OR {$c} BETWEEN 1 AND 5");
        }

        Schema::table('service_requests', function (Blueprint $table) {
            $table->dateTime('pickup_reminded_at', 6)->nullable()->after('completed_at'); // J-12
            $table->dateTime('review_invited_at', 6)->nullable()->after('closed_at');     // J-05
        });
        Schema::table('work_orders', function (Blueprint $table) {
            $table->text('no_charge_reason')->nullable()->after('no_charge');
        });

        $this->seedTemplates();
    }

    /** قوالب أولية تُضبط حدودها من ورقة الشركة (Q-20) في A-18 */
    private function seedTemplates(): void
    {
        $n = fn (string $key, string $ar, string $en, string $unit, ?float $min, ?float $max) => compact('key') + ['label_ar' => $ar, 'label_en' => $en, 'type' => 'number', 'unit' => $unit, 'min' => $min, 'max' => $max];
        $b = fn (string $key, string $ar, string $en, bool $expect) => ['key' => $key, 'label_ar' => $ar, 'label_en' => $en, 'type' => 'boolean', 'expect' => $expect];
        $rows = [
            ['hydraulic_cylinder', 'اختبار السلندر الهيدروليكي', 'Hydraulic cylinder test', [
                $n('test_pressure', 'ضغط الاختبار', 'Test pressure', 'bar', 150, 400), $n('hold_minutes', 'مدة الثبات', 'Hold time', 'min', 5, null),
                $b('no_leak', 'لا تسريب', 'No leak', true), $b('full_stroke', 'الشوط كاملاً بسلاسة', 'Full smooth stroke', true)]],
            ['hydraulic_pump', 'اختبار المضخة الهيدروليكية', 'Hydraulic pump test', [
                $n('pressure', 'الضغط', 'Pressure', 'bar', 100, 400), $n('flow', 'التدفق', 'Flow', 'L/min', 1, null),
                $b('no_noise', 'لا صوت غير طبيعي', 'No abnormal noise', true), $b('no_leak', 'لا تسريب', 'No leak', true)]],
            ['diesel_engine', 'اختبار تشغيل المحرك', 'Engine run test', [
                $n('oil_pressure', 'ضغط الزيت', 'Oil pressure', 'bar', 2, 7), $n('coolant_temp', 'حرارة التبريد', 'Coolant temperature', '°C', null, 95),
                $n('run_minutes', 'مدة التشغيل', 'Run time', 'min', 15, null), $b('no_smoke', 'لا دخان غير طبيعي', 'No abnormal smoke', true)]],
            ['transmission', 'اختبار ناقل الحركة', 'Transmission test', [
                $b('all_gears', 'كل السرعات تعمل', 'All gears engage', true), $n('oil_temp', 'حرارة الزيت', 'Oil temperature', '°C', null, 100),
                $b('no_leak', 'لا تسريب', 'No leak', true)]],
            ['equipment', 'اختبار تشغيل المعدة', 'Machine operation test', [
                $b('functions_ok', 'كل الوظائف تعمل', 'All functions work', true), $b('no_leak', 'لا تسريب', 'No leak', true),
                $n('run_minutes', 'مدة التشغيل', 'Run time', 'min', 10, null)]],
            ['general', 'اختبار عام', 'General test', [$b('works', 'يعمل كما يجب', 'Works as expected', true)]],
        ];
        foreach ($rows as [$applies, $ar, $en, $fields]) {
            DB::table('test_templates')->insert([
                'id' => (string) Str::uuid7(), 'name_ar' => $ar, 'name_en' => $en, 'applies_to' => $applies,
                'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn('no_charge_reason'));
        Schema::table('service_requests', fn (Blueprint $table) => $table->dropColumn(['pickup_reminded_at', 'review_invited_at']));
        foreach (['reviews', 'service_handovers', 'additional_work_request_attachments', 'additional_work_requests', 'service_reports', 'work_order_tests', 'test_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
