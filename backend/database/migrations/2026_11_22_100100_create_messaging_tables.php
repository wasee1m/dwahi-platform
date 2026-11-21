<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §11: المحادثات والإشعارات (FR-MSG-01، FR-NOT-01، FR-NOT-02). طلبات الصيانة والقطع (S6، S7) لم تُبنَ بعد،
// فعموداهما في المحادثة بلا مفتاح خارجي حتى ترحيل وحدتيهما.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rfq_id')->nullable()->unique()->constrained('rfqs');
            $table->uuid('service_request_id')->nullable()->unique();
            $table->uuid('sales_order_id')->nullable()->unique();
            $table->dateTime('last_message_at', 6)->nullable();
            $table->datetimes(6);
        });
        Check::exactlyOne('conversations', 'conversations_subject_chk', ['rfq_id', 'service_request_id', 'sales_order_id']);

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();                 // يولده المرسل لمنع التكرار عند إعادة المحاولة
            $table->foreignUuid('conversation_id')->constrained('conversations');
            $table->foreignUuid('sender_user_id')->constrained('users');
            $table->text('body')->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->foreignUuid('message_id')->constrained('messages');
            $table->foreignUuid('attachment_id')->constrained('attachments');
            $table->primary(['message_id', 'attachment_id']);
        });

        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->foreignUuid('conversation_id')->constrained('conversations');
            $table->foreignUuid('user_id')->constrained('users');
            $table->dateTime('last_read_at', 6);
            $table->primary(['conversation_id', 'user_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('event_code', 50);              // N-01..N-32 (01 §8)
            $table->string('title');                       // بلغة المستلم
            $table->text('body');
            $table->string('deep_link', 500);
            $table->json('data');
            $table->dateTime('read_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('channel', 30);
            $table->string('status', 30);
            $table->string('provider_ref')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->dateTime('created_at', 6);
        });
        Check::in('notification_deliveries', 'channel', ['push', 'email', 'sms', 'whatsapp']);
        Check::in('notification_deliveries', 'status', ['queued', 'sent', 'delivered', 'failed', 'skipped']);

        Schema::create('notification_preferences', function (Blueprint $table) { // FR-NOT-03 (الشاشة في S5)
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('category', 30);
            $table->string('channel', 30);
            $table->boolean('enabled');
            $table->primary(['user_id', 'category', 'channel']);
        });
        Check::in('notification_preferences', 'category', ['quotes', 'orders', 'service', 'messages', 'marketing']);
        Check::in('notification_preferences', 'channel', ['push', 'email', 'sms', 'whatsapp']);
    }

    public function down(): void
    {
        foreach (['notification_preferences', 'notification_deliveries', 'notifications', 'conversation_reads', 'message_attachments', 'messages', 'conversations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
