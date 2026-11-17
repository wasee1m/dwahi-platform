<?php

// FR-PRT-01، FR-PRT-03، BR-04، US-03

it('finds a part however its number is typed', function (string $q) {
    $res = $this->getJson(api().'/parts/search?q='.urlencode($q))->assertOk();
    expect($res->json('data.0.part_number'))->toBe('1R-0750');
})->with(['1R-0750', '1r0750', '1R 0750', '1R-07']);

it('matches alternative numbers and says so', function () {
    $res = $this->getJson(api().'/parts/search?q=SMP-0750')->assertOk();
    expect($res->json('data.0.part_number'))->toBe('1R-0750')
        ->and($res->json('data.0.matched_alternative'))->toBe('SMP-0750-A');
});

it('searches Arabic names by part of a word (FULLTEXT ngram)', function () {
    $res = $this->getJson(api().'/parts/search?q='.urlencode('هيدرو'))->assertOk();
    expect(collect($res->json('data'))->pluck('part_number')->sort()->values()->all())->toBe(['2XL-KIT', '3XL-KIT']);
});

it('shows availability as a level, never a quantity (BR-04)', function () {
    $levels = collect($this->getJson(api().'/parts/search?q=filter')->json('data'))
        ->pluck('availability', 'part_number')->all();

    expect($levels)->toMatchArray(['1R-0750' => 'available', '1R-1808' => 'limited', '600-211-1340' => 'on_request'])
        ->and(json_encode($this->getJson(api().'/parts/caterpillar/1R0750')->json()))->not->toContain('qty');
});

it('shows a part page with alternatives and compatible models', function () {
    $this->getJson(api().'/parts/caterpillar/1R-0750', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Fuel filter')
        ->assertJsonPath('data.price.amount', '6.500')
        ->assertJsonPath('data.alternatives.0.number', 'SMP-0750-A')
        ->assertJsonCount(2, 'data.compatible_models');
});
