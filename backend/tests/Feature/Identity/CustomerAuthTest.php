<?php

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Sms\LogSmsSender;
use App\Modules\Identity\Sms\SmsSender;
use Illuminate\Support\Facades\DB;

// FR-AUTH-01، FR-AUTH-02، FR-AUTH-04، FR-AUTH-05، US-06، US-07

beforeEach(function () {
    $this->sms = new LogSmsSender;
    $this->app->instance(SmsSender::class, $this->sms);
});

function lastCode(LogSmsSender $sms): string
{
    preg_match('/\d{6}/', end($sms->sent)['text'], $m);

    return $m[0];
}

function signIn($test, string $phone = '91234567', string $client = 'web'): array
{
    $test->postJson(api().'/auth/otp/request', ['phone' => $phone])->assertStatus(202);

    return $test->postJson(api().'/auth/otp/verify', ['phone' => $phone, 'code' => lastCode($test->sms), 'client' => $client])
        ->assertOk()->json('data');
}

it('registers a new number with OTP and asks to complete the profile', function () {
    $this->postJson(api().'/auth/otp/request', ['phone' => '9123 4567'])
        ->assertStatus(202)->assertJsonPath('data.expires_in', 300);

    expect($this->sms->sent[0]['to'])->toBe('+96891234567')
        ->and($this->sms->sent[0]['text'])->toContain('صالح 5 دقائق');

    $data = $this->postJson(api().'/auth/otp/verify', ['phone' => '+96891234567', 'code' => lastCode($this->sms), 'client' => 'web'])
        ->assertOk()->json('data');

    expect($data['user']['needs_onboarding'])->toBeTrue()
        ->and($data['access_token'])->not->toBeEmpty()
        ->and($data['refresh_token'])->toStartWith($data['session_id'].'.');
});

it('gives the same answer for registered and unregistered numbers (US-07)', function () {
    User::factory()->create(['phone_e164' => '+96891111111']);
    $a = $this->postJson(api().'/auth/otp/request', ['phone' => '91111111'])->json();
    $b = $this->postJson(api().'/auth/otp/request', ['phone' => '92222222'])->json();
    expect($a)->toBe($b);
});

it('completes onboarding for a company and records consents (NFR-13)', function () {
    $s = signIn($this);
    $this->withToken($s['access_token'])->postJson(api().'/me/onboarding', [
        'full_name' => 'سالم البلوشي', 'account_type' => 'company', 'company_name' => 'شركة الخوض للمقاولات',
        'governorate' => 'muscat', 'accept_terms' => true, 'accept_privacy' => true,
    ])->assertOk()
        ->assertJsonPath('data.needs_onboarding', false)
        ->assertJsonPath('data.organizations.0.role', 'owner')
        ->assertJsonPath('data.organizations.0.can_approve_quotes', true);

    expect(DB::table('consents')->pluck('granted', 'kind')->all())->toBe(['terms' => 1, 'privacy' => 1, 'marketing' => 0]);
});

it('requires accepting the terms', function () {
    $s = signIn($this);
    $this->withToken($s['access_token'])->postJson(api().'/me/onboarding', ['full_name' => 'A', 'account_type' => 'individual'])
        ->assertStatus(422)->assertJsonValidationErrors(['accept_terms', 'accept_privacy'], 'error.details');
});

it('locks the number for 15 minutes after 5 wrong codes (FR-AUTH-05)', function () {
    $this->postJson(api().'/auth/otp/request', ['phone' => '91234567']);
    $good = lastCode($this->sms);
    $bad = $good === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $i) {
        $this->postJson(api().'/auth/otp/verify', ['phone' => '91234567', 'code' => $bad, 'client' => 'web'])
            ->assertStatus(422)->assertJsonPath('error.code', 'OTP_INVALID')->assertJsonPath('error.details.attempts_left', 5 - $i);
    }
    $this->postJson(api().'/auth/otp/verify', ['phone' => '91234567', 'code' => $bad, 'client' => 'web'])
        ->assertStatus(429)->assertJsonPath('error.code', 'OTP_LOCKED')->assertHeader('Retry-After');

    // حتى الرمز الصحيح مرفوض أثناء القفل، ومعه الوقت المتبقي
    $this->postJson(api().'/auth/otp/verify', ['phone' => '91234567', 'code' => $good, 'client' => 'web'])
        ->assertStatus(429)->assertJsonPath('error.code', 'OTP_LOCKED');

    $this->travel(16)->minutes();
    $this->postJson(api().'/auth/otp/request', ['phone' => '91234567'])->assertStatus(202);
});

it('allows only 3 codes per number every 15 minutes', function () {
    foreach (range(1, 3) as $_) {
        $this->postJson(api().'/auth/otp/request', ['phone' => '91234567'])->assertStatus(202);
    }
    $res = $this->postJson(api().'/auth/otp/request', ['phone' => '91234567'])->assertStatus(429)->assertJsonPath('error.code', 'OTP_THROTTLED');
    expect($res->json('error.details.retry_after'))->toBeGreaterThan(800)->toBeLessThanOrEqual(900);
});

it('accepts international mobiles and rejects invalid numbers', function () {
    $this->postJson(api().'/auth/otp/request', ['phone' => '+963 944 123 456'])->assertStatus(202);
    expect($this->sms->sent[0]['to'])->toBe('+963944123456');

    $this->postJson(api().'/auth/otp/request', ['phone' => '1234'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PHONE');
    $this->postJson(api().'/auth/otp/request', ['phone' => '24123456'])->assertStatus(422); // رقم أرضي عماني
});

it('rotates the refresh token and detects reuse of an old one', function () {
    $s = signIn($this);
    $other = signIn($this, '91234567', 'customer_app');

    $rotated = $this->postJson(api().'/auth/token/refresh', ['refresh_token' => $s['refresh_token']])->assertOk()->json('data');
    expect($rotated['refresh_token'])->not->toBe($s['refresh_token']);

    // الرمز القديم بعد مهلة الطلبات المتزامنة = إعادة استخدام، فتنتهي كل الجلسات
    $this->travel(31)->seconds();
    $this->postJson(api().'/auth/token/refresh', ['refresh_token' => $s['refresh_token']])
        ->assertStatus(401)->assertJsonPath('error.code', 'REFRESH_REUSED');

    expect(AuthSession::query()->whereNull('revoked_at')->count())->toBe(0);
    $this->withToken($other['access_token'])->getJson(api().'/me')->assertStatus(401);
});

it('lets the user see and end sessions (US-07)', function () {
    $web = signIn($this);
    $app = signIn($this, '91234567', 'customer_app');

    $list = $this->withToken($web['access_token'])->getJson(api().'/me/sessions')->assertOk()->json('data');
    expect($list)->toHaveCount(2)->and(collect($list)->firstWhere('current', true)['id'])->toBe($web['session_id']);

    $this->withToken($web['access_token'])->deleteJson(api().'/me/sessions/'.$app['session_id'])->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withToken($app['access_token'])->getJson(api().'/me')->assertStatus(401);
    $this->postJson(api().'/auth/token/refresh', ['refresh_token' => $app['refresh_token']])->assertStatus(401);
});

it('uses 24 hours for web without "remember me" and 60 days with it (04 §5)', function () {
    $this->postJson(api().'/auth/otp/request', ['phone' => '91234567']);
    $short = $this->postJson(api().'/auth/otp/verify', ['phone' => '91234567', 'code' => lastCode($this->sms), 'client' => 'web'])->json('data');
    $this->postJson(api().'/auth/otp/request', ['phone' => '91234567']);
    $long = $this->postJson(api().'/auth/otp/verify', ['phone' => '91234567', 'code' => lastCode($this->sms), 'client' => 'web', 'remember' => true])->json('data');

    expect(now()->diffInHours($short['refresh_expires_at']))->toBeBetween(23.9, 24.0)
        ->and(now()->diffInDays($long['refresh_expires_at']))->toBeBetween(59.9, 60.0);
});

it('changes the phone only after OTP on the new number and alerts the old one (N-30)', function () {
    $s = signIn($this);
    $this->withToken($s['access_token'])->postJson(api().'/me/phone/request', ['phone' => '97654321'])->assertStatus(202);
    $this->withToken($s['access_token'])->postJson(api().'/me/phone/verify', ['phone' => '97654321', 'code' => lastCode($this->sms)])
        ->assertOk()->assertJsonPath('data.phone', '+96897654321');

    expect(end($this->sms->sent)['to'])->toBe('+96891234567')
        ->and(DB::table('audit_logs')->where('action', 'user.phone_changed')->exists())->toBeTrue();
});

it('keeps customers out of the admin API', function () {
    $s = signIn($this);
    $this->withToken($s['access_token'])->getJson(api().'/admin/staff')->assertStatus(403)->assertJsonPath('error.code', 'WRONG_CLIENT');
});
