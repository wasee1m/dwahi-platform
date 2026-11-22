<?php

use App\Models\User;
use App\Modules\Identity\Sms\SmsSender;
use App\Modules\Notifications\Jobs\SendInvitationSms;
use App\Modules\Notifications\Support\QuietHours;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

// FR-ACC-02، US-09، N-19، SM-7

function company($test, string $phone = '91234567'): array
{
    $token = customerToken($test, $phone, "شركة {$phone}");

    return [$token, asUser($test, $token)->getJson(api().'/me/organizations')->assertOk()->json('data.0.id')];
}

function userId(string $phone): string
{
    return User::query()->where('phone_e164', "+968{$phone}")->valueOrFail('id');
}

function join_company($test, string $owner, string $org, string $phone, string $role = 'member', bool $approves = false): string
{
    asUser($test, $owner)->postJson(api()."/me/organizations/{$org}/invitations", ['phone' => $phone, 'role' => $role, 'can_approve_quotes' => $approves])->assertCreated();
    $token = customerToken($test, $phone);
    $id = asUser($test, $token)->getJson(api().'/me/invitations')->assertOk()->json('data.0.id');
    asUser($test, $token)->postJson(api()."/me/invitations/{$id}/accept")->assertOk();

    return $token;
}

it('invites a colleague who then sees the company (US-09, N-19)', function () {
    [$owner, $org] = company($this);
    $member = customerToken($this, '92345678');

    $res = asUser($this, $owner)->postJson(api()."/me/organizations/{$org}/invitations", ['phone' => '9234 5678', 'role' => 'member'])
        ->assertCreated()->assertJsonPath('data.invitations.0.phone', '+96892345678');
    expect(strtotime($res->json('data.invitations.0.expires_at')) - time())->toBeGreaterThan(7 * 86400 - 120)
        ->and(DB::table('notifications')->where(['user_id' => userId('92345678'), 'event_code' => 'N-19'])->count())->toBe(1);

    $id = asUser($this, $member)->getJson(api().'/me/invitations')->assertOk()->json('data.0.id');
    asUser($this, $member)->postJson(api()."/me/invitations/{$id}/accept")->assertOk()
        ->assertJsonPath('data.my_role', 'member')->assertJsonPath('data.can_manage', false);
    asUser($this, $member)->getJson(api().'/me')->assertJsonPath('data.organizations.0.id', $org);
});

it('texts a number that has no account yet, without links from user-written names', function () {
    [$owner, $org] = company($this);
    asUser($this, $owner)->patchJson(api()."/me/organizations/{$org}", ['name' => 'شركة evil.example.com/win الخوض'])->assertOk();
    asUser($this, $owner)->postJson(api()."/me/organizations/{$org}/invitations", ['phone' => '93456789', 'role' => 'member'])->assertCreated();

    $sms = collect(app(SmsSender::class)->sent)->last();
    expect($sms['to'])->toBe('+96893456789')->and($sms['text'])->toContain('الخوض')->not->toContain('evil.example.com');
});

it('holds an invitation text during quiet hours', function () {
    [$owner, $org] = company($this);
    Bus::fake([SendInvitationSms::class]);
    $this->travelTo(now('Asia/Muscat')->setTime(23, 30)->utc());
    // رمز الوصول عمره 15 دقيقة، فالدخول بعد تغيير الساعة
    $owner = customerToken($this, '91234567');

    asUser($this, $owner)->postJson(api()."/me/organizations/{$org}/invitations", ['phone' => '93456789', 'role' => 'member'])->assertCreated();

    Bus::assertDispatched(SendInvitationSms::class, fn ($job) => Carbon::instance($job->delay)->timezone('Asia/Muscat')->format('H:i') === '07:00');
});

it('computes the next allowed SMS time', function (string $muscat, ?string $expected) {
    $at = QuietHours::sendAt(Carbon::parse($muscat, 'Asia/Muscat'));

    expect($at?->timezone('Asia/Muscat')->format('Y-m-d H:i'))->toBe($expected);
})->with([
    ['2026-11-22 21:59', null],
    ['2026-11-22 22:00', '2026-11-23 07:00'],
    ['2026-11-23 03:15', '2026-11-23 07:00'],
    ['2026-11-23 07:00', null],
]);

it('lets only the invited number accept, once, within 7 days', function () {
    [$owner, $org] = company($this);
    asUser($this, $owner)->postJson(api()."/me/organizations/{$org}/invitations", ['phone' => '92345678', 'role' => 'member'])->assertCreated();
    $id = DB::table('organization_invitations')->where('organization_id', $org)->value('id');

    $other = customerToken($this, '93456789');
    asUser($this, $other)->getJson(api().'/me/invitations')->assertJsonCount(0, 'data');
    asUser($this, $other)->postJson(api()."/me/invitations/{$id}/accept")->assertNotFound();

    $invitee = customerToken($this, '92345678');
    DB::table('organization_invitations')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
    asUser($this, $invitee)->postJson(api()."/me/invitations/{$id}/accept")->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_CLOSED');

    DB::table('organization_invitations')->where('id', $id)->update(['expires_at' => now()->addDay()]);
    asUser($this, $invitee)->postJson(api()."/me/invitations/{$id}/accept")->assertOk();
    asUser($this, $invitee)->postJson(api()."/me/invitations/{$id}/accept")->assertStatus(409);
});

it('replaces a pending invitation to the same number and can revoke it', function () {
    [$owner, $org] = company($this);
    $send = fn () => asUser($this, $owner)->postJson(api()."/me/organizations/{$org}/invitations", ['phone' => '92345678', 'role' => 'member'])->assertCreated();
    $send();
    $id = $send()->assertJsonCount(1, 'data.invitations')->json('data.invitations.0.id');

    asUser($this, $owner)->deleteJson(api()."/me/organizations/{$org}/invitations/{$id}")->assertOk()->assertJsonCount(0, 'data.invitations');
});

it('keeps member management to owners and hides phones from members', function () {
    [$owner, $org] = company($this);
    $member = join_company($this, $owner, $org, '92345678');
    $url = api()."/me/organizations/{$org}";

    asUser($this, $member)->postJson("{$url}/invitations", ['phone' => '93456789', 'role' => 'member'])->assertForbidden();
    asUser($this, $member)->patchJson($url, ['preapproval_limit' => '500'])->assertForbidden();
    asUser($this, $member)->patchJson("{$url}/members/".userId('92345678'), ['role' => 'owner', 'can_approve_quotes' => true])->assertForbidden();
    asUser($this, $member)->deleteJson("{$url}/members/".userId('91234567'))->assertForbidden();

    $seen = asUser($this, $member)->getJson($url)->assertOk()->json('data');
    expect(array_filter(array_column($seen['members'], 'phone')))->toBe([])->and($seen['invitations'])->toBe([]);
});

it('hides a company from non-members', function () {
    [, $org] = company($this);
    $outsider = customerToken($this, '92345678');
    $url = api()."/me/organizations/{$org}";

    asUser($this, $outsider)->getJson($url)->assertNotFound();
    asUser($this, $outsider)->patchJson($url, ['name' => 'x'])->assertNotFound();
    asUser($this, $outsider)->postJson("{$url}/invitations", ['phone' => '93456789', 'role' => 'member'])->assertNotFound();
    asUser($this, $outsider)->deleteJson("{$url}/members/".userId('92345678'))->assertNotFound();
});

it('refuses to remove or demote the only owner (US-09)', function () {
    [$owner, $org] = company($this);
    join_company($this, $owner, $org, '92345678');
    $me = userId('91234567');

    asUser($this, $owner)->deleteJson(api()."/me/organizations/{$org}/members/{$me}")->assertStatus(409)->assertJsonPath('error.code', 'LAST_OWNER');
    asUser($this, $owner)->patchJson(api()."/me/organizations/{$org}/members/{$me}", ['role' => 'member', 'can_approve_quotes' => false])
        ->assertStatus(409)->assertJsonPath('error.code', 'LAST_OWNER');

    asUser($this, $owner)->patchJson(api()."/me/organizations/{$org}/members/".userId('92345678'), ['role' => 'owner', 'can_approve_quotes' => false])->assertOk();
    expect((bool) DB::table('organization_members')->where('user_id', userId('92345678'))->value('can_approve_quotes'))->toBeTrue();
    asUser($this, $owner)->deleteJson(api()."/me/organizations/{$org}/members/{$me}")->assertNoContent();
});

it('removes access at once, revokes their invitations, and allows joining again', function () {
    [$owner, $org] = company($this);
    $second = join_company($this, $owner, $org, '92345678', 'owner');
    asUser($this, $second)->postJson(api()."/me/organizations/{$org}/invitations", ['phone' => '93456789', 'role' => 'owner'])->assertCreated();

    asUser($this, $owner)->deleteJson(api()."/me/organizations/{$org}/members/".userId('92345678'))->assertNoContent();

    asUser($this, $second)->getJson(api()."/me/organizations/{$org}")->assertNotFound();
    expect(DB::table('organization_invitations')->where('phone_e164', '+96893456789')->value('status'))->toBe('revoked');

    $again = join_company($this, $owner, $org, '92345678');
    asUser($this, $again)->getJson(api()."/me/organizations/{$org}")->assertOk()->assertJsonPath('data.my_role', 'member');
});

it('lets the owner set the maintenance pre-approval limit (BR-06)', function () {
    [$owner, $org] = company($this);

    asUser($this, $owner)->patchJson(api()."/me/organizations/{$org}", ['preapproval_limit' => '50'])
        ->assertOk()->assertJsonPath('data.preapproval_limit.amount', '50.000');
    expect(DB::table('audit_logs')->where(['action' => 'organization.updated', 'entity_id' => $org])->count())->toBe(1);
});

it('does not write an invited phone to the append-only audit log', function () {
    [$owner, $org] = company($this);
    asUser($this, $owner)->postJson(api()."/me/organizations/{$org}/invitations", ['phone' => '93456789', 'role' => 'member'])->assertCreated();

    $row = DB::table('audit_logs')->where(['action' => 'organization.member_invited', 'entity_id' => $org])->first();
    expect($row->before.$row->after)->not->toContain('93456789');
});

it('requires a completed profile before any account change, and a customer account at all', function () {
    $sms = app(SmsSender::class);
    $this->postJson(api().'/auth/otp/request', ['phone' => '97654321'])->assertStatus(202);
    preg_match('/\d{6}/', collect($sms->sent)->last()['text'], $m);
    $fresh = $this->postJson(api().'/auth/otp/verify', ['phone' => '97654321', 'code' => $m[0], 'client' => 'web'])->json('data.access_token');
    asUser($this, $fresh)->postJson(api().'/me/organizations', ['name' => 'س', 'governorate' => 'muscat'])
        ->assertForbidden()->assertJsonPath('error.code', 'ONBOARDING_REQUIRED');

    asUser($this, staffToken($this, 'sales'))->getJson(api().'/me/organizations')->assertForbidden();
});
