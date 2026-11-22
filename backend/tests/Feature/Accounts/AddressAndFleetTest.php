<?php

use App\Models\User;
use App\Modules\Parts\Models\Part;
use Illuminate\Support\Facades\DB;

// FR-ACC-04، FR-ACC-05، FR-PRT-02، US-04، US-08، US-10، 03 §13.1

function addressInput(array $over = []): array
{
    return $over + [
        'label' => 'مستودع الخوض', 'kind' => 'both', 'governorate' => 'muscat', 'wilayat' => 'السيب',
        'description' => 'خلف المحطة', 'latitude' => 23.6, 'longitude' => 58.2, 'contact_name' => 'سالم', 'contact_phone' => '9555 4444',
    ];
}

function memberOfCompany($test): array
{
    $owner = customerToken($test, '91234567', 'شركة الخوض');
    $member = customerToken($test, '92345678');
    $org = DB::table('organization_members')->where('user_id', User::query()->where('phone_e164', '+96891234567')->valueOrFail('id'))->value('organization_id');
    $id = User::query()->where('phone_e164', '+96892345678')->valueOrFail('id');
    DB::table('organization_members')->insert(['organization_id' => $org, 'user_id' => $id, 'role' => 'member', 'can_approve_quotes' => false, 'joined_at' => now()]);

    return [$owner, $member, $org, $id];
}

it('saves an address with its pin in the right axis order (US-08)', function () {
    $t = customerToken($this);

    asUser($this, $t)->postJson(api().'/me/addresses', addressInput())->assertCreated()
        ->assertJsonPath('data.latitude', 23.6)->assertJsonPath('data.longitude', 58.2)
        ->assertJsonPath('data.contact_phone', '+96895554444')->assertJsonPath('data.account', null);
});

it('accepts an address without a pin, and can add and clear one later', function () {
    $t = customerToken($this);
    $id = asUser($this, $t)->postJson(api().'/me/addresses', addressInput(['latitude' => null, 'longitude' => null]))
        ->assertCreated()->assertJsonPath('data.latitude', null)->json('data.id');

    asUser($this, $t)->patchJson(api()."/me/addresses/{$id}", ['latitude' => 24.3, 'longitude' => 56.7])->assertOk()->assertJsonPath('data.latitude', 24.3);
    asUser($this, $t)->patchJson(api()."/me/addresses/{$id}", ['latitude' => null, 'longitude' => null])->assertOk()->assertJsonPath('data.latitude', null);
});

it('copies an address into a request, so archiving it changes nothing there (BR-17)', function () {
    $t = customerToken($this);
    $address = asUser($this, $t)->postJson(api().'/me/addresses', addressInput())->json('data.id');
    $rfq = asUser($this, $t)->postJson(api().'/me/rfqs', [
        'type' => 'parts', 'delivery_address_id' => $address, 'items' => [['free_text' => 'فلتر', 'quantity' => 1]],
    ])->assertCreated()->json('data');
    expect($rfq['delivery_address_text'])->toContain('مستودع الخوض')->toContain('مسقط')->toContain('السيب');

    asUser($this, $t)->deleteJson(api()."/me/addresses/{$address}")->assertNoContent();

    asUser($this, $t)->getJson(api().'/me/addresses')->assertJsonCount(0, 'data');
    asUser($this, $t)->getJson(api()."/me/rfqs/{$rfq['id']}")->assertJsonPath('data.delivery_address_text', $rfq['delivery_address_text']);
});

it('adds fleet equipment from the catalog with its last reading (US-10)', function () {
    $t = customerToken($this);
    $model = DB::table('equipment_models')->value('id');

    $e = asUser($this, $t)->postJson(api().'/me/fleet', ['model_id' => $model, 'serial_number' => 'CAT0320D123', 'year' => 2015, 'hour_meter' => 8200])
        ->assertCreated()->assertJsonPath('data.hour_meter', 8200)->assertJsonPath('data.model_id', $model)->json('data');

    expect($e['hour_meter_at'])->not->toBeNull()
        ->and(asUser($this, $t)->getJson(api()."/me/fleet/{$e['id']}/history")->json('data.0.reading'))->toBe(8200);
});

it('accepts a model that is not in the catalog, but never no model at all', function () {
    $t = customerToken($this);

    $id = asUser($this, $t)->postJson(api().'/me/fleet', ['brand_text' => 'XCMG', 'model_text' => 'XE215C'])
        ->assertCreated()->assertJsonPath('data.machine', 'XCMG XE215C')->json('data.id');
    asUser($this, $t)->postJson(api().'/me/fleet', ['nickname' => 'الحفار'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['model_text']]]);
    asUser($this, $t)->patchJson(api()."/me/fleet/{$id}", ['model_text' => null])->assertStatus(422);
});

it('warns on a lower reading and takes it only with confirmation and a note', function () {
    $t = customerToken($this);
    $id = asUser($this, $t)->postJson(api().'/me/fleet', ['model_text' => 'D6', 'hour_meter' => 9000])->json('data.id');
    $url = api()."/me/fleet/{$id}/hour-readings";

    asUser($this, $t)->postJson($url, ['reading' => 100])->assertStatus(409)
        ->assertJsonPath('error.code', 'HOUR_METER_LOWER')->assertJsonPath('error.details.previous', 9000);
    asUser($this, $t)->postJson($url, ['reading' => 100, 'confirm_lower' => true])->assertStatus(422);
    asUser($this, $t)->postJson($url, ['reading' => 100, 'confirm_lower' => true, 'note' => 'تبديل العداد'])
        ->assertCreated()->assertJsonPath('data.hour_meter', 100);

    expect((bool) DB::table('hour_meter_readings')->where(['customer_equipment_id' => $id, 'reading' => 100])->value('needs_review'))->toBeTrue();
});

it('shares company records with members until they are removed (03 §13.1)', function () {
    [$owner, $member, $org, $memberId] = memberOfCompany($this);
    $machine = asUser($this, $owner)->postJson(api().'/me/fleet', ['organization_id' => $org, 'model_text' => 'PC200'])->assertCreated()->json('data.id');
    $address = asUser($this, $owner)->postJson(api().'/me/addresses', addressInput(['organization_id' => $org]))->assertCreated()->json('data.id');

    asUser($this, $member)->getJson(api()."/me/fleet/{$machine}")->assertOk();
    asUser($this, $member)->getJson(api().'/me/addresses')->assertJsonPath('data.0.id', $address);

    DB::table('organization_members')->where('user_id', $memberId)->update(['removed_at' => now()]);

    asUser($this, $member)->getJson(api()."/me/fleet/{$machine}")->assertNotFound();
    asUser($this, $member)->getJson(api().'/me/addresses')->assertJsonCount(0, 'data');
});

it('keeps a member personal fleet out of the company', function () {
    [$owner, $member] = memberOfCompany($this);
    $id = asUser($this, $member)->postJson(api().'/me/fleet', ['model_text' => 'جرار شخصي'])->assertCreated()->json('data.id');

    asUser($this, $owner)->getJson(api()."/me/fleet/{$id}")->assertNotFound();
    asUser($this, $owner)->getJson(api().'/me/fleet')->assertJsonCount(0, 'data');
});

it('answers 404 to another customer on every fleet and address route', function () {
    $mine = customerToken($this);
    $machine = asUser($this, $mine)->postJson(api().'/me/fleet', ['model_text' => 'D6'])->json('data.id');
    $address = asUser($this, $mine)->postJson(api().'/me/addresses', addressInput())->json('data.id');
    $other = customerToken($this, '92345678');

    foreach ([
        ['getJson', "/me/fleet/{$machine}", []], ['patchJson', "/me/fleet/{$machine}", ['nickname' => 'x']],
        ['deleteJson', "/me/fleet/{$machine}", []], ['postJson', "/me/fleet/{$machine}/hour-readings", ['reading' => 5]],
        ['getJson', "/me/fleet/{$machine}/history", []],
        ['patchJson', "/me/addresses/{$address}", ['label' => 'x']], ['deleteJson', "/me/addresses/{$address}", []],
    ] as [$verb, $path, $body]) {
        $req = asUser($this, $other);
        ($verb === 'getJson' ? $req->getJson(api().$path) : $req->{$verb}(api().$path, $body))->assertNotFound();
    }
});

it('does not mix accounts in a request, a site or a new record', function () {
    [$owner, , $org] = memberOfCompany($this);
    $outsider = customerToken($this, '93456789');
    $companyMachine = asUser($this, $owner)->postJson(api().'/me/fleet', ['organization_id' => $org, 'model_text' => 'PC200'])->json('data.id');
    $companyAddress = asUser($this, $owner)->postJson(api().'/me/addresses', addressInput(['organization_id' => $org]))->json('data.id');
    $items = [['free_text' => 'فلتر', 'quantity' => 1]];

    asUser($this, $owner)->postJson(api().'/me/rfqs', ['type' => 'parts', 'organization_id' => null, 'customer_equipment_id' => $companyMachine, 'items' => $items])->assertStatus(422);
    asUser($this, $owner)->postJson(api().'/me/rfqs', ['type' => 'parts', 'organization_id' => null, 'delivery_address_id' => $companyAddress, 'items' => $items])->assertStatus(422);
    asUser($this, $owner)->postJson(api().'/me/fleet', ['model_text' => 'D6', 'site_address_id' => $companyAddress])->assertStatus(422);
    asUser($this, $outsider)->postJson(api().'/me/fleet', ['model_text' => 'D6', 'organization_id' => $org])->assertStatus(422);
    asUser($this, $outsider)->postJson(api().'/me/addresses', addressInput(['organization_id' => $org]))->assertStatus(422);
});

it('filters by the selected account', function () {
    [$owner, , $org] = memberOfCompany($this);
    asUser($this, $owner)->postJson(api().'/me/fleet', ['model_text' => 'شخصية'])->assertCreated();
    asUser($this, $owner)->postJson(api().'/me/fleet', ['model_text' => 'للشركة', 'organization_id' => $org])->assertCreated();

    asUser($this, $owner)->getJson(api().'/me/fleet')->assertJsonCount(2, 'data');
    asUser($this, $owner)->getJson(api().'/me/fleet?account=personal')->assertJsonCount(1, 'data')->assertJsonPath('data.0.model', 'شخصية');
    asUser($this, $owner)->getJson(api()."/me/fleet?account={$org}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.model', 'للشركة');
});

it('lists the parts of a fleet model grouped by category (US-04)', function () {
    $model = DB::table('part_compatibility')->value('model_id');
    $expected = Part::query()->where('is_active', true)->whereHas('compatibleModels', fn ($m) => $m->where('equipment_models.id', $model))->count();

    $data = $this->getJson(api()."/parts/compatible?model_id={$model}")->assertOk()->json('data');

    expect($data['model']['id'])->toBe($model)
        ->and(collect($data['groups'])->sum(fn ($g) => count($g['parts'])))->toBe($expected);
    $this->getJson(api().'/public/models')->assertOk()->assertJsonFragment(['id' => $model]);
});

it('names a known part category in the customer language', function () {
    $model = DB::table('part_compatibility as c')->join('parts as p', 'p.id', '=', 'c.part_id')->where('p.category', 'filters')->value('c.model_id');
    $names = fn (string $lang) => collect($this->withHeader('Accept-Language', $lang)->getJson(api()."/parts/compatible?model_id={$model}")->json('data.groups'))->pluck('category');

    expect($names('ar'))->toContain('فلاتر')->and($names('en'))->toContain('Filters');
});

it('links a parts request to fleet equipment and shows it in its history (US-04)', function () {
    $t = customerToken($this);
    $machine = asUser($this, $t)->postJson(api().'/me/fleet', ['model_text' => '950GC'])->json('data.id');

    $rfq = asUser($this, $t)->postJson(api().'/me/rfqs', ['type' => 'parts', 'customer_equipment_id' => $machine, 'items' => [['free_text' => 'طقم فلاتر', 'quantity' => 1]]])
        ->assertCreated()->assertJsonPath('data.equipment.id', $machine)->json('data');

    asUser($this, $t)->getJson(api()."/me/fleet/{$machine}/history")->assertJsonPath('data.0.number', $rfq['number']);
});
