<?php

use App\Models\User;
use App\Modules\Identity\Models\StaffProfile;
use App\Modules\Identity\Sms\LogSmsSender;
use App\Modules\Identity\Sms\SmsSender;
use App\Modules\Platform\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

// اختبارات التكامل على MySQL 8.4 حقيقي (قاعدة dwahi_test)، لا SQLite (04 §11).
// البيانات الأساسية تُزرع مرة واحدة وتُلتزم (FULLTEXT في InnoDB لا يرى إلا الملتزم)،
// وكل اختبار يعمل داخل معاملة تُلغى بعده.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function api(): string
{
    return '/api/v1';
}

/** موظف بأدوار وفرع وكلمة مرور معروفة */
function staffUser(array $roles = ['admin'], string $email = 'admin@dwahi.test', string $password = 'correct horse battery'): User
{
    $u = User::factory()->staff()->create(['email' => $email, 'password_hash' => $password]);
    StaffProfile::query()->create(['user_id' => $u->id, 'employee_no' => 'E-'.substr($u->id, -6), 'branch_id' => Branch::query()->value('id')]);
    $u->syncRoles($roles);

    return $u;
}

/** دخول كامل للوحة الإدارة: كلمة المرور ثم إعداد TOTP أو التحقق منه */
function adminSignIn($test, string $email = 'admin@dwahi.test', string $password = 'correct horse battery'): array
{
    $step = $test->postJson(api().'/auth/staff/login', ['email' => $email, 'password' => $password])->assertOk()->json('data');
    $secret = $step['secret'] ?? decrypt(DB::table('staff_profiles')->join('users', 'users.id', '=', 'staff_profiles.user_id')
        ->where('users.email', $email)->value('totp_secret_enc'));
    $code = (new Google2FA)->getCurrentOtp($secret);

    return $test->postJson(api().'/auth/staff/2fa', ['mfa_token' => $step['mfa_token'], 'code' => $code])->assertOk()->json('data');
}

/** تبديل المستخدم بين الطلبات في نفس الاختبار: الحارس يحفظ المستخدم السابق، فيُنسى أولاً */
function asUser($test, string $token)
{
    app('auth')->forgetGuards();

    return $test->withToken($token);
}

/**
 * عميل مسجل الدخول برمز الجوال وأكمل ملفه (US-06)، ويعيد رمز الوصول. مع اسم شركة يصبح مالكها.
 */
function customerToken($test, string $phone = '91234567', ?string $company = null): string
{
    // نفس المرسل الذي تحمله خدمة الرموز (مفرد في الحاوية)، فتتراكم رسائل كل العملاء فيه
    $sms = app(SmsSender::class);
    assert($sms instanceof LogSmsSender);
    app('auth')->forgetGuards();
    $test->postJson(api().'/auth/otp/request', ['phone' => $phone])->assertStatus(202);
    preg_match('/\d{6}/', end($sms->sent)['text'], $m);
    $token = $test->postJson(api().'/auth/otp/verify', ['phone' => $phone, 'code' => $m[0], 'client' => 'web'])->assertOk()->json('data.access_token');
    $test->withToken($token)->postJson(api().'/me/onboarding', [
        'full_name' => "عميل {$phone}", 'account_type' => $company ? 'company' : 'individual', 'company_name' => $company,
        'governorate' => 'muscat', 'accept_terms' => true, 'accept_privacy' => true,
    ])->assertOk();

    return $token;
}

/** موظف بدور واحد مسجل الدخول، ويعيد رمز الوصول */
function staffToken($test, string $role): string
{
    $email = "{$role}@dwahi.test";
    staffUser([$role], $email);

    return adminSignIn($test, $email)['access_token'];
}

/** عدد الإشعارات بالرمز، ولمستخدم إن حُدد */
function notified(string $code, ?string $userId = null): int
{
    return DB::table('notifications')->where('event_code', $code)->when($userId, fn ($q) => $q->where('user_id', $userId))->count();
}

// ---------- الصيانة (S7، S8) ----------

function machine($test, string $token): string
{
    return asUser($test, $token)->postJson(api().'/me/fleet', ['brand_text' => 'CAT', 'model_text' => '320D', 'organization_id' => null, 'hour_meter' => 9000])
        ->assertCreated()->json('data.id');
}

function serviceRequest($test, string $token, array $extra = []): array
{
    return asUser($test, $token)->postJson(api().'/me/service-requests', $extra + [
        'customer_equipment_id' => machine($test, $token), 'organization_id' => null,
        'problem_description' => 'تسريب زيت من سلندر البوم', 'urgency' => 'normal', 'fees_acknowledged' => true,
    ])->assertCreated()->json('data');
}

function staffUpload($test, string $token, string $name = 'x.png'): string
{
    return asUser($test, $token)->post(api().'/admin/staff-uploads', ['file' => UploadedFile::fake()->image($name)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
}
