<?php

use App\Modules\Content\Models\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// A-14 (FR-CMS-01، FR-CMS-03، FR-CMS-04، US-42، NFR-14)

beforeEach(fn () => Storage::fake('public'));

function pageBody(array $over = []): array
{
    return $over + [
        'title_ar' => 'من نحن', 'title_en' => 'About us', 'body_ar' => '## عنوان', 'body_en' => '## Heading', 'status' => 'published',
    ];
}

it('strips scripts and refuses to publish a page without English (US-42, NFR-14)', function () {
    $t = staffToken($this, 'content_editor');
    $id = Page::query()->where('slug', 'about')->value('id');

    $this->withToken($t)->putJson(api()."/admin/pages/{$id}", pageBody(['body_en' => '']))
        ->assertStatus(422)->assertJsonValidationErrors(['body_en'], 'error.details');

    // مسودة دون إنجليزي مقبولة، ولا تظهر في الموقع
    $this->withToken($t)->putJson(api()."/admin/pages/{$id}", pageBody(['body_en' => '', 'status' => 'draft']))->assertOk();
    $this->getJson(api().'/public/pages/about')->assertNotFound();

    $body = "## ورشة\n\n<script>alert('x')</script>نص **عريض** و<b>وسم</b> و 3 < 5 [رابط](javascript:alert(1))";
    $this->withToken($t)->putJson(api()."/admin/pages/{$id}", pageBody(['body_ar' => $body]))
        ->assertOk()->assertJsonPath('data.body_ar', "## ورشة\n\nنص **عريض** ووسم و 3 < 5 [رابط](alert(1))");
    $this->getJson(api().'/public/pages/about')->assertOk()->assertJsonPath('data.title', 'من نحن');

    // المعرّف ثابت بعد الإنشاء
    $this->withToken($t)->putJson(api()."/admin/pages/{$id}", pageBody(['slug' => 'other']))->assertStatus(422);
    expect(DB::table('audit_logs')->where('action', 'page.updated')->where('entity_id', $id)->count())->toBe(2);
});

it('shows a banner only inside its period (US-42)', function () {
    $this->travelTo('2026-11-25 08:00:00'); // قبل الدخول: رمز الوصول صالح 15 دقيقة
    $t = staffToken($this, 'content_editor');
    $img = fn () => $this->withToken($t)->post(api().'/admin/uploads', ['file' => UploadedFile::fake()->image('b.jpg', 1600, 500)], ['Accept' => 'application/json'])->json('data.id');

    $this->withToken($t)->postJson(api().'/admin/banners', [
        'placement' => 'parts', 'image_ar_id' => $img(), 'image_en_id' => $img(), 'link' => '/ar/parts?q=filter',
        'starts_at' => '2026-12-01T00:00+04:00', 'ends_at' => '2026-12-15T23:59+04:00',
    ])->assertCreated()->assertJsonPath('data.state', 'scheduled')->assertJsonPath('data.starts_at', '2026-11-30T20:00:00Z');

    $this->getJson(api().'/public/banners?placement=parts')->assertOk()->assertJsonCount(0, 'data');
    $this->travelTo('2026-12-05 10:00:00');
    $this->getJson(api().'/public/banners?placement=parts')->assertOk()->assertJsonCount(1, 'data');
    $this->travelTo('2026-12-16 10:00:00');
    $this->getJson(api().'/public/banners?placement=parts')->assertOk()->assertJsonCount(0, 'data');

    $this->travelTo('2026-11-25 08:05:00');
    $this->withToken($t)->postJson(api().'/admin/banners', [
        'placement' => 'parts', 'image_ar_id' => $img(), 'image_en_id' => $img(), 'link' => 'javascript:alert(1)',
        'starts_at' => '2026-12-10', 'ends_at' => '2026-12-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['link', 'ends_at'], 'error.details');
});

it('adds a FAQ at the end of its section and reorders the section', function () {
    $t = staffToken($this, 'content_editor');
    $new = $this->withToken($t)->postJson(api().'/admin/faqs', [
        'section' => 'parts', 'question_ar' => 'س', 'question_en' => 'Q', 'answer_ar' => 'ج', 'answer_en' => 'A',
    ])->assertCreated()->json('data');

    $parts = collect($this->withToken($t)->getJson(api().'/admin/faqs')->json('data'))->where('section', 'parts')->values();
    expect($parts->last()['id'])->toBe($new['id']);

    $ids = $parts->pluck('id')->reverse()->values()->all();
    $after = collect($this->withToken($t)->putJson(api().'/admin/faqs/order', ['ids' => $ids])->assertOk()->json('data'))->where('section', 'parts');
    expect($after->first()['id'])->toBe($new['id']);
});

it('keeps content to editors and admins', function () {
    $this->withToken(staffToken($this, 'sales'))->getJson(api().'/admin/pages')->assertForbidden();
});
