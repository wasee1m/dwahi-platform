<?php

use Illuminate\Support\Facades\DB;

// NFR-08: التحقق الثنائي إلزامي، ويُعطَّل في التطوير المحلي فقط بالإعداد STAFF_2FA_REQUIRED

it('requires the second step by default', function () {
    staffUser();
    $step = $this->postJson(api().'/auth/staff/login', ['email' => 'admin@dwahi.test', 'password' => 'correct horse battery'])->assertOk()->json('data');
    expect($step)->toHaveKey('mfa_token')->not->toHaveKey('access_token');
});

it('signs staff in with the password alone when turned off locally, and records it in the audit log', function () {
    config(['auth.staff_2fa_required' => false]);
    staffUser();
    $r = $this->postJson(api().'/auth/staff/login', ['email' => 'admin@dwahi.test', 'password' => 'correct horse battery'])->assertOk()->json('data');
    expect($r['two_factor_skipped'])->toBeTrue()->and($r['access_token'])->not->toBeEmpty()
        ->and(DB::table('audit_logs')->where('action', 'auth.two_factor_skipped')->count())->toBe(1);
    $this->withToken($r['access_token'])->getJson(api().'/admin/dashboard')->assertOk();

    // كلمة مرور خاطئة تبقى مرفوضة
    $this->postJson(api().'/auth/staff/login', ['email' => 'admin@dwahi.test', 'password' => 'wrong'])->assertStatus(401);
});

it('never skips the second step in production, whatever the setting says', function () {
    config(['auth.staff_2fa_required' => false]);
    app()->detectEnvironment(fn () => 'production');
    staffUser();
    $step = $this->postJson(api().'/auth/staff/login', ['email' => 'admin@dwahi.test', 'password' => 'correct horse battery'])->assertOk()->json('data');
    expect($step)->toHaveKey('mfa_token')->not->toHaveKey('access_token');
    app()->detectEnvironment(fn () => 'testing');
});
