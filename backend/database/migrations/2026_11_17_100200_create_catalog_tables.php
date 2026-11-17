<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §4: الماركات والفئات والموديلات والمعدات المعروضة (وحدة catalog، FR-CAT-*)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignUuid('logo_id')->nullable()->constrained('attachments');
            $table->boolean('is_represented')->default(false); // وكيل معتمد (Q-10)
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
        });

        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_id')->nullable()->constrained('equipment_categories');
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->integer('sort_order')->default(0);
            $table->datetimes(6);
        });

        Schema::create('equipment_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('brand_id')->constrained('brands');
            $table->foreignUuid('category_id')->constrained('equipment_categories');
            $table->string('model_name');
            $table->string('slug')->unique();
            $table->integer('operating_weight_kg')->nullable();   // مواصفات قابلة للفلترة (FR-CAT-02)
            $table->decimal('engine_power_kw', 7, 1)->nullable();
            $table->decimal('bucket_capacity_m3', 5, 2)->nullable();
            $table->integer('lifting_capacity_kg')->nullable();
            $table->json('specs')->default(new Expression('(JSON_ARRAY())')); // للعرض فقط
            $table->foreignUuid('spec_sheet_id')->nullable()->constrained('attachments');
            $table->datetimes(6);
            $table->unique(['brand_id', 'model_name']);
            $table->index(['category_id', 'brand_id']);
            $table->index('operating_weight_kg');
        });

        Schema::create('equipment_listings', function (Blueprint $table) { // FR-CAT-01، FR-CAT-06، BR-03
            $table->uuid('id')->primary();
            $table->string('listing_kind', 30)->default('machine');
            $table->foreignUuid('model_id')->nullable()->constrained('equipment_models');
            $table->string('component_type', 50)->nullable();
            $table->string('component_model')->nullable();
            $table->string('source_machine')->nullable();
            $table->text('missing_parts')->nullable();
            $table->string('condition', 30);
            $table->smallInteger('warranty_months')->nullable();
            $table->smallInteger('year')->nullable();
            $table->integer('hour_meter')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('slug')->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->decimal('price', 12, 3)->nullable();
            $table->string('price_visibility', 30);
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->string('status', 30)->default('draft');
            // FK إلى rfqs يُضاف مع جداول العروض في S4 (BR-19)
            $table->uuid('reserved_for_rfq_id')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->dateTime('published_at', 6)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->datetimes(6);
            $table->index(['status', 'condition', 'model_id']);
            $table->index(['status', 'published_at']);
            $table->index(['status', 'listing_kind', 'published_at']);
        });
        Check::in('equipment_listings', 'listing_kind', ['machine', 'component', 'scrap']);
        Check::in('equipment_listings', 'condition', ['new', 'used', 'rebuilt', 'for_parts']);
        Check::in('equipment_listings', 'price_visibility', ['shown', 'on_request']);
        Check::in('equipment_listings', 'status', ['draft', 'published', 'reserved', 'sold', 'archived']);
        Check::add('equipment_listings', 'equipment_listings_warranty_chk', 'warranty_months IS NULL OR warranty_months >= 0');
        Check::add('equipment_listings', 'equipment_listings_price_chk', "price_visibility = 'on_request' OR price IS NOT NULL");
        Check::add('equipment_listings', 'equipment_listings_reserved_chk', "reserved_for_rfq_id IS NULL OR status IN ('reserved','sold')");
        Check::add('equipment_listings', 'equipment_listings_component_chk', "(listing_kind = 'component') = (component_model IS NOT NULL)");
        Check::add('equipment_listings', 'equipment_listings_model_chk', "listing_kind = 'component' OR model_id IS NOT NULL");
        Check::add('equipment_listings', 'equipment_listings_scrap_chk', "(listing_kind = 'scrap') = (`condition` = 'for_parts')");
        Check::add('equipment_listings', 'equipment_listings_used_chk', "listing_kind <> 'machine' OR `condition` = 'new' OR (year IS NOT NULL AND hour_meter IS NOT NULL)");
        Check::add('equipment_listings', 'equipment_listings_scrap_year_chk', "listing_kind <> 'scrap' OR year IS NOT NULL");

        Schema::create('listing_media', function (Blueprint $table) {
            $table->foreignUuid('listing_id')->constrained('equipment_listings');
            $table->foreignUuid('attachment_id')->constrained('attachments');
            $table->integer('sort_order');
            $table->primary(['listing_id', 'attachment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_media');
        Schema::dropIfExists('equipment_listings');
        Schema::dropIfExists('equipment_models');
        Schema::dropIfExists('equipment_categories');
        Schema::dropIfExists('brands');
    }
};
