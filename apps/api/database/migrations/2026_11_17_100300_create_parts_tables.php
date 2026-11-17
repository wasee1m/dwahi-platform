<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 03 §5: القطع والمخزون (وحدة parts، FR-PRT-*)
return new class extends Migration
{
    private const NORMALIZE = "UPPER(REGEXP_REPLACE(%s, '[^A-Za-z0-9]', ''))";

    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('brand_id')->constrained('brands');
            $table->string('part_number', 100);
            $table->string('part_number_normalized', 100)->storedAs(sprintf(self::NORMALIZE, 'part_number'));
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('unit', 30);
            $table->decimal('price', 12, 3);
            $table->decimal('vat_rate', 5, 2)->default(5.00);
            $table->boolean('is_returnable')->default(true);      // BR-12
            $table->smallInteger('warranty_months')->nullable(); // FR-CAT-07
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('erp_ref')->nullable()->unique();     // P2
            $table->datetimes(6);
            $table->unique(['brand_id', 'part_number_normalized']);
            $table->index('part_number_normalized');
        });
        Check::in('parts', 'unit', ['piece', 'set', 'liter', 'meter', 'kg']);
        Check::add('parts', 'parts_warranty_chk', 'warranty_months IS NULL OR warranty_months >= 0');
        // البحث بجزء من الرقم أو الاسم (ADR-09)
        DB::statement('ALTER TABLE parts ADD FULLTEXT parts_search_ft (part_number_normalized, name_ar, name_en) WITH PARSER ngram');

        Schema::create('part_cross_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('part_id')->constrained('parts');
            $table->string('alt_number', 100);
            $table->string('alt_number_normalized', 100)->storedAs(sprintf(self::NORMALIZE, 'alt_number'));
            $table->string('alt_brand')->nullable();
            $table->unique(['part_id', 'alt_number_normalized']);
            $table->index('alt_number_normalized');
        });

        Schema::create('part_compatibility', function (Blueprint $table) {
            $table->foreignUuid('part_id')->constrained('parts');
            $table->foreignUuid('model_id')->constrained('equipment_models');
            $table->text('notes')->nullable();
            $table->primary(['part_id', 'model_id']);
        });

        Schema::create('part_media', function (Blueprint $table) {
            $table->foreignUuid('part_id')->constrained('parts');
            $table->foreignUuid('attachment_id')->constrained('attachments');
            $table->integer('sort_order');
            $table->primary(['part_id', 'attachment_id']);
        });

        Schema::create('stock_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->string('name');
            $table->datetimes(6);
        });

        Schema::create('stock_levels', function (Blueprint $table) { // إسقاط من stock_movements
            $table->foreignUuid('part_id')->constrained('parts');
            $table->foreignUuid('location_id')->constrained('stock_locations');
            $table->decimal('qty_on_hand', 12, 2)->default(0);
            $table->decimal('qty_reserved', 12, 2)->default(0);
            $table->primary(['part_id', 'location_id']);
        });
        Check::add('stock_levels', 'stock_levels_on_hand_chk', 'qty_on_hand >= 0');
        Check::add('stock_levels', 'stock_levels_reserved_chk', 'qty_reserved >= 0 AND qty_reserved <= qty_on_hand');

        Schema::create('stock_movements', function (Blueprint $table) { // دفتر إلحاقي
            $table->uuid('id')->primary();
            $table->foreignUuid('part_id')->constrained('parts');
            $table->foreignUuid('location_id')->constrained('stock_locations');
            $table->decimal('qty_delta', 12, 2);
            $table->string('reason', 30);
            // FKs إلى الطلبات وأوامر العمل والإرجاع تُضاف مع جداولها (S6، S7)
            $table->uuid('sales_order_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('return_request_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->dateTime('created_at', 6);
        });
        Check::add('stock_movements', 'stock_movements_delta_chk', 'qty_delta <> 0');
        Check::in('stock_movements', 'reason', ['receipt', 'sale', 'work_order', 'return', 'adjustment', 'transfer_in', 'transfer_out']);
        Check::exactlyOne('stock_movements', 'stock_movements_source_chk', ['sales_order_id', 'work_order_id', 'return_request_id'], '<=');
    }

    public function down(): void
    {
        foreach (['stock_movements', 'stock_levels', 'stock_locations', 'part_media', 'part_compatibility', 'part_cross_references', 'parts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
