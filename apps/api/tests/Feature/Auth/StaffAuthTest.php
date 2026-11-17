<?php

use App\Models\User;
use App\Modules\Identity\Authorization\Permissions;
use App\Modules\Identity\Models\StaffProfile;
use App\Modules\Identity\Notifications\SetPasswordLink;
use App\Modules\Platform\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

// FR-AUTH-03، FR-ADM-01، FR-ADM-04، NFR-08، NFR-10، US-39، US-44

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

it('forces TOTP setup on first staff sign-in, then asks for the code (NFR-08)', function () {
    staffUser();
    $first = $this->postJson(api().'/auth/staff/login', ['email' => 'admin@dwahi.test', 'password' => 'correct horse battery'])
        ->assertOk()->json('data');
    expect($first['setup_required'])->toBeTrue()->and($first['otpauth_url'])->toStartWith('otpauth://totp/');

    $this->postJson(api().'/auth/staff/2fa', ['mfa_token' => $first['mfa_token'], 'code' => '000000'])
        ->assertStatus(422)->assertJsonPath('error.code', 'TWO_FACTOR_INVALID');
    $this->postJson(api().'/auth/staff/2fa', ['mfa_token' => $first['mfa_token'], 'code' => (new Google2FA)->getCurrentOtp($first['secret'])])
        ->assertOk()->assertJsonPath('data.user.two_factor_enabled', true);

    $second = $this->postJson(api().'/auth/staff/login', ['email' => 'admin@dwahi.test', 'password' => 'correct horse battery'])->json('data');
    expect($second)->not->toHaveKey('secret')->and($second['setup_required'])->toBeFalse();
});

it('rejects wrong staff passwords with one generic error', function () {
    staffUser();
    $this->postJson(api().'/auth/staff/login', ['email' => 'admin@dwahi.test', 'password' => 'wrong'])
        ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    $this->postJson(api().'/auth/staff/login', ['email' => 'nobody@dwahi.test', 'password' => 'wrong'])
        ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('creates staff, emails an activation link, and audits it (US-39)', function () {
    Notification::fake();
    staffUser();
    $s = adminSignIn($this);

    $res = $this->withToken($s['access_token'])->postJson(api().'/admin/staff', [
        'full_name' => 'منسق الصيانة', 'email' => 'coord@dwahi.test', 'employee_no' => 'C-001',
        'branch_code' => 'SOH', 'roles' => ['service_coordinator'],
    ])->assertCreated()->assertJsonPath('data.activated', false);

    $coord = User::query()->find($res->json('data.id'));
    Notification::assertSentTo($coord, SetPasswordLink::class);
    $audit = DB::table('audit_logs')->where('action', 'staff.created')->where('entity_id', $coord->id)->first();
    expect(json_decode($audit->after, true)['roles'])->toBe(['service_coordinator'])
        ->and($audit->actor_user_id)->not->toBeNull();
});

it('lists staff with their branch and two-factor state (eager-loaded profiles)', function () {
    staffUser();
    $s = adminSignIn($this);
    $row = collect($this->withToken($s['access_token'])->getJson(api().'/admin/staff')->assertOk()->json('data'))
        ->firstWhere('email', 'admin@dwahi.test');

    expect($row['branch']['code'])->toBe('SOH')
        ->and($row['two_factor_enabled'])->toBeTrue()
        ->and($row['roles'])->toBe(['admin']);
});

it('denies admin pages to roles without permission (403)', function () {
    staffUser();
    staffUser(['sales'], 'sales@dwahi.test');
    $s = adminSignIn($this, 'sales@dwahi.test');

    $this->withToken($s['access_token'])->getJson(api().'/admin/staff')->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    $this->withToken($s['access_token'])->getJson(api().'/admin/audit-logs')->assertForbidden();
});

it('ends all sessions of a suspended employee immediately', function () {
    staffUser();
    staffUser(['sales'], 'sales@dwahi.test');
    $admin = adminSignIn($this);
    $sales = adminSignIn($this, 'sales@dwahi.test');
    $salesId = User::query()->where('email', 'sales@dwahi.test')->value('id');

    $this->withToken($admin['access_token'])->postJson(api()."/admin/staff/{$salesId}/suspend", ['reason' => 'ترك العمل'])->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($sales['access_token'])->getJson(api().'/me')->assertStatus(401);
    $this->postJson(api().'/auth/staff/login', ['email' => 'sales@dwahi.test', 'password' => 'correct horse battery'])
        ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
});

it('ends an admin session after 30 minutes idle (NFR-08)', function () {
    staffUser();
    $s = adminSignIn($this);
    $this->travel(31)->minutes();
    $this->postJson(api().'/auth/token/refresh', ['refresh_token' => $s['refresh_token']])
        ->assertStatus(401)->assertJsonPath('error.code', 'SESSION_IDLE');
});

it('binds a technician device with a one-time code, valid for one device (FR-AUTH-03)', function () {
    staffUser();
    $tech = staffUser(['technician'], 'tech@dwahi.test');
    $tech->forceFill(['phone_e164' => '+96893333333'])->save();
    $admin = adminSignIn($this);

    $code = $this->withToken($admin['access_token'])->postJson(api()."/admin/technicians/{$tech->id}/activation-code")
        ->assertCreated()->json('data.code');

    $login = fn (array $extra) => $this->postJson(api().'/auth/technician/login', ['login' => '93333333', 'password' => 'correct horse battery', 'platform' => 'android'] + $extra);

    $login([])->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_REGISTERED');
    $deviceId = $login(['activation_code' => strtolower($code)])->assertOk()->json('data.device_id');
    $login(['activation_code' => $code])->assertStatus(422)->assertJsonPath('error.code', 'ACTIVATION_CODE_INVALID');
    $login(['device_id' => $deviceId])->assertOk();

    $this->withToken($admin['access_token'])->deleteJson(api()."/admin/devices/{$deviceId}")->assertNoContent();
    $login(['device_id' => $deviceId])->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_REGISTERED');
});

it('lets the admin search the audit log, which the database refuses to change (US-44)', function () {
    staffUser();
    $s = adminSignIn($this);
    $rows = $this->withToken($s['access_token'])->getJson(api().'/admin/audit-logs?action=auth.')->assertOk()->json('data');
    expect(collect($rows)->pluck('action'))->toContain('auth.two_factor_enabled');

    expect(fn () => DB::table('audit_logs')->update(['action' => 'x']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('audit_logs')->delete())->toThrow(QueryException::class, 'append-only');
});

it('matches the permission matrix in 01 §3.2', function (string $role, string $ability, bool $allowed) {
    expect(Permissions::allows([$role], $ability))->toBe($allowed);
})->with([
    ['admin', 'staff.manage', true],
    ['sales', 'staff.manage', false],
    ['service_coordinator', 'devices.manage', true],
    ['sales', 'devices.manage', false],
    ['service_coordinator', 'service.triage', true],
    ['parts_staff', 'orders.deliver', true],
    ['accountant', 'refunds.manage', true],
    ['sales', 'refunds.manage', false],
    ['content_editor', 'content.manage', true],
    ['technician', 'quotation.send', false],
    ['accountant', 'audit.view', false],
]);
