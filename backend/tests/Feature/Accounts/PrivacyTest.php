<?php

use App\Models\User;
use App\Modules\Accounts\Jobs\BuildDataExport;
use App\Modules\Identity\Notifications\EmailCode;
use App\Modules\Identity\Notifications\SetPasswordLink;
use App\Modules\Identity\Sms\SmsSender;
use App\Modules\Notifications\Support\EventCatalog;
use App\Modules\Notifications\Support\Preferences;
use App\Modules\Platform\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// FR-ACC-06، FR-AUTH-04، FR-NOT-03، BR-16، J-08، US-11، US-23

beforeEach(fn () => Storage::fake('local'));

function customerByPhone(string $phone = '91234567'): User
{
    return User::query()->where('phone_e164', "+968{$phone}")->firstOrFail();
}

function deleteAccount($test, string $token)
{
    asUser($test, $token)->postJson(api().'/me/deletion/request')->assertStatus(202);
    preg_match('/\d{6}/', collect(app(SmsSender::class)->sent)->last()['text'], $m);

    return asUser($test, $token)->postJson(api().'/me/deletion', ['code' => $m[0]]);
}

// --- البريد وكلمة المرور والجلسات

it('verifies an email by code, then allows password recovery to it', function () {
    Notification::fake();
    $t = customerToken($this);
    asUser($this, $t)->patchJson(api().'/me', ['email' => 'salim@example.com'])->assertOk();
    asUser($this, $t)->postJson(api().'/me/email/request')->assertStatus(202)->assertJsonPath('data.debug_code', null);
    $user = customerByPhone();
    $code = Notification::sent($user, EmailCode::class)->first()->code;

    asUser($this, $t)->postJson(api().'/me/email/verify', ['code' => $code === '000000' ? '111111' : '000000'])->assertStatus(422);
    asUser($this, $t)->postJson(api().'/me/email/verify', ['code' => $code])->assertOk()->assertJsonPath('data.email_verified', true);

    $this->postJson(api().'/auth/password/forgot', ['email' => 'salim@example.com'])->assertStatus(202);
    Notification::assertSentTo($user, SetPasswordLink::class);
});

it('unverifies the email on any change, onboarding included', function () {
    $t = customerToken($this);
    customerByPhone()->forceFill(['email' => 'proved@example.com', 'email_verified_at' => now()])->save();

    asUser($this, $t)->postJson(api().'/me/onboarding', [
        'full_name' => 'سالم', 'account_type' => 'individual', 'email' => 'victim@example.com', 'accept_terms' => true, 'accept_privacy' => true,
    ])->assertOk()->assertJsonPath('data.email_verified', false);
});

it('does not accept a code sent to the previous email', function () {
    Notification::fake();
    $t = customerToken($this);
    asUser($this, $t)->patchJson(api().'/me', ['email' => 'one@example.com'])->assertOk();
    asUser($this, $t)->postJson(api().'/me/email/request')->assertStatus(202);
    $code = Notification::sent(customerByPhone(), EmailCode::class)->first()->code;

    asUser($this, $t)->patchJson(api().'/me', ['email' => 'two@example.com'])->assertOk();

    asUser($this, $t)->postJson(api().'/me/email/verify', ['code' => $code])->assertStatus(422);
});

it('changes a password only with the current one and ends other sessions', function () {
    $other = customerToken($this);
    $t = customerToken($this);
    customerByPhone()->forceFill(['email' => 'a@example.com', 'password_hash' => 'old password 123'])->save();
    $new = ['password' => 'new password 456', 'password_confirmation' => 'new password 456'];

    asUser($this, $t)->postJson(api().'/me/password', $new)->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['current_password']]]);
    asUser($this, $t)->postJson(api().'/me/password', $new + ['current_password' => 'old password 123'])->assertNoContent();

    asUser($this, $other)->getJson(api().'/me')->assertUnauthorized();
    asUser($this, $t)->getJson(api().'/me')->assertOk();
});

it('ends one session, or every session (FR-AUTH-04)', function () {
    $first = customerToken($this);
    $second = customerToken($this);
    $other = collect(asUser($this, $second)->getJson(api().'/me/sessions')->assertOk()->json('data'))->firstWhere('current', false);

    asUser($this, $second)->deleteJson(api()."/me/sessions/{$other['id']}")->assertNoContent();
    asUser($this, $first)->getJson(api().'/me')->assertUnauthorized();

    asUser($this, $second)->postJson(api().'/auth/logout-all')->assertNoContent();
    asUser($this, $second)->getJson(api().'/me')->assertUnauthorized();
});

// --- التفضيلات

it('shows mandatory channels locked and lets optional ones be turned off (US-23)', function () {
    $t = customerToken($this);
    $cells = collect(asUser($this, $t)->getJson(api().'/me/notification-preferences')->assertOk()->json('data.preferences'))
        ->keyBy(fn ($c) => "{$c['category']}:{$c['channel']}");

    expect($cells['orders:sms']['state'])->toBe('locked')
        ->and($cells['quotes:sms']['state'])->toBe('locked')
        ->and($cells['quotes:email']['state'])->toBe('editable')
        ->and($cells['quotes:email']['has_mandatory'])->toBeTrue()
        ->and($cells['messages:push']['state'])->toBe('editable')
        ->and($cells['messages:sms']['state'])->toBe('unused');

    $put = fn (string $cat, string $channel) => asUser($this, $t)->putJson(api().'/me/notification-preferences', [
        'preferences' => [['category' => $cat, 'channel' => $channel, 'enabled' => false]],
    ]);
    $put('orders', 'sms')->assertStatus(422);
    $put('messages', 'push')->assertOk();

    $id = customerByPhone()->id;
    expect(Preferences::enabled($id, 'messages', 'push'))->toBeFalse()
        ->and(Preferences::enabled($id, 'quotes', 'push'))->toBeTrue();
});

it('sends no marketing until the customer opts in (US-23)', function () {
    $t = customerToken($this);
    $id = customerByPhone()->id;
    expect(Preferences::enabled($id, 'marketing', 'sms'))->toBeFalse();

    asUser($this, $t)->putJson(api().'/me/marketing-consent', ['granted' => true])->assertOk()->assertJsonPath('data.marketing_consent', true);
    expect(Preferences::enabled($id, 'marketing', 'sms'))->toBeTrue();

    $this->travel(2)->seconds();
    asUser($this, $t)->putJson(api().'/me/marketing-consent', ['granted' => false])->assertOk()->assertJsonPath('data.marketing_consent', false);
    expect(DB::table('consents')->where(['user_id' => $id, 'kind' => 'marketing'])->count())->toBe(3);
});

it('keeps every event inside a known category', function () {
    foreach (EventCatalog::EVENTS as $event) {
        expect([...EventCatalog::CATEGORIES, 'account'])->toContain($event['category']);
    }
});

// --- تنزيل البيانات

it('queues an export once while one is pending', function () {
    Queue::fake();
    $t = customerToken($this);

    $first = asUser($this, $t)->postJson(api().'/me/data-export')->assertStatus(202)->assertJsonPath('data.status', 'pending')->json('data.id');
    asUser($this, $t)->postJson(api().'/me/data-export')->assertStatus(202)->assertJsonPath('data.id', $first);

    Queue::assertPushed(BuildDataExport::class, 1);
});

it('exports only the customer own data, with a temporary link and a notification', function () {
    $other = customerToken($this, '92345678', 'شركة سرية');
    asUser($this, $other)->postJson(api().'/me/fleet', ['model_text' => 'OTHER-MACHINE'])->assertCreated();
    $t = customerToken($this);
    asUser($this, $t)->postJson(api().'/me/fleet', ['model_text' => 'MY-MACHINE'])->assertCreated();
    asUser($this, $t)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['free_text' => 'MY-PART', 'quantity' => 1]]])->assertCreated();
    $me = customerByPhone();
    DB::table('customer_notes')->insert(['id' => (string) Str::uuid7(), 'user_id' => $me->id, 'author_id' => staffUser(['sales'], 's@dwahi.test')->id, 'body' => 'INTERNAL-NOTE', 'created_at' => now()]);

    asUser($this, $t)->postJson(api().'/me/data-export')->assertStatus(202);
    $export = asUser($this, $t)->getJson(api().'/me/data-export')->assertJsonPath('data.status', 'ready')->json('data');

    $json = Storage::disk('local')->get(Attachment::query()->findOrFail(DB::table('data_exports')->where('id', $export['id'])->value('attachment_id'))->storage_key);
    expect($json)->toContain('MY-MACHINE')->toContain('MY-PART')->toContain('+96891234567')
        ->not->toContain('OTHER-MACHINE')->not->toContain('+96892345678')->not->toContain('INTERNAL-NOTE')->not->toContain('password_hash')
        ->and($export['url'])->toContain('signature=')
        ->and(DB::table('notifications')->where(['user_id' => $me->id, 'event_code' => 'N-33'])->count())->toBe(1);
});

it('destroys an export after it expires', function () {
    $t = customerToken($this);
    asUser($this, $t)->postJson(api().'/me/data-export')->assertStatus(202);
    $key = Attachment::query()->findOrFail(DB::table('data_exports')->value('attachment_id'))->storage_key;
    DB::table('data_exports')->update(['expires_at' => now()->subMinute()]);

    asUser($this, $t)->getJson(api().'/me/data-export')->assertJsonPath('data.status', 'expired')->assertJsonPath('data.url', null);
    $this->artisan('accounts:run purge-exports')->assertSuccessful();

    Storage::disk('local')->assertMissing($key);
});

// --- حذف الحساب

it('asks the only owner of a company with members to transfer ownership first (US-11)', function () {
    $owner = customerToken($this, '91234567', 'شركة الخوض');
    customerToken($this, '92345678');
    $org = DB::table('organization_members')->where('user_id', customerByPhone()->id)->value('organization_id');
    DB::table('organization_members')->insert(['organization_id' => $org, 'user_id' => customerByPhone('92345678')->id, 'role' => 'member', 'can_approve_quotes' => true, 'joined_at' => now()]);

    asUser($this, $owner)->getJson(api().'/me/deletion')->assertJsonPath('data.can_delete', false)->assertJsonPath('data.companies.0.id', $org);
    asUser($this, $owner)->postJson(api().'/me/deletion/request')->assertStatus(409)->assertJsonPath('error.code', 'DELETION_BLOCKED');
});

it('lists open requests and refuses deletion until they close (US-11)', function () {
    $t = customerToken($this);
    $rfq = asUser($this, $t)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['free_text' => 'مضخة', 'quantity' => 1]]])->json('data');

    asUser($this, $t)->getJson(api().'/me/deletion')->assertJsonPath('data.requests.0.number', $rfq['number']);
    asUser($this, $t)->postJson(api().'/me/deletion/request')->assertStatus(409);

    asUser($this, $t)->postJson(api()."/me/rfqs/{$rfq['id']}/cancel")->assertOk();
    asUser($this, $t)->getJson(api().'/me/deletion')->assertJsonPath('data.can_delete', true)->assertJsonPath('data.grace_days', 30);
});

it('disables the account at once after the code, and blocks signing in', function () {
    $t = customerToken($this);
    asUser($this, $t)->postJson(api().'/me/deletion', ['code' => '123456'])->assertStatus(422);

    deleteAccount($this, $t)->assertNoContent();

    expect(customerByPhone()->status)->toBe('deleted')->and(customerByPhone()->anonymized_at)->toBeNull();
    asUser($this, $t)->getJson(api().'/me')->assertUnauthorized();

    $this->travel(16)->minutes(); // حد 3 رسائل لكل رقم كل 15 دقيقة
    $this->postJson(api().'/auth/otp/request', ['phone' => '91234567'])->assertStatus(202);
    preg_match('/\d{6}/', collect(app(SmsSender::class)->sent)->last()['text'], $m);
    $this->postJson(api().'/auth/otp/verify', ['phone' => '91234567', 'code' => $m[0], 'client' => 'web'])
        ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_DELETED');
});

it('revokes invitations to a company left without members', function () {
    $t = customerToken($this, '91234567', 'شركة فرد واحد');
    $org = DB::table('organization_members')->where('user_id', customerByPhone()->id)->value('organization_id');
    asUser($this, $t)->postJson(api()."/me/organizations/{$org}/invitations", ['phone' => '93456789', 'role' => 'member'])->assertCreated();

    deleteAccount($this, $t)->assertNoContent();

    expect(DB::table('organization_invitations')->where('organization_id', $org)->value('status'))->toBe('revoked');
});

it('anonymizes 30 days after deletion, not before, and only once (BR-16, J-08)', function () {
    $t = customerToken($this);
    $photo = asUser($this, $t)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->image('plate.jpg', 100, 100)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $machine = asUser($this, $t)->postJson(api().'/me/fleet', ['model_text' => 'D6', 'serial_number' => 'SN-1', 'nameplate_photo_id' => $photo])->json('data.id');
    asUser($this, $t)->postJson(api().'/me/addresses', ['label' => 'بيتي', 'kind' => 'delivery', 'governorate' => 'muscat', 'wilayat' => 'السيب', 'latitude' => 23.6, 'longitude' => 58.2])->assertCreated();
    $key = Attachment::query()->findOrFail($photo)->storage_key;
    $id = customerByPhone()->id;
    deleteAccount($this, $t)->assertNoContent();

    $this->travel(29)->days();
    $this->artisan('accounts:run anonymize')->expectsOutput('anonymize: 0')->assertSuccessful();

    $this->travel(2)->days();
    $this->artisan('accounts:run anonymize')->expectsOutput('anonymize: 1')->assertSuccessful();
    $this->artisan('accounts:run anonymize')->expectsOutput('anonymize: 0')->assertSuccessful();

    $user = User::query()->findOrFail($id);
    $address = DB::table('addresses')->where('user_id', $id)->first();
    expect($user->phone_e164)->toBeNull()
        ->and($user->full_name)->toBe('حساب محذوف')
        ->and($address->label)->toBe('—')
        ->and($address->location)->toBeNull()
        ->and(DB::table('customer_equipment')->where('id', $machine)->value('serial_number'))->toBeNull()
        ->and(DB::table('otp_challenges')->where('phone_e164', '+96891234567')->count())->toBe(0);
    Storage::disk('local')->assertMissing($key);
});

it('keeps company files and frees the number when anonymizing a member', function () {
    $owner = customerToken($this, '91234567', 'شركة الخوض');
    $member = customerToken($this, '92345678');
    $org = DB::table('organization_members')->where('user_id', customerByPhone()->id)->value('organization_id');
    DB::table('organization_members')->insert(['organization_id' => $org, 'user_id' => customerByPhone('92345678')->id, 'role' => 'member', 'can_approve_quotes' => false, 'joined_at' => now()]);
    $photo = asUser($this, $member)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->image('plate.jpg', 100, 100)], ['Accept' => 'application/json'])->json('data.id');
    asUser($this, $member)->postJson(api().'/me/fleet', ['organization_id' => $org, 'model_text' => 'PC200', 'nameplate_photo_id' => $photo])->assertCreated();
    deleteAccount($this, $member)->assertNoContent();

    $this->travel(31)->days();
    $this->artisan('accounts:run anonymize')->assertSuccessful();

    Storage::disk('local')->assertExists(Attachment::query()->findOrFail($photo)->storage_key);
    expect(User::query()->where('phone_e164', '+96892345678')->exists())->toBeFalse();
});
