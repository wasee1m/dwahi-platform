<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §12: الملفات، والإعدادات، والترقيم، وسجل التدقيق (وحدة platform في 04 §3)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) { // NFR-11
            $table->uuid('id')->primary();
            $table->string('storage_key', 500)->unique();
            $table->string('original_name')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('duration_s')->nullable();
            $table->string('visibility', 30);
            $table->string('scan_status', 30);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users');
            $table->dateTime('created_at', 6);
        });
        Check::in('attachments', 'visibility', ['public', 'private']);
        Check::in('attachments', 'scan_status', ['pending', 'clean', 'infected']);

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('avatar_id')->references('id')->on('attachments');
        });

        Schema::create('settings', function (Blueprint $table) { // FR-ADM-03
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->dateTime('updated_at', 6);
        });

        Schema::create('number_sequences', function (Blueprint $table) { // BR-14
            $table->string('prefix', 10);
            $table->smallInteger('year');
            $table->integer('last_value');
            $table->primary(['prefix', 'year']);
        });

        Schema::create('audit_logs', function (Blueprint $table) { // FR-ADM-04، NFR-10
            $table->id();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $table->json('actor_roles')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->uuid('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('trace_id')->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('settings');
        Schema::table('users', fn (Blueprint $t) => $t->dropForeign(['avatar_id']));
        Schema::dropIfExists('attachments');
    }
};
