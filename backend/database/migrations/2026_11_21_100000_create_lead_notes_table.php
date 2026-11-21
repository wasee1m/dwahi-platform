<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §12: متابعة الاستفسار (US-43): كل ملاحظة أو تغيير حالة يُحفظ باسم الموظف ووقته
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users');
            $table->text('body')->nullable();
            $table->string('status_from', 30)->nullable();
            $table->string('status_to', 30)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_notes');
    }
};
