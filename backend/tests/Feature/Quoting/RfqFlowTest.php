<?php

use App\Modules\Catalog\Models\EquipmentListing;
use App\Modules\Parts\Models\Part;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\Rfq;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// SM-1، SM-2، FR-RFQ-01..05، FR-PRT-05، US-12، US-13، US-32، BR-01، BR-02، BR-14، BR-19

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

function partsRfq($test, string $token): array
{
    $photo = asUser($test, $token)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->image('plate.jpg', 800, 600)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');

    return asUser($test, $token)->postJson(api().'/me/rfqs', ['type' => 'parts', 'notes' => 'للحفار في صحار', 'items' => [
        ['part_id' => Part::query()->where('part_number', '1R-0750')->value('id'), 'quantity' => 50],
        ['free_text' => 'فلتر هيدروليك 207-60-71182', 'quantity' => 2, 'attachment_ids' => [$photo]],
    ]])->assertCreated()->json('data');
}

it('creates a parts request with a catalog line and an unlisted part with its photo (US-12)', function () {
    staffUser(['sales'], 'sales@dwahi.test');
    $t = customerToken($this);
    $rfq = partsRfq($this, $t);

    expect($rfq['status'])->toBe('submitted')
        ->and($rfq['number'])->toMatch('/^RFQ-\d{4}-\d{6}$/')
        ->and($rfq['items'])->toHaveCount(2)
        ->and($rfq['items'][1]['attachments'][0]['url'])->toContain('signature=');

    // N-01 للعميل ولفريق المبيعات
    expect(notified('N-01'))->toBe(2);

    // بند بلا رقم ولا وصف ولا صنف
    $details = asUser($this, $t)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['quantity' => 1]]])
        ->assertStatus(422)->json('error.details');
    expect($details['items.0.free_text'][0])->toBe('أدخل رقم القطعة أو وصفها، أو اختر صنفاً من الكتالوج.');

    // الملف الخاص برابط موقّع فقط
    $url = $rfq['items'][1]['attachments'][0]['url'];
    $this->get($url)->assertOk();
    $this->get(preg_replace('/signature=\w+/', 'signature=bad', $url))->assertForbidden();
});

it('lets sales quote with per-line VAT, send it, and locks the sent version (US-32)', function () {
    $c = customerToken($this);
    $rfq = partsRfq($this, $c);
    $s = staffToken($this, 'sales');

    // "إعداد عرض": البنود من الطلب بأسعار الكتالوج
    $draft = asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/quotations")->assertCreated()->json('data');
    $part = Part::query()->where('part_number', '1R-0750')->first();
    expect($draft['items'][0]['unit_price'])->toBe($part->price)->and($draft['status'])->toBe('draft');

    $lines = $draft['items'];
    $lines[0]['discount_amount'] = bcmul(bcmul('50', $part->price, 3), '0.05', 3); // خصم 5%
    $lines[1]['unit_price'] = '12.400';
    $lines[] = ['item_type' => 'labor', 'description_ar' => 'تركيب', 'description_en' => 'Fitting', 'quantity' => '1', 'unit' => 'job', 'unit_price' => '15', 'vat_rate' => '5'];
    $q = asUser($this, $s)->putJson(api()."/admin/quotations/{$draft['id']}", ['items' => $lines, 'payment_terms' => 'نقداً عند الاستلام'])->assertOk()->json('data');
    expect($q['items'])->toHaveCount(3)->and($q['items'][2]['line_vat'])->toBe('0.750');

    // معاينة PDF للمسودة
    asUser($this, $s)->get(api()."/admin/quotations/{$q['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $sent = asUser($this, $s)->postJson(api()."/admin/quotations/{$q['id']}/send")->assertOk()->json('data');
    expect($sent['status'])->toBe('sent')
        ->and($sent['number'])->toMatch('/^QT-\d{4}-\d{6}-v1$/')
        ->and($sent['valid_until'])->toBe(now('Asia/Muscat')->addDays(14)->toDateString())
        ->and(Rfq::query()->find($rfq['id'])->status)->toBe('quoted')
        ->and(notified('N-02'))->toBe(1);

    // لا تعديل بعد الإرسال
    asUser($this, $s)->putJson(api()."/admin/quotations/{$q['id']}", ['items' => $lines])->assertStatus(409)->assertJsonPath('error.code', 'QUOTATION_LOCKED');
    // ولا إلغاء من العميل بعد وصول عرض
    asUser($this, $c)->postJson(api()."/me/rfqs/{$rfq['id']}/cancel")->assertStatus(409);
    // العميل ينزّل PDF المحفوظ، والتطبيق يفتحه برابط موقّع (M-18)
    asUser($this, $c)->get(api()."/me/quotations/{$q['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $pdfUrl = asUser($this, $c)->getJson(api()."/me/rfqs/{$rfq['id']}")->json('data.quotations.0.pdf_url');
    expect($pdfUrl)->toContain('signature=');
    $this->get($pdfUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('issues a new version on a revision request and supersedes the old one (FR-RFQ-03)', function () {
    $c = customerToken($this);
    $rfq = partsRfq($this, $c);
    $s = staffToken($this, 'sales');
    $v1 = asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/quotations")->json('data.id');
    asUser($this, $s)->postJson(api()."/admin/quotations/{$v1}/send")->assertOk();

    asUser($this, $c)->postJson(api()."/me/quotations/{$v1}/revision", ['note' => 'نحتاج سعراً لـ 30 وحدة'])->assertOk()
        ->assertJsonPath('data.status', 'in_review');
    expect(notified('N-03'))->toBe(1);

    $v2 = asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/quotations")->assertCreated()->json('data');
    expect($v2['version'])->toBe(2)->and($v2['items'])->toHaveCount(2);
    $sent = asUser($this, $s)->postJson(api()."/admin/quotations/{$v2['id']}/send")->json('data');

    expect(Quotation::query()->find($v1)->status)->toBe('superseded')
        ->and($sent['number'])->toBe(preg_replace('/-v1$/', '-v2', Quotation::query()->find($v1)->number));
    $versions = asUser($this, $c)->getJson(api()."/me/rfqs/{$rfq['id']}")->json('data.quotations');
    expect(collect($versions)->pluck('status')->all())->toBe(['sent', 'superseded']);
});

it('reserves the machine on acceptance and blocks other requests for it (BR-19, FR-RFQ-05)', function () {
    $listing = EquipmentListing::query()->where('status', 'published')->whereNotNull('price')->first();
    $c = customerToken($this);
    $rfq = asUser($this, $c)->postJson(api().'/me/rfqs', ['type' => 'equipment', 'items' => [['listing_id' => $listing->id, 'quantity' => 1]]])
        ->assertCreated()->json('data');
    $s = staffToken($this, 'sales');
    $q = asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/quotations")->json('data.id');
    asUser($this, $s)->postJson(api()."/admin/quotations/{$q}/send")->assertOk();

    $lpo = asUser($this, $c)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->create('lpo.pdf', 50, 'application/pdf')], ['Accept' => 'application/json']);
    asUser($this, $c)->postJson(api()."/me/quotations/{$q}/accept", ['lpo_number' => 'LPO-7781'])->assertOk()->assertJsonPath('data.status', 'won');

    expect($listing->fresh()->status)->toBe('reserved')
        ->and($listing->fresh()->reserved_for_rfq_id)->toBe($rfq['id'])
        ->and(Quotation::query()->find($q)->lpo_number)->toBe('LPO-7781')
        ->and(notified('N-03'))->toBe(1);

    // عميل آخر لا يطلب معدة محجوزة
    $other = customerToken($this, '92222222');
    asUser($this, $other)->postJson(api().'/me/rfqs', ['type' => 'equipment', 'items' => [['listing_id' => $listing->id, 'quantity' => 1]]])
        ->assertStatus(422)->assertJsonValidationErrors(['items.0.listing_id'], 'error.details');
    // ولا يرى طلب غيره
    asUser($this, $other)->getJson(api()."/me/rfqs/{$rfq['id']}")->assertNotFound();
    unset($lpo);
});

it('expires quotes hourly, reminds once 48 hours before, and lets the customer ask for a renewal (BR-02, J-02, J-03)', function () {
    $c = customerToken($this);
    $rfq = partsRfq($this, $c);
    $s = staffToken($this, 'sales');
    $q = asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/quotations")->json('data.id');
    asUser($this, $s)->postJson(api()."/admin/quotations/{$q}/send")->assertOk();
    $validUntil = Quotation::query()->find($q)->valid_until;

    $this->travelTo($validUntil->copy()->subDays(1)->setTime(8, 0)); // قبل نهاية يوم الصلاحية بأقل من 48 ساعة
    $this->artisan('quotations:run remind')->assertSuccessful();
    $this->artisan('quotations:run remind')->assertSuccessful();
    expect(notified('N-04'))->toBe(1);

    $this->travelTo($validUntil->copy()->addDay()->setTime(1, 0));
    $this->artisan('quotations:run expire')->assertSuccessful();
    $this->artisan('quotations:run expire')->assertSuccessful();
    expect(Quotation::query()->find($q)->status)->toBe('expired')
        ->and(Rfq::query()->find($rfq['id'])->lost_reason)->toBe('expired');

    $c = customerToken($this, '91234567'); // رمز جديد بعد تقديم الوقت
    asUser($this, $c)->postJson(api()."/me/quotations/{$q}/accept")->assertStatus(409);
    asUser($this, $c)->postJson(api()."/me/rfqs/{$rfq['id']}/renew")->assertOk()->assertJsonPath('data.status', 'in_review');
    expect(notified('N-03'))->toBe(1);
});

it('asks the owner to approve when a member without approval rights accepts (US-13, N-31)', function () {
    $owner = customerToken($this, '93333333', 'شركة الخوض');
    $orgId = DB::table('organizations')->value('id');
    $memberToken = customerToken($this, '94444444');
    $member = DB::table('users')->where('phone_e164', '+96894444444')->value('id');
    DB::table('organization_members')->insert(['organization_id' => $orgId, 'user_id' => $member, 'role' => 'member', 'can_approve_quotes' => false, 'joined_at' => now()]);

    $rfq = partsRfq($this, $memberToken);
    expect(Rfq::query()->find($rfq['id'])->organization_id)->toBe($orgId);
    $s = staffToken($this, 'sales');
    $q = asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/quotations")->json('data.id');
    asUser($this, $s)->postJson(api()."/admin/quotations/{$q}/send")->assertOk();
    expect(notified('N-02'))->toBe(2); // الطالب والمالك

    asUser($this, $memberToken)->getJson(api()."/me/rfqs/{$rfq['id']}")->assertJsonPath('data.can_decide', false);
    asUser($this, $memberToken)->postJson(api()."/me/quotations/{$q}/accept")->assertForbidden()->assertJsonPath('error.code', 'APPROVAL_REQUIRED');
    asUser($this, $memberToken)->postJson(api()."/me/quotations/{$q}/request-approval")->assertOk();
    expect(notified('N-31', DB::table('users')->where('phone_e164', '+96893333333')->value('id')))->toBe(1);

    asUser($this, $owner)->postJson(api()."/me/quotations/{$q}/accept")->assertOk()->assertJsonPath('data.status', 'won');
});

it('records a lost request with its reason and tells the customer (SM-1 1.4, N-25)', function () {
    $c = customerToken($this);
    $rfq = partsRfq($this, $c);
    $s = staffToken($this, 'sales');
    asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/assign")->assertOk()->assertJsonPath('data.status', 'in_review');
    asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/lose", [])->assertStatus(422);
    asUser($this, $s)->postJson(api()."/admin/rfqs/{$rfq['id']}/lose", ['reason' => 'غير متوفرة لدى الوكيل'])->assertOk()->assertJsonPath('data.status', 'lost');
    $n = DB::table('notifications')->where('event_code', 'N-25')->first();
    expect($n->body)->toBe('غير متوفرة لدى الوكيل');
});

it('creates a request on behalf of a phone customer (A-05, 01 §3.2)', function () {
    $s = staffToken($this, 'sales');
    asUser($this, $s)->getJson(api().'/admin/customers/lookup?phone=95555555')->assertOk()->assertJsonPath('data', null);
    $customer = asUser($this, $s)->postJson(api().'/admin/customers', ['phone' => '9555 5555', 'full_name' => 'خالد'])->assertCreated()->json('data');

    $rfq = asUser($this, $s)->postJson(api().'/admin/rfqs', ['customer_id' => $customer['id'], 'source' => 'phone', 'type' => 'parts',
        'items' => [['free_text' => 'طقم جوانات مضخة', 'quantity' => 1]]])->assertCreated()->json('data');
    expect($rfq['source'])->toBe('phone')->and($rfq['status'])->toBe('in_review')->and($rfq['assigned_to']['name'])->not->toBeNull();

    // الفني لا يصل لطلبات العروض
    asUser($this, staffToken($this, 'technician'))->getJson(api().'/admin/rfqs')->assertForbidden();
});

it('explains why a part does not belong in an equipment request', function () {
    $c = customerToken($this);
    $details = asUser($this, $c)->postJson(api().'/me/rfqs', ['type' => 'equipment', 'items' => [
        ['part_id' => Part::query()->where('part_number', '1R-0750')->value('id'), 'quantity' => 1],
    ]])->assertStatus(422)->json('error.details');

    expect($details['items.0.part_id'][0])->toBe(__('quoting.part_in_equipment_request', [], 'ar'));
});

it('assigns a request only to staff who follow quote requests', function () {
    $c = customerToken($this);
    $rfq = partsRfq($this, $c);
    $technician = staffUser(['technician'], 'tech@dwahi.test');
    $coordinator = staffUser(['service_coordinator'], 'coordinator@dwahi.test');
    $s = staffToken($this, 'sales');
    $url = api()."/admin/rfqs/{$rfq['id']}/assign";

    foreach ([$technician->id, DB::table('users')->where('user_type', 'customer')->value('id')] as $id) {
        asUser($this, $s)->postJson($url, ['user_id' => $id])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['user_id']]]);
    }
    asUser($this, $s)->postJson($url, ['user_id' => $coordinator->id])->assertOk();
    expect(DB::table('rfqs')->where('id', $rfq['id'])->value('assigned_to'))->toBe($coordinator->id);
});

it('lists new requests first, then in review, then quoted, oldest first in each', function () {
    $c = customerToken($this);
    $create = function (string $status, int $daysAgo) use ($c) {
        $id = asUser($this, $c)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['free_text' => 'فلتر', 'quantity' => 1]]])
            ->assertCreated()->json('data.id');
        DB::table('rfqs')->where('id', $id)->update(['status' => $status, 'created_at' => now()->subDays($daysAgo)]);

        return $id;
    };
    $quoted = $create('quoted', 9);
    $newer = $create('submitted', 1);
    $inReview = $create('in_review', 5);
    $older = $create('submitted', 2);

    $listed = collect(asUser($this, staffToken($this, 'sales'))->getJson(api().'/admin/rfqs')->assertOk()->json('data'))
        ->pluck('id')->intersect([$quoted, $newer, $inReview, $older])->values()->all();

    expect($listed)->toBe([$older, $newer, $inReview, $quoted]);
});
