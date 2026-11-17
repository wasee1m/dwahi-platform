<?php

use App\Modules\Content\Models\Lead;

// FR-CMS-01، FR-CMS-03، FR-CMS-05، US-05، NFR-09

it('serves published pages, FAQs and branches', function () {
    $this->getJson(api().'/public/pages/about')->assertOk()->assertJsonPath('data.title', 'من نحن');
    $this->getJson(api().'/public/faqs')->assertOk()->assertJsonCount(6, 'data');
    $this->getJson(api().'/public/branches')->assertOk()
        ->assertJsonPath('data.0.code', 'SOH')
        ->assertJsonPath('data.0.latitude', 24.347)
        ->assertJsonPath('data.0.longitude', 56.709);
});

it('stores a lead and normalises a local Omani mobile to E.164', function () {
    $this->postJson(api().'/public/leads', [
        'name' => 'أحمد', 'phone' => '9123 4567', 'topic' => 'parts', 'message' => 'أحتاج فلتر', 'branch_code' => 'SOH',
    ])->assertCreated();

    $lead = Lead::query()->sole();
    expect($lead->phone)->toBe('+96891234567')->and($lead->status)->toBe('new')->and($lead->branch_id)->not->toBeNull();
});

it('accepts international numbers', function () {
    $this->postJson(api().'/public/leads', ['name' => 'Sami', 'phone' => '00963 944 123 456', 'topic' => 'equipment', 'message' => 'Export'])
        ->assertCreated();
    expect(Lead::query()->sole()->phone)->toBe('+963944123456');
});

it('rejects invalid input with Arabic field messages', function () {
    $res = $this->postJson(api().'/public/leads', ['name' => '', 'phone' => '123', 'topic' => 'x', 'message' => ''])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    expect(array_keys($res->json('error.details')))->toEqualCanonicalizing(['name', 'phone', 'topic', 'message'])
        ->and($res->json('error.details.phone.0'))->toContain('رقم جوال صحيحاً');
});

it('pretends to accept bots that fill the hidden field, without saving', function () {
    $this->postJson(api().'/public/leads', ['name' => 'Bot', 'phone' => '91234567', 'topic' => 'other', 'message' => 'spam', 'website' => 'http://spam'])
        ->assertCreated();
    expect(Lead::query()->count())->toBe(0);
});

it('rate-limits the contact form (NFR-09)', function () {
    $body = ['name' => 'A', 'phone' => '91234567', 'topic' => 'other', 'message' => 'm'];
    foreach (range(1, 3) as $_) {
        $this->postJson(api().'/public/leads', $body)->assertCreated();
    }
    $this->postJson(api().'/public/leads', $body)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});
