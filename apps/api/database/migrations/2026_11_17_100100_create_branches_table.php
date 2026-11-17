<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §4: الفروع (FR-ADM-02). اليوم ورشة واحدة في صحار، والجدول جاهز لأكثر من فرع.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('governorate', 50);
            $table->string('wilayat', 50);
            $table->text('address_ar')->nullable();
            $table->text('address_en')->nullable();
            $table->geography('location', 'point', 4326);
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp_sales', 20)->nullable();
            $table->string('whatsapp_service', 20)->nullable();
            $table->string('whatsapp_parts', 20)->nullable();
            $table->string('email')->nullable();
            $table->json('working_hours');            // للعرض فقط، و SLA في settings
            $table->json('covered_governorates');
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
            $table->spatialIndex('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
