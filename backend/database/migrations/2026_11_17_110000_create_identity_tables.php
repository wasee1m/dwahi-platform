<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §3: باقي جداول الهوية (S1: FR-AUTH-01..05، FR-ACC-01، FR-ADM-01)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users');
            $table->string('employee_no', 30)->unique();
            $table->foreignUuid('branch_id')->constrained('branches');
            $table->boolean('restrict_to_branch')->default(false);
            $table->json('skills')->default(new Expression('(JSON_ARRAY())'));
            $table->text('totp_secret_enc')->nullable();   // مشفر بمفتاح التطبيق (NFR-07)
            $table->dateTime('totp_enabled_at', 6)->nullable(); // NFR-08
            $table->datetimes(6);
        });

        Schema::create('otp_challenges', function (Blueprint $table) { // FR-AUTH-05
            $table->uuid('id')->primary();
            $table->string('phone_e164', 20);
            $table->string('purpose', 30);
            $table->string('code_hash', 64);
            $table->dateTime('expires_at', 6);
            $table->smallInteger('attempts')->default(0);
            $table->dateTime('consumed_at', 6)->nullable();
            $table->string('ip', 45)->nullable();
            $table->datetimes(6);
            $table->index(['phone_e164', 'created_at']);
        });
        Check::in('otp_challenges', 'purpose', ['login', 'register', 'change_phone', 'invite', 'delete_account']);

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('app', 30);
            $table->string('platform', 30);
            $table->string('push_token')->nullable();
            $table->string('app_version', 30)->nullable();
            $table->string('status', 30)->default('active');
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->datetimes(6);
        });
        Check::in('devices', 'app', ['customer', 'technician', 'web']);
        Check::in('devices', 'platform', ['android', 'ios', 'web']);
        Check::in('devices', 'status', ['active', 'revoked']);

        Schema::create('device_activation_codes', function (Blueprint $table) { // FR-AUTH-03
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('code_hash', 64)->unique();
            $table->dateTime('expires_at', 6);
            $table->foreignUuid('issued_by')->constrained('users');
            $table->dateTime('consumed_at', 6)->nullable();
            $table->foreignUuid('device_id')->nullable()->constrained('devices');
            $table->datetimes(6);
        });
        Check::add('device_activation_codes', 'device_activation_codes_consumed_chk', '(consumed_at IS NULL) = (device_id IS NULL)');

        Schema::create('sessions', function (Blueprint $table) { // FR-AUTH-04، 04 §5
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('device_id')->nullable()->constrained('devices');
            $table->string('client', 30);                       // web | admin | customer_app | technician_app
            $table->boolean('remember')->default(false);         // "تذكرني" في الويب (FR-AUTH-02)
            $table->string('refresh_token_hash', 64)->unique();  // يُدوَّر عند كل استخدام
            $table->string('previous_refresh_token_hash', 64)->nullable(); // مهلة قصيرة للطلبات المتزامنة
            $table->dateTime('rotated_at', 6)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTime('last_used_at', 6)->nullable();
            $table->dateTime('expires_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->string('revoked_reason', 30)->nullable();
            $table->datetimes(6);
            $table->index(['user_id', 'revoked_at']);
        });
        Check::in('sessions', 'client', ['web', 'admin', 'customer_app', 'technician_app']);

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // رمز الوصول ينتمي لجلسة، فإنهاء الجلسة يلغي رموزها فوراً
            $table->foreignUuid('session_id')->nullable()->after('tokenable_id')->constrained('sessions')->cascadeOnDelete();
        });

        Schema::create('consents', function (Blueprint $table) { // NFR-13، سجل إلحاقي
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('kind', 30);
            $table->string('version', 50);
            $table->boolean('granted');
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at', 6);
        });
        Check::in('consents', 'kind', ['terms', 'privacy', 'marketing']);

        Schema::create('organizations', function (Blueprint $table) { // FR-ACC-02 (الإدارة الكاملة في S5)
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('cr_number', 50)->nullable()->unique();
            $table->date('cr_expiry_date')->nullable();
            $table->string('vat_number', 50)->nullable();
            $table->string('governorate', 50);
            $table->string('verification_status', 30)->default('unverified');
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->dateTime('verified_at', 6)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->decimal('credit_limit', 12, 3)->default(0);
            $table->integer('payment_terms_days')->default(0);
            $table->decimal('preapproval_limit', 12, 3)->default(0);
            $table->text('internal_notes')->nullable();
            $table->datetimes(6);
        });
        Check::in('organizations', 'verification_status', ['unverified', 'pending', 'verified', 'rejected']);

        Schema::create('organization_members', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('role', 30);
            $table->boolean('can_approve_quotes')->default(false);
            $table->dateTime('joined_at', 6);
            $table->dateTime('removed_at', 6)->nullable();
            $table->primary(['organization_id', 'user_id']);
        });
        Check::in('organization_members', 'role', ['owner', 'member']);

        // استعادة كلمة المرور وتفعيل حساب الموظف (FR-AUTH-04، US-39) عبر Password Broker في Laravel
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropConstrainedForeignId('session_id'));
        foreach (['password_reset_tokens', 'organization_members', 'organizations', 'consents', 'sessions',
            'device_activation_codes', 'devices', 'otp_challenges', 'staff_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
