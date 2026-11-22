<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 03 §3 و §6: وحدة accounts (S5: FR-ACC-02، FR-ACC-04، FR-ACC-05، FR-ACC-06، FR-ADM-05)
return new class extends Migration
{
    public function up(): void
    {
        // الدعوة تُقبل من صاحب الجوال بعد دخوله بالرمز، فإثباتها الرقم نفسه ولا رمز يُمرَّر في الرسالة
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('phone_e164', 20)->nullable(); // يُفرَّغ عند التجهيل (BR-16)
            $table->string('role', 30);
            $table->boolean('can_approve_quotes')->default(false);
            $table->string('status', 30);
            $table->dateTime('expires_at', 6);            // +7 أيام
            $table->foreignUuid('invited_by')->constrained('users');
            $table->datetimes(6);
            $table->index(['phone_e164', 'status']);
        });
        Check::in('organization_invitations', 'role', ['owner', 'member']);
        Check::in('organization_invitations', 'status', ['pending', 'accepted', 'expired', 'revoked']);
        // دعوة معلقة واحدة لكل رقم في الشركة
        DB::statement("ALTER TABLE organization_invitations ADD COLUMN pending_key VARCHAR(80) GENERATED ALWAYS AS
            (CASE WHEN status = 'pending' THEN CONCAT(organization_id, ':', phone_e164) END) STORED,
            ADD UNIQUE KEY one_pending_invitation (pending_key)");

        Schema::create('addresses', function (Blueprint $table) { // FR-ACC-04
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->string('label');
            $table->string('kind', 30);
            $table->string('governorate', 50);
            $table->string('wilayat', 50);
            $table->text('description')->nullable();      // وصف الوصول
            $table->geography('location', 'point', 4326)->nullable(); // الدبوس اختياري حتى مفتاح الخرائط (FR-INT-03)
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->dateTime('archived_at', 6)->nullable();
            $table->datetimes(6);
        });
        Check::exactlyOne('addresses', 'addresses_account_chk', ['user_id', 'organization_id']);
        Check::in('addresses', 'kind', ['delivery', 'site', 'both']);

        Schema::create('customer_equipment', function (Blueprint $table) { // FR-ACC-05
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users');
            $table->foreignUuid('model_id')->nullable()->constrained('equipment_models');
            $table->string('brand_text')->nullable();     // إن لم يكن الموديل في الكتالوج
            $table->string('model_text')->nullable();
            $table->string('serial_number')->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('nickname')->nullable();
            $table->integer('current_hour_meter')->nullable();
            $table->dateTime('hour_meter_updated_at', 6)->nullable();
            $table->foreignUuid('site_address_id')->nullable()->constrained('addresses');
            $table->foreignUuid('nameplate_photo_id')->nullable()->constrained('attachments'); // M-13 صورة اللوحة التعريفية
            $table->date('warranty_until')->nullable();   // P2
            $table->dateTime('archived_at', 6)->nullable();
            $table->datetimes(6);
        });
        Check::exactlyOne('customer_equipment', 'customer_equipment_account_chk', ['organization_id', 'owner_user_id']);
        Check::add('customer_equipment', 'customer_equipment_model_chk', 'model_id IS NOT NULL OR model_text IS NOT NULL');
        Check::add('customer_equipment', 'customer_equipment_meter_chk', 'current_hour_meter IS NULL OR current_hour_meter >= 0');

        Schema::create('hour_meter_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_equipment_id')->constrained('customer_equipment');
            $table->integer('reading');
            $table->string('source', 30);
            $table->uuid('work_order_id')->nullable();    // FK في S7
            $table->foreignUuid('photo_id')->nullable()->constrained('attachments');
            $table->string('note', 500)->nullable();      // سبب القراءة الأقل (تبديل عداد)
            $table->boolean('needs_review')->default(false);
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->dateTime('recorded_at', 6);
            $table->datetimes(6);
            $table->index(['customer_equipment_id', 'recorded_at']);
        });
        Check::add('hour_meter_readings', 'hour_meter_readings_value_chk', 'reading >= 0');
        Check::in('hour_meter_readings', 'source', ['customer', 'intake', 'technician', 'telematics']);

        Schema::create('data_exports', function (Blueprint $table) { // FR-ACC-06
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('status', 30);
            $table->foreignUuid('attachment_id')->nullable()->constrained('attachments');
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->datetimes(6);
            $table->index(['user_id', 'created_at']);
        });
        Check::in('data_exports', 'status', ['pending', 'ready', 'failed', 'expired']);

        Schema::create('customer_notes', function (Blueprint $table) { // FR-ADM-05، على نمط lead_notes
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body');
            $table->dateTime('created_at', 6);
        });
        Check::exactlyOne('customer_notes', 'customer_notes_subject_chk', ['user_id', 'organization_id']);

        Schema::table('rfqs', function (Blueprint $table) {
            $table->foreign('delivery_address_id')->references('id')->on('addresses');
            // المعدة المرتبطة بطلب القطع (C-07، US-04)
            $table->foreignUuid('customer_equipment_id')->nullable()->after('service_request_id')->constrained('customer_equipment');
        });
    }

    public function down(): void
    {
        Schema::table('rfqs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_equipment_id');
            $table->dropForeign(['delivery_address_id']);
        });
        foreach (['customer_notes', 'data_exports', 'hour_meter_readings', 'customer_equipment', 'addresses', 'organization_invitations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
