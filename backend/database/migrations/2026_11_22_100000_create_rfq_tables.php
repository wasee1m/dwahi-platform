<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 03 §7: طلبات عروض الأسعار والعروض (SM-1، SM-2، FR-RFQ-*). طلبات الصيانة (S7) والعناوين (S5) لم تُبنَ بعد،
// فأعمدتها هنا بلا مفتاح خارجي، ويُضاف في ترحيل وحدتها.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 50)->unique();
            $table->string('type', 30);
            $table->foreignUuid('customer_user_id')->constrained('users');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->string('status', 30);
            $table->string('source', 30);
            $table->foreignUuid('created_by')->constrained('users');      // العميل أو الموظف نيابة عنه
            $table->foreignUuid('assigned_to')->nullable()->constrained('users');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->uuid('service_request_id')->nullable();               // FK في S7
            $table->boolean('is_additional')->default(false);
            $table->uuid('delivery_address_id')->nullable();              // FK في S5
            $table->text('delivery_address_text')->nullable();            // حتى تأتي العناوين المحفوظة
            $table->char('delivery_country', 2)->default('OM');
            $table->text('notes')->nullable();
            $table->string('lost_reason', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->datetimes(6);
            $table->index(['status', 'created_at']);
            $table->index(['customer_user_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
        Check::in('rfqs', 'type', ['equipment', 'parts', 'service']);
        Check::in('rfqs', 'status', ['submitted', 'in_review', 'quoted', 'won', 'lost', 'cancelled']);
        Check::in('rfqs', 'source', ['web', 'app', 'whatsapp', 'phone', 'walk_in', 'system']);
        Check::add('rfqs', 'rfqs_service_link_chk', "(type = 'service') = (service_request_id IS NOT NULL)");
        Check::add('rfqs', 'rfqs_additional_chk', "NOT is_additional OR type = 'service'");
        // تقدير أساسي واحد لكل طلب صيانة
        DB::statement("ALTER TABLE rfqs ADD COLUMN primary_service_key CHAR(36) GENERATED ALWAYS AS
            (CASE WHEN type = 'service' AND NOT is_additional THEN service_request_id END) STORED,
            ADD UNIQUE KEY one_primary_service_rfq (primary_service_key)");

        Schema::create('rfq_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->smallInteger('line_no');
            $table->foreignUuid('listing_id')->nullable()->constrained('equipment_listings');
            $table->foreignUuid('part_id')->nullable()->constrained('parts');
            $table->text('free_text')->nullable();     // قطعة غير مدرجة: رقمها أو وصفها (FR-PRT-05)
            $table->json('specs')->nullable();         // مواصفات السلندر (FR-RFQ-01)
            $table->decimal('quantity', 12, 2);
            $table->unique(['rfq_id', 'line_no']);
        });
        Check::add('rfq_items', 'rfq_items_qty_chk', 'quantity > 0');
        Check::exactlyOne('rfq_items', 'rfq_items_subject_chk', ['listing_id', 'part_id', 'free_text'], '>=');

        Schema::create('rfq_attachments', function (Blueprint $table) {
            $table->foreignUuid('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $table->foreignUuid('rfq_item_id')->nullable()->constrained('rfq_items')->cascadeOnDelete();
            $table->foreignUuid('attachment_id')->constrained('attachments');
            $table->primary(['rfq_id', 'attachment_id']);
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rfq_id')->constrained('rfqs');
            $table->smallInteger('version');
            $table->string('number', 50)->nullable()->unique();
            $table->string('status', 30);
            $table->date('valid_until')->nullable();
            $table->char('currency', 3)->default('OMR');
            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('discount_total', 12, 3)->default(0);
            $table->decimal('vat_total', 12, 3)->default(0);
            $table->decimal('total', 12, 3)->default(0);
            $table->string('estimated_duration')->nullable();
            $table->text('delivery_terms')->nullable();
            $table->text('warranty_terms')->nullable();
            $table->text('payment_terms')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('sent_by')->nullable()->constrained('users');
            $table->dateTime('sent_at', 6)->nullable();
            $table->foreignUuid('responded_by')->nullable()->constrained('users');
            $table->dateTime('responded_at', 6)->nullable();
            $table->text('response_note')->nullable();
            $table->string('lpo_number')->nullable();
            $table->foreignUuid('lpo_attachment_id')->nullable()->constrained('attachments');
            $table->foreignUuid('pdf_id')->nullable()->constrained('attachments');
            $table->dateTime('expiry_reminded_at', 6)->nullable();        // J-03 مرة واحدة
            $table->dateTime('approval_requested_at', 6)->nullable();     // N-31: عضو بلا صلاحية اعتماد
            $table->integer('version_lock')->default(0);
            $table->datetimes(6);
            $table->unique(['rfq_id', 'version']);
        });
        Check::in('quotations', 'status', ['draft', 'sent', 'accepted', 'rejected', 'revision_requested', 'expired', 'superseded']);
        Check::add('quotations', 'quotations_issued_chk', "status = 'draft' OR (valid_until IS NOT NULL AND number IS NOT NULL)");
        // نسخة فعالة واحدة لكل طلب
        DB::statement("ALTER TABLE quotations ADD COLUMN sent_rfq_key CHAR(36) GENERATED ALWAYS AS
            (CASE WHEN status = 'sent' THEN rfq_id END) STORED, ADD UNIQUE KEY one_sent_quotation_per_rfq (sent_rfq_key)");

        Schema::create('quotation_items', function (Blueprint $table) { // نسخة ثابتة BR-17
            $table->uuid('id')->primary();
            $table->foreignUuid('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->smallInteger('line_no');
            $table->string('item_type', 30);
            $table->foreignUuid('listing_id')->nullable()->constrained('equipment_listings');
            $table->foreignUuid('part_id')->nullable()->constrained('parts');
            $table->text('description_ar');
            $table->text('description_en');
            $table->decimal('quantity', 12, 2);
            $table->string('unit');
            $table->decimal('unit_price', 12, 3);
            $table->decimal('discount_amount', 12, 3)->default(0);
            $table->decimal('vat_rate', 5, 2);
            $table->smallInteger('warranty_months')->nullable();
            $table->decimal('line_subtotal', 12, 3);
            $table->decimal('line_vat', 12, 3);
            $table->decimal('line_total', 12, 3);
            $table->unique(['quotation_id', 'line_no']);
        });
        Check::in('quotation_items', 'item_type', ['equipment', 'part', 'labor', 'service_fee', 'other']);
        Check::add('quotation_items', 'quotation_items_qty_chk', 'quantity > 0');
        Check::add('quotation_items', 'quotation_items_price_chk', 'unit_price >= 0 AND discount_amount >= 0');

        Schema::table('equipment_listings', fn (Blueprint $t) => $t->foreign('reserved_for_rfq_id')->references('id')->on('rfqs'));
    }

    public function down(): void
    {
        Schema::table('equipment_listings', fn (Blueprint $t) => $t->dropForeign(['reserved_for_rfq_id']));
        foreach (['quotation_items', 'quotations', 'rfq_attachments', 'rfq_items', 'rfqs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
