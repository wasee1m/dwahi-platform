<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// FR-ADM-05، US-45، SM-7، 01 §3.2

function customerWithCompany($test): array
{
    $token = customerToken($test, '91234567', 'شركة الخوض');
    $id = User::query()->where('phone_e164', '+96891234567')->valueOrFail('id');
    $org = DB::table('organization_members')->where('user_id', $id)->value('organization_id');
    DB::table('organizations')->where('id', $org)->update(['cr_number' => '1122334']);

    return [$token, $id, $org];
}

it('finds a customer by phone, name, company or CR number (US-45)', function (string $q) {
    [, $id] = customerWithCompany($this);
    customerToken($this, '92345678');

    asUser($this, staffToken($this, 'sales'))->getJson(api().'/admin/customers?q='.urlencode($q))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
})->with(['9123 4567', '+968 91234567', 'عميل 91234567', 'الخوض', '1122334']);

it('finds a company by name, CR number or a member phone', function (string $q) {
    [, , $org] = customerWithCompany($this);

    asUser($this, staffToken($this, 'sales'))->getJson(api().'/admin/organizations?q='.urlencode($q))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $org)->assertJsonPath('data.0.members_count', 1);
})->with(['الخوض', '1122334', '91234567']);

it('shows the whole profile in one call (US-45)', function () {
    [$token, $id, $org] = customerWithCompany($this);
    asUser($this, $token)->postJson(api().'/me/fleet', ['model_text' => 'شخصية'])->assertCreated();
    asUser($this, $token)->postJson(api().'/me/fleet', ['model_text' => 'للشركة', 'organization_id' => $org])->assertCreated();
    $rfq = asUser($this, $token)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['free_text' => 'مضخة', 'quantity' => 1]]])->json('data');
    asUser($this, $token)->postJson(api()."/me/rfqs/{$rfq['id']}/messages", ['id' => (string) Str::uuid7(), 'body' => 'متى العرض؟'])->assertCreated();
    $s = staffToken($this, 'sales');

    asUser($this, $s)->getJson(api()."/admin/customers/{$id}")->assertOk()
        ->assertJsonPath('data.organizations.0.id', $org)
        ->assertJsonPath('data.fleet.0.model', 'شخصية')
        ->assertJsonPath('data.requests.0.number', $rfq['number'])
        ->assertJsonPath('data.conversations.0.messages', 1);
    asUser($this, $s)->getJson(api()."/admin/organizations/{$org}")->assertOk()
        ->assertJsonPath('data.fleet.0.model', 'للشركة')
        ->assertJsonPath('data.members.0.phone', '+96891234567')
        ->assertJsonPath('data.can_manage', false);
});

it('keeps internal notes with their author and away from the customer', function () {
    [$token, $id, $org] = customerWithCompany($this);
    $s = staffToken($this, 'sales');

    asUser($this, $s)->postJson(api()."/admin/customers/{$id}/notes", ['body' => 'يفضل الاتصال مساءً'])->assertCreated()->assertJsonPath('data.0.body', 'يفضل الاتصال مساءً');
    asUser($this, $s)->postJson(api()."/admin/organizations/{$org}/notes", ['body' => 'ملاحظة الشركة'])->assertCreated();

    expect(asUser($this, $token)->getJson(api().'/me')->content())->not->toContain('يفضل')
        ->and(asUser($this, $token)->getJson(api()."/me/organizations/{$org}")->json('data'))->not->toHaveKey('notes');
});

it('lets the admin suspend with a reason, ending sessions and writing the audit (SM-7)', function () {
    [$token, $id] = customerWithCompany($this);
    $admin = staffToken($this, 'admin');
    $url = api()."/admin/customers/{$id}";

    asUser($this, $admin)->postJson("{$url}/suspend")->assertStatus(422);
    asUser($this, $admin)->postJson("{$url}/suspend", ['reason' => 'طلبات وهمية'])->assertOk()
        ->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.active_sessions', 0);
    asUser($this, $admin)->postJson("{$url}/suspend", ['reason' => 'مرة أخرى'])->assertStatus(409);

    asUser($this, $token)->getJson(api().'/me')->assertUnauthorized();
    expect(DB::table('audit_logs')->where(['action' => 'customer.suspended', 'entity_id' => $id])->count())->toBe(1);

    asUser($this, $admin)->postJson("{$url}/activate")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.suspended_reason', null);
});

it('never lets suspension or activation touch a deleted account', function () {
    [, $id] = customerWithCompany($this);
    DB::table('users')->where('id', $id)->update(['status' => 'deleted', 'deleted_at' => now()]);
    $admin = staffToken($this, 'admin');

    asUser($this, $admin)->postJson(api()."/admin/customers/{$id}/activate")->assertStatus(409);
    asUser($this, $admin)->postJson(api()."/admin/customers/{$id}/suspend", ['reason' => 'x'])->assertStatus(409);
});

it('does not expose staff accounts as customers', function () {
    $staff = staffUser(['content_editor'], 'editor@dwahi.test');
    $admin = staffToken($this, 'admin');

    asUser($this, $admin)->getJson(api()."/admin/customers/{$staff->id}")->assertNotFound();
    asUser($this, $admin)->postJson(api()."/admin/customers/{$staff->id}/suspend", ['reason' => 'x'])->assertNotFound();
});

it('follows the role matrix on every customer route (01 §3.2)', function (string $role, bool $view, bool $create, bool $manage) {
    [, $id, $org] = customerWithCompany($this);
    $t = staffToken($this, $role);
    $check = fn (string $verb, string $path, array $body, bool $allowed) => tap(
        $verb === 'get' ? asUser($this, $t)->getJson(api().$path) : asUser($this, $t)->postJson(api().$path, $body),
        fn ($res) => $allowed ? $res->assertSuccessful() : $res->assertForbidden(),
    );

    $check('get', '/admin/customers', [], $view);
    $check('get', "/admin/customers/{$id}", [], $view);
    $check('get', '/admin/organizations', [], $view);
    $check('get', "/admin/organizations/{$org}", [], $view);
    $check('post', "/admin/customers/{$id}/notes", ['body' => 'ملاحظة'], $view);
    $check('get', '/admin/customers/lookup?phone=91234567', [], $create);
    $check('post', '/admin/customers', ['phone' => '95556677', 'full_name' => 'جديد'], $create);
    $check('post', "/admin/organizations/{$org}/invitations", ['phone' => '93456789', 'role' => 'member'], $manage);
    $check('post', "/admin/customers/{$id}/suspend", ['reason' => 'سبب'], $manage);
})->with([
    ['admin', true, true, true],
    ['sales', true, true, false],
    ['service_coordinator', true, true, false],
    ['accountant', true, false, false],
    ['parts_staff', false, false, false],
    ['content_editor', false, false, false],
]);

it('refuses a request on behalf of a suspended customer', function () {
    [, $id] = customerWithCompany($this);
    DB::table('users')->where('id', $id)->update(['status' => 'suspended']);

    asUser($this, staffToken($this, 'sales'))->postJson(api().'/admin/rfqs', [
        'customer_id' => $id, 'source' => 'phone', 'type' => 'parts', 'items' => [['free_text' => 'فلتر', 'quantity' => 1]],
    ])->assertStatus(422);
});
