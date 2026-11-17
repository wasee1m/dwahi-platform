<?php

use App\Modules\Catalog\Models\EquipmentListing;
use Illuminate\Database\QueryException;

// FR-CAT-01، FR-CAT-02، FR-CAT-03، US-01، US-02

it('lists only published and reserved equipment, with money as a 3-decimal string', function () {
    EquipmentListing::query()->where('slug', 'cat-950h-2008')->update(['status' => 'sold']);

    $res = $this->getJson(api().'/equipment?limit=48')->assertOk();

    $statuses = collect($res->json('data'))->pluck('status')->unique()->sort()->values()->all();
    expect($statuses)->toBe(['published', 'reserved'])
        ->and(collect($res->json('data'))->pluck('slug'))->not->toContain('cat-950h-2008')
        ->and($res->json('total'))->toBe(8);

    $bl71 = collect($res->json('data'))->firstWhere('slug', 'volvo-bl71-2012');
    expect($bl71['price'])->toBe(['amount' => '14500.000', 'currency' => 'OMR']);
});

it('hides the price when it is "on request" (BR-03)', function () {
    $res = $this->getJson(api().'/equipment/volvo-ec210-2010')->assertOk();
    expect($res->json('data.price'))->toBeNull();
});

it('filters by kind and sorts by price with "on request" last', function () {
    $res = $this->getJson(api().'/equipment?kind=machine&sort=price_desc')->assertOk();
    $prices = collect($res->json('data'))->map(fn ($e) => $e['price']['amount'] ?? null)->all();

    expect($prices)->toBe(['21000.000', '19500.000', '14500.000', null, null]);
});

it('pages with an opaque cursor', function () {
    $first = $this->getJson(api().'/equipment?limit=5')->assertOk();
    $second = $this->getJson(api().'/equipment?limit=5&cursor='.$first->json('next_cursor'))->assertOk();

    expect($first->json('data'))->toHaveCount(5)
        ->and($second->json('data'))->toHaveCount(4) // 9 معروضة: 5 آليات و 3 مكوّنات و 1 سكراب
        ->and($second->json('next_cursor'))->toBeNull()
        ->and(array_intersect(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        ))->toBeEmpty();
});

it('counts each facet without its own filter', function () {
    $res = $this->getJson(api().'/equipment/facets?kind=component')->assertOk();
    $kinds = collect($res->json('kinds'))->pluck('count', 'value')->all();

    expect($kinds)->toBe(['machine' => 5, 'component' => 3, 'scrap' => 1]);
});

it('answers in the requested language', function () {
    $this->getJson(api().'/equipment/volvo-bl71-2012', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('data.title', 'Volvo BL71 backhoe loader, 2012')
        ->assertJsonPath('data.category.name', 'Backhoe loaders');
});

it('returns the unified error format with a trace id (04 §4.2)', function () {
    $res = $this->getJson(api().'/equipment/nope')->assertNotFound()->assertHeader('X-Trace-Id');
    expect($res->json('error.code'))->toBe('NOT_FOUND')
        ->and($res->json('error.trace_id'))->toBe($res->headers->get('X-Trace-Id'));

    $this->getJson(api().'/equipment?kind=boat')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('enforces the listing rules in the database itself (BR-03)', function () {
    // سكراب يجب أن تكون حالته for_parts
    EquipmentListing::query()->where('slug', 'komatsu-pc200-scrap')->update(['condition' => 'used']);
})->throws(QueryException::class, 'equipment_listings_scrap_chk');
