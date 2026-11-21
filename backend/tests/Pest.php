<?php

use App\Models\User;
use App\Modules\Identity\Models\StaffProfile;
use App\Modules\Platform\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

/** موظف بدور واحد مسجل الدخول، ويعيد رمز الوصول */
function staffToken($test, string $role): string
{
    $email = "{$role}@dwahi.test";
    staffUser([$role], $email);

    return adminSignIn($test, $email)['access_token'];
}
