<?php

use App\Models\User;
use App\Modules\Content\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// قواعد الرؤية وسلامة الحساب قبل فتح دعوات الشركات (03 §13.1، 02 §10، FR-AUTH-04)

function joinCompanyOf(User $owner, User $member): string
{
    $org = DB::table('organization_members')->where('user_id', $owner->id)->value('organization_id');
    DB::table('organization_members')->insert([
        'organization_id' => $org, 'user_id' => $member->id, 'role' => 'member', 'can_approve_quotes' => false, 'joined_at' => now(),
    ]);

    return $org;
}

function removedMemberWithRequest($test): array
{
    $ownerToken = customerToken($test, '91234567', 'شركة الخوض');
    $memberToken = customerToken($test, '92345678');
    $owner = User::query()->where('phone_e164', '+96891234567')->firstOrFail();
    $member = User::query()->where('phone_e164', '+96892345678')->firstOrFail();
    $org = joinCompanyOf($owner, $member);
    $rfq = asUser($test, $memberToken)->postJson(api().'/me/rfqs', [
        'type' => 'parts', 'organization_id' => $org, 'items' => [['free_text' => 'مضخة', 'quantity' => 1]],
    ])->assertCreated()->json('data');
    DB::table('organization_members')->where('user_id', $member->id)->update(['removed_at' => now()]);

    return compact('ownerToken', 'memberToken', 'owner', 'member', 'rfq');
}

it('sends a company quote to the owner and never to a member who was removed', function () {
    $x = removedMemberWithRequest($this);
    $s = staffToken($this, 'sales');
    $draft = asUser($this, $s)->postJson(api()."/admin/rfqs/{$x['rfq']['id']}/quotations")->assertCreated()->json('data');
    $lines = $draft['items'];
    $lines[0]['unit_price'] = '120.000';
    asUser($this, $s)->putJson(api()."/admin/quotations/{$draft['id']}", ['items' => $lines])->assertOk();
    asUser($this, $s)->postJson(api()."/admin/quotations/{$draft['id']}/send")->assertOk();

    $got = fn (User $u) => DB::table('notifications')->where(['user_id' => $u->id, 'event_code' => 'N-02'])->count();
    expect($got($x['owner']))->toBe(1)->and($got($x['member']))->toBe(0);
});

it('sends a staff message to the owner and never to a member who was removed', function () {
    $x = removedMemberWithRequest($this);
    $s = staffToken($this, 'sales');
    asUser($this, $s)->postJson(api()."/admin/rfqs/{$x['rfq']['id']}/messages", ['id' => (string) Str::uuid7(), 'body' => 'السعر 120'])->assertCreated();

    $got = fn (User $u) => DB::table('notifications')->where(['user_id' => $u->id, 'event_code' => 'N-17'])->count();
    expect($got($x['owner']))->toBe(1)->and($got($x['member']))->toBe(0);
});

it('lets a company member keep a personal request away from the company', function () {
    $ownerToken = customerToken($this, '91234567', 'شركة الخوض');
    $memberToken = customerToken($this, '92345678');
    joinCompanyOf(User::query()->where('phone_e164', '+96891234567')->firstOrFail(), User::query()->where('phone_e164', '+96892345678')->firstOrFail());

    $id = asUser($this, $memberToken)->postJson(api().'/me/rfqs', [
        'type' => 'parts', 'organization_id' => null, 'items' => [['free_text' => 'فلتر لسيارتي', 'quantity' => 1]],
    ])->assertCreated()->json('data.id');

    asUser($this, $ownerToken)->getJson(api()."/me/rfqs/{$id}")->assertNotFound();
});

it('refuses a request in the name of a company the customer does not belong to', function () {
    customerToken($this, '91234567', 'شركة الخوض');
    $outsider = customerToken($this, '92345678');
    $org = DB::table('organization_members')->value('organization_id');

    asUser($this, $outsider)->postJson(api().'/me/rfqs', [
        'type' => 'parts', 'organization_id' => $org, 'items' => [['free_text' => 'فلتر', 'quantity' => 1]],
    ])->assertStatus(422);
});

it('searches requests by name without matching every phone', function () {
    $c = customerToken($this);
    asUser($this, $c)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['free_text' => 'فلتر', 'quantity' => 1]]])->assertCreated();

    asUser($this, staffToken($this, 'sales'))->getJson(api().'/admin/rfqs?q='.urlencode('لا يوجد'))->assertOk()->assertJsonCount(0, 'data');
});

it('searches enquiries by name without matching every phone', function () {
    Lead::query()->create(['name' => 'سالم', 'phone' => '+96891234567', 'topic' => 'parts', 'message' => 'أحتاج فلتراً', 'status' => 'new']);

    asUser($this, staffToken($this, 'sales'))->getJson(api().'/admin/leads?q='.urlencode('لا يوجد'))->assertOk()->assertJsonCount(0, 'data');
});

it('does not send a password link to an email the customer never proved', function () {
    Notification::fake();
    $t = customerToken($this);
    asUser($this, $t)->patchJson(api().'/me', ['email' => 'typo@example.com'])->assertOk();

    $this->postJson(api().'/auth/password/forgot', ['email' => 'typo@example.com'])->assertStatus(202);

    Notification::assertNothingSent();
});
