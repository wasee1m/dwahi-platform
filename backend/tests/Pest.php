<?php

use App\Models\User;
use App\Modules\Identity\Models\DeviceActivationCode;
use App\Modules\Identity\Models\StaffProfile;
use App\Modules\Identity\Sms\LogSmsSender;
use App\Modules\Identity\Sms\SmsSender;
use App\Modules\Platform\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

function sendEstimate($test, string $coordinator, string $srId, string $price = '120'): string
{
    $q = asUser($test, $coordinator)->postJson(api()."/admin/service-requests/{$srId}/estimate")->assertCreated()->json('data');
    asUser($test, $coordinator)->putJson(api()."/admin/quotations/{$q['quotation_id']}", ['items' => [
        ['item_type' => 'labor', 'description_ar' => 'تغيير جوانات السلندر', 'description_en' => 'Cylinder reseal', 'quantity' => '1', 'unit' => 'job', 'unit_price' => $price, 'vat_rate' => '5'],
    ], 'estimated_duration' => 'يومان'])->assertOk();
    asUser($test, $coordinator)->postJson(api()."/admin/quotations/{$q['quotation_id']}/send")->assertOk();

    return $q['quotation_id'];
}

/** فني بجهاز مفعّل برمز لمرة واحدة (إصداره من الإدارة مختبر في StaffAuthTest)، ويعيد رمز جلسة technician_app */
function techToken($test, User $tech): string
{
    $code = 'TECH'.strtoupper(Str::random(4));
    DeviceActivationCode::query()->create(['user_id' => $tech->id, 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addDay(), 'issued_by' => $tech->id]);
    app('auth')->forgetGuards();

    return $test->withHeaders(['Authorization' => ''])->postJson(api().'/auth/technician/login', [
        'login' => $tech->email, 'password' => 'correct horse battery', 'platform' => 'android', 'activation_code' => $code,
    ])->assertOk()->json('data.access_token');
}

/**
 * طلب فحص مجدول اليوم لفني، ومستلم في الورشة إلا إن طُلب غير ذلك.
 *
 * @return array{tech: User, token: string, wo: string, coord: string, sr: array<string, mixed>, customer: string}
 */
function techTask($test, array $opts = []): array
{
    $tech = $opts['tech'] ?? staffUser(['technician'], 'tech@dwahi.test');
    $c = $opts['customer'] ?? customerToken($test);
    $sr = serviceRequest($test, $c);
    $coord = $opts['coord'] ?? staffToken($test, 'service_coordinator');
    $wo = asUser($test, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/triage", ['route' => 'inspection', 'urgency' => 'normal', 'branch_id' => Branch::query()->value('id')])
        ->assertOk()->json('data.work_orders.0.id');
    $start = $opts['start'] ?? now();
    asUser($test, $coord)->postJson(api()."/admin/work-orders/{$wo}/schedule", ['lead_id' => $tech->id, 'start' => $start->toIso8601String(), 'end' => $start->copy()->addHours(2)->toIso8601String(), 'force' => true])
        ->assertOk();
    if ($opts['receive'] ?? true) {
        asUser($test, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/intake", [
            'brought_by_name' => 'سائق اللوبد', 'signature_id' => staffUpload($test, $coord, 'sig.png'), 'hour_meter_reading' => 9120,
        ])->assertOk();
    }
    $token = $opts['token'] ?? techToken($test, $tech);

    return ['tech' => $tech, 'token' => $token, 'wo' => $wo, 'coord' => $coord, 'sr' => $sr, 'customer' => $c];
}

/** حدث واحد من الصندوق الصادر */
function ev(string $wo, string $type, array $payload = [], ?string $at = null, ?string $id = null): array
{
    return ['client_event_id' => $id ?? (string) Str::uuid(), 'work_order_id' => $wo, 'type' => $type, 'occurred_at' => $at ?? now()->toIso8601ZuluString(), 'payload' => $payload];
}

function sync($test, string $token, array $events): array
{
    return asUser($test, $token)->postJson(api().'/tech/sync/events', ['events' => $events])->assertOk()->json('data.results');
}

function techUpload($test, string $token, string $name = 'p.jpg'): string
{
    return asUser($test, $token)->post(api().'/tech/uploads', ['file' => UploadedFile::fake()->image($name)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
}
