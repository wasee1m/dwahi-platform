<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §12: الصفحات والأسئلة الشائعة والبنرات والاستفسارات (وحدة content، FR-CMS-*)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) { // FR-CMS-01، FR-CMS-03
            $table->uuid('id')->primary();
            $table->string('slug', 100)->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->longText('body_ar');   // Markdown
            $table->longText('body_en');
            $table->string('seo_title_ar')->nullable();
            $table->string('seo_title_en')->nullable();
            $table->text('seo_description_ar')->nullable();
            $table->text('seo_description_en')->nullable();
            $table->string('status', 30);
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->datetimes(6);
        });
        Check::in('pages', 'status', ['draft', 'published']);

        Schema::create('faqs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('section', 50);
            $table->text('question_ar');
            $table->text('question_en');
            $table->text('answer_ar');
            $table->text('answer_en');
            $table->integer('sort_order');
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
        });

        Schema::create('banners', function (Blueprint $table) { // FR-CMS-04
            $table->uuid('id')->primary();
            $table->string('placement', 30);
            $table->foreignUuid('image_ar_id')->constrained('attachments');
            $table->foreignUuid('image_en_id')->constrained('attachments');
            $table->string('link', 500)->nullable();
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->datetimes(6);
            $table->index(['placement', 'is_active', 'starts_at']);
        });
        Check::in('banners', 'placement', ['web_home', 'app_home', 'parts', 'service']);
        Check::add('banners', 'banners_period_chk', 'ends_at > starts_at');

        Schema::create('leads', function (Blueprint $table) { // FR-CMS-05
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('phone', 20);
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('topic', 30);
            $table->text('message');
            $table->string('source_page', 500)->nullable();
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->string('status', 30);
            $table->foreignUuid('assigned_to')->nullable()->constrained('users');
            $table->datetimes(6);
            $table->index(['status', 'created_at']);
        });
        Check::in('leads', 'topic', ['equipment', 'parts', 'service', 'other']);
        Check::in('leads', 'status', ['new', 'contacted', 'qualified', 'closed']);
    }

    public function down(): void
    {
        foreach (['leads', 'banners', 'faqs', 'pages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
