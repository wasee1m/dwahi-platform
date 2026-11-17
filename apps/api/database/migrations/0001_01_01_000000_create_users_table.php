<?php

use App\Support\Database\Check;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 03 §3: المستخدمون والأدوار. باقي جداول الهوية (OTP، الأجهزة، الجلسات، الموافقات) في سبرنت الهوية S1.
// جدول "sessions" محجوز لجلسات التحديث (FR-AUTH-04)، وجلسات Laravel نفسها في Redis.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_type', 30);
            $table->string('full_name');
            $table->string('phone_e164', 20)->nullable()->unique();
            $table->dateTime('phone_verified_at', 6)->nullable();
            $table->string('email')->nullable()->unique();
            $table->dateTime('email_verified_at', 6)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('preferred_locale', 30)->default('ar');
            $table->uuid('avatar_id')->nullable(); // المفتاح الأجنبي في جدول attachments
            $table->string('status', 30)->default('active');
            $table->text('suspended_reason')->nullable();
            $table->dateTime('last_login_at', 6)->nullable();
            $table->smallInteger('pickup_no_show_count')->default(0);
            $table->dateTime('internal_flag_at', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('anonymized_at', 6)->nullable();
            $table->datetimes(6);
        });
        Check::in('users', 'user_type', ['customer', 'staff']);
        Check::in('users', 'preferred_locale', ['ar', 'en']);
        Check::in('users', 'status', ['active', 'suspended', 'deleted']);
        Check::add('users', 'users_customer_phone_chk', "user_type <> 'customer' OR phone_e164 IS NOT NULL OR anonymized_at IS NOT NULL");
        Check::add('users', 'users_staff_email_chk', "user_type <> 'staff' OR email IS NOT NULL");

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('role', 30);
            $table->primary(['user_id', 'role']);
        });
        Check::in('user_roles', 'role', ['sales', 'service_coordinator', 'technician', 'parts_staff', 'accountant', 'content_editor', 'admin']);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('users');
    }
};
