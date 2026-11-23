<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §8 و §10: وحدتا orders و billing (S6: FR-ORD-01 إلى FR-ORD-05، FR-PAY-02، FR-PAY-03، FR-PAY-06)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) { // FR-ORD-01: سلة واحدة لكل مستخدم عبر الأجهزة
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users');
            $table->datetimes(6);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->foreignUuid('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignUuid('part_id')->constrained('parts');
            $table->decimal('quantity', 12, 2);
            $table->decimal('seen_price', 12, 3); // آخر سعر رآه العميل، فيُنبَّه إن تغير (US-14)
            $table->datetimes(6);
            $table->primary(['cart_id', 'part_id']);
        });
        Check::add('cart_items', 'cart_items_qty_chk', 'quantity > 0');

        Schema::create('sales_orders', function (Blueprint $table) { // SM-3a
            $table->uuid('id')->primary();
            $table->string('number', 50)->unique();
            $table->foreignUuid('customer_user_id')->constrained('users');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignUuid('rfq_id')->nullable()->unique()->constrained('rfqs'); // 3.14: طلب واحد لكل عرض قطع مقبول
            $table->foreignUuid('quotation_id')->nullable()->constrained('quotations');
            $table->string('status', 30);
            $table->string('fulfillment', 30);
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->json('delivery_address')->nullable();  // نسخة ثابتة
            $table->string('delivery_governorate', 50)->nullable();
            $table->string('payment_method', 30);
            $table->boolean('needs_phone_confirmation')->default(false); // BR-18
            $table->dateTime('phone_confirmed_at', 6)->nullable();
            $table->string('phone_confirmation_note', 500)->nullable();
            $table->dateTime('pickup_deadline_at', 6)->nullable(); // BR-05
            $table->dateTime('pickup_reminded_at', 6)->nullable(); // J-01: تذكير قبل المهلة بيوم مرة واحدة
            $table->decimal('subtotal', 12, 3);
            $table->decimal('vat_total', 12, 3);
            $table->decimal('delivery_fee', 12, 3)->default(0);  // قبل الضريبة، وضريبته ضمن vat_total (BR-01)
            $table->decimal('delivery_vat', 12, 3)->default(0);
            $table->decimal('total', 12, 3);
            $table->char('currency', 3)->default('OMR');
            // رمز الاستلام مشفّر لا مُجزّأ: العميل يحتاج رؤيته في C-14 و M-19 متى فتح الطلب
            $table->text('pickup_code_enc')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->foreignUuid('delivered_by')->nullable()->constrained('users');
            $table->foreignUuid('proof_id')->nullable()->constrained('attachments');
            $table->foreignUuid('created_by')->constrained('users');
            $table->datetimes(6);
            $table->index(['status', 'branch_id', 'created_at']);
            $table->index(['status', 'pickup_deadline_at']);
            $table->index(['customer_user_id', 'status']);
        });
        Check::in('sales_orders', 'status', ['pending_payment', 'confirmed', 'processing', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'cancelled']);
        Check::in('sales_orders', 'fulfillment', ['delivery', 'pickup']);
        Check::in('sales_orders', 'payment_method', ['pay_on_fulfillment', 'credit', 'online']);
        Check::add('sales_orders', 'sales_orders_address_chk', "fulfillment = 'pickup' OR delivery_address IS NOT NULL");
        Check::add('sales_orders', 'sales_orders_pending_chk', "payment_method = 'online' OR status <> 'pending_payment'");
        Check::add('sales_orders', 'sales_orders_deadline_chk', "status <> 'ready_for_pickup' OR pickup_deadline_at IS NOT NULL");
        Check::add('sales_orders', 'sales_orders_money_chk', 'subtotal >= 0 AND vat_total >= 0 AND delivery_fee >= 0 AND total >= 0');

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('sales_orders');
            $table->smallInteger('line_no');
            $table->foreignUuid('part_id')->constrained('parts');
            $table->string('part_number_snapshot');
            $table->string('name_ar_snapshot');
            $table->string('name_en_snapshot');
            $table->string('unit', 30);
            $table->boolean('is_returnable');
            $table->smallInteger('warranty_months')->nullable(); // FR-CAT-07
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 3);                // من الكتالوج، أو من العرض المقبول (3.14)
            $table->decimal('discount_amount', 12, 3)->default(0); // من العرض فقط
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('line_subtotal', 12, 3);
            $table->decimal('line_vat', 12, 3);
            $table->decimal('line_total', 12, 3);
            $table->foreignUuid('stock_location_id')->constrained('stock_locations');
            $table->unique(['order_id', 'line_no']);
        });
        Check::add('sales_order_items', 'sales_order_items_qty_chk', 'quantity > 0');

        // الخط الزمني للطلب (C-14، M-19): كل انتقال سطر، ومنه "تعذر التسليم" الذي يعيد الطلب للتجهيز
        Schema::create('sales_order_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('sales_orders');
            $table->string('status', 30);
            $table->string('note', 500)->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users');
            $table->dateTime('created_at', 6);
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('return_requests', function (Blueprint $table) { // SM-3b
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('sales_orders');
            $table->string('status', 30);
            $table->text('reason');
            $table->text('decision_note')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->dateTime('decided_at', 6)->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users');
            $table->dateTime('received_at', 6)->nullable();
            $table->datetimes(6);
            $table->index(['status', 'created_at']);
        });
        Check::in('return_requests', 'status', ['requested', 'approved', 'rejected', 'received', 'refunded']);

        Schema::create('return_request_attachments', function (Blueprint $table) {
            $table->foreignUuid('return_request_id')->constrained('return_requests');
            $table->foreignUuid('attachment_id')->constrained('attachments');
            $table->primary(['return_request_id', 'attachment_id']);
        });

        Schema::create('return_request_items', function (Blueprint $table) {
            $table->foreignUuid('return_request_id')->constrained('return_requests');
            $table->foreignUuid('order_item_id')->constrained('sales_order_items');
            $table->decimal('quantity', 12, 2);
            $table->boolean('restocked')->nullable(); // عند الاستلام: صالحة تعود للمخزون (3b.4)
            $table->primary(['return_request_id', 'order_item_id']);
        });
        Check::add('return_request_items', 'return_request_items_qty_chk', 'quantity > 0');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('sales_order_id')->references('id')->on('sales_orders');
            $table->foreign('return_request_id')->references('id')->on('return_requests');
        });

        Schema::create('invoices', function (Blueprint $table) { // SM-6a، FR-PAY-03
            $table->uuid('id')->primary();
            $table->string('number', 50)->nullable()->unique(); // يُسند عند الإصدار (BR-14)
            $table->foreignUuid('sales_order_id')->nullable()->unique()->constrained('sales_orders');
            $table->foreignUuid('quotation_id')->nullable()->unique()->constrained('quotations'); // عرض معدة مقبول
            $table->uuid('service_request_id')->nullable();   // FK مع وحدة الصيانة (S7)
            $table->foreignUuid('customer_user_id')->constrained('users');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->string('status', 30);
            $table->json('seller_snapshot');
            $table->json('buyer_snapshot');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('OMR');
            $table->decimal('subtotal', 12, 3);
            $table->decimal('discount_total', 12, 3)->default(0);
            $table->decimal('vat_total', 12, 3);
            $table->decimal('total', 12, 3);
            $table->decimal('amount_paid', 12, 3)->default(0); // إسقاط من payments
            $table->foreignUuid('pdf_id')->nullable()->constrained('attachments');
            $table->string('einvoice_status', 50)->nullable(); // P2
            $table->string('erp_ref')->nullable()->unique();   // P2
            $table->text('void_reason')->nullable();
            $table->foreignUuid('issued_by')->nullable()->constrained('users');
            $table->dateTime('issued_at', 6)->nullable();
            $table->datetimes(6);
            $table->index(['status', 'due_date']);
            $table->index(['customer_user_id', 'status']);
        });
        Check::in('invoices', 'status', ['draft', 'issued', 'partially_paid', 'paid', 'void']);
        Check::exactlyOne('invoices', 'invoices_source_chk', ['sales_order_id', 'quotation_id', 'service_request_id']);
        Check::add('invoices', 'invoices_issued_chk', "status = 'draft' OR (number IS NOT NULL AND issue_date IS NOT NULL)");
        Check::add('invoices', 'invoices_paid_chk', 'amount_paid >= 0 AND amount_paid <= total');

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->smallInteger('line_no');
            $table->string('item_type', 30); // part, equipment, labor, service_fee, delivery, other
            $table->foreignUuid('order_item_id')->nullable()->constrained('sales_order_items');
            $table->text('description_ar');
            $table->text('description_en');
            $table->decimal('quantity', 12, 2);
            $table->string('unit');
            $table->decimal('unit_price', 12, 3);
            $table->decimal('discount_amount', 12, 3)->default(0);
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('line_subtotal', 12, 3);
            $table->decimal('line_vat', 12, 3);
            $table->decimal('line_total', 12, 3);
            $table->smallInteger('warranty_months')->nullable();
            $table->date('warranty_until')->nullable(); // FR-CAT-07: من تاريخ الفاتورة
            $table->unique(['invoice_id', 'line_no']);
        });

        Schema::create('credit_notes', function (Blueprint $table) { // الإلغاء والاسترداد بعد الإصدار
            $table->uuid('id')->primary();
            $table->string('number', 50)->unique();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->foreignUuid('return_request_id')->nullable()->constrained('return_requests');
            $table->text('reason');
            $table->decimal('subtotal', 12, 3);
            $table->decimal('vat_total', 12, 3);
            $table->decimal('total', 12, 3);
            $table->dateTime('issued_at', 6);
            $table->foreignUuid('issued_by')->constrained('users');
            $table->foreignUuid('pdf_id')->nullable()->constrained('attachments');
            $table->datetimes(6);
        });
        Check::add('credit_notes', 'credit_notes_total_chk', 'total > 0');

        Schema::create('payments', function (Blueprint $table) { // SM-6b
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices');
            $table->foreignUuid('sales_order_id')->nullable()->constrained('sales_orders');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches'); // مكان التحصيل لتقرير الصندوق (US-38)
            $table->string('method', 30);
            $table->string('status', 30);
            $table->decimal('amount', 12, 3);
            $table->decimal('refunded_amount', 12, 3)->default(0);
            $table->char('currency', 3)->default('OMR');
            $table->string('gateway', 50)->nullable();            // P3
            $table->string('gateway_session_id')->nullable()->unique();
            $table->string('gateway_ref')->nullable()->unique();
            $table->string('idempotency_key')->unique();
            $table->string('reference_text')->nullable();          // رقم التحويل أو الشيك أو إيصال نقاط البيع
            $table->foreignUuid('proof_id')->nullable()->constrained('attachments');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->dateTime('received_at', 6)->nullable();
            $table->datetimes(6);
            $table->index(['branch_id', 'received_at']);
        });
        Check::in('payments', 'method', ['cash', 'pos_card', 'bank_transfer', 'cheque', 'card']);
        Check::in('payments', 'status', ['initiated', 'succeeded', 'failed', 'refunded', 'partially_refunded']);
        Check::add('payments', 'payments_amount_chk', 'amount > 0 AND refunded_amount >= 0 AND refunded_amount <= amount');
        Check::exactlyOne('payments', 'payments_target_chk', ['invoice_id', 'sales_order_id'], '>=');
        Check::add('payments', 'payments_recorder_chk', "method = 'card' OR recorded_by IS NOT NULL");

        Schema::create('refunds', function (Blueprint $table) { // FR-PAY-06
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments');
            $table->foreignUuid('credit_note_id')->nullable()->constrained('credit_notes');
            $table->foreignUuid('return_request_id')->nullable()->constrained('return_requests');
            $table->string('method', 30);                         // نقد أو تحويل في P1
            $table->decimal('amount', 12, 3);
            $table->text('reason');
            $table->string('reference_text')->nullable();
            $table->string('status', 30);
            $table->string('gateway_ref')->nullable()->unique();  // P3
            $table->foreignUuid('created_by')->constrained('users');
            $table->datetimes(6);
        });
        Check::add('refunds', 'refunds_amount_chk', 'amount > 0');
        Check::in('refunds', 'method', ['cash', 'bank_transfer', 'pos_card', 'card']);
        Check::in('refunds', 'status', ['pending', 'succeeded', 'failed']);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropForeign(['return_request_id']);
        });
        foreach (['refunds', 'payments', 'credit_notes', 'invoice_items', 'invoices', 'return_request_items', 'return_request_attachments',
            'return_requests', 'sales_order_events', 'sales_order_items', 'sales_orders', 'cart_items', 'carts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
