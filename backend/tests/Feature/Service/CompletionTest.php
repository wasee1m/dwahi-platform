<?php

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Setting;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// SM-4 (4.12، 4.13، 4a)، SM-5 (5.10 إلى 5.13)، FR-TEC-05، FR-TEC-06، FR-WO-05، FR-SRV-04، FR-SRV-05، FR-SRV-09، BR-07، BR-10، BR-20، J-05، J-12

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Setting::query()->updateOrCreate(['key' => 'fees.inspection'], ['value' => '15.000']);
});

/**
 * أمر إصلاح بتقدير 126.000 مقبول، مجدول اليوم لفني ومستلم في الورشة.
 *
 * @return array{tech: User, token: string, wo: string, coord: string, sr: array<string, mixed>, customer: string, quote: string}
 */
function repairJob($test): array
{
    $tech = staffUser(['technician'], 'tech@dwahi.test');
    $c = customerToken($test);
    $sr = serviceRequest($test, $c);
    $coord = staffToken($test, 'service_coordinator');
    asUser($test, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/triage", ['route' => 'direct_estimate', 'urgency' => 'normal', 'branch_id' => Branch::query()->value('id')])->assertOk();
    $quote = sendEstimate($test, $coord, $sr['id']);
    asUser($test, $c)->postJson(api()."/me/quotations/{$quote}/accept")->assertOk();
    $wo = asUser($test, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/repair")->assertCreated()->json('data.id');
    asUser($test, $coord)->postJson(api()."/admin/work-orders/{$wo}/schedule", ['lead_id' => $tech->id, 'start' => now()->toIso8601String(), 'end' => now()->addHours(3)->toIso8601String(), 'force' => true])->assertOk();
    asUser($test, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/intake", [
        'brought_by_name' => 'سائق اللوبد', 'signature_id' => staffUpload($test, $coord, 'sig.png'), 'hour_meter_reading' => 9120,
    ])->assertOk();

    return ['tech' => $tech, 'token' => techToken($test, $tech), 'wo' => $wo, 'coord' => $coord, 'sr' => $sr, 'customer' => $c, 'quote' => $quote];
}

/** العمل حتى الإنهاء: البدء قبل ساعة، وصورة بعد، والعداد، وقطعة من العميل، واختبار ناجح */
function workDone($test, array $j): void
{
    $template = DB::table('test_templates')->where('applies_to', 'equipment')->value('id');
    $start = WorkOrder::query()->findOrFail($j['wo'])->status === 'scheduled'
        ? [ev($j['wo'], 'status_changed', ['to' => 'in_progress'], now()->subHour()->toIso8601ZuluString())] : [];
    $r = sync($test, $j['token'], [
        ...$start,
        ev($j['wo'], 'photo_added', ['attachment_id' => techUpload($test, $j['token']), 'phase' => 'after']),
        ev($j['wo'], 'hour_meter_recorded', ['reading' => 9130]),
        ev($j['wo'], 'part_used', ['id' => (string) Str::uuid(), 'quantity' => '2', 'source' => 'customer_supplied', 'description' => 'جوانات من العميل']),
        ev($j['wo'], 'test_recorded', ['id' => (string) Str::uuid(), 'template_id' => $template, 'values' => ['functions_ok' => true, 'no_leak' => true, 'run_minutes' => 20]]),
    ]);
    expect(collect($r)->pluck('result')->unique()->all())->toBe(['applied']);
}

function finish($test, array $j): array
{
    return sync($test, $j['token'], [ev($j['wo'], 'report_submitted', [
        'findings' => 'تلف جوانات السلندر', 'actions_taken' => 'تغيير الجوانات واختبار الضغط', 'recommendations' => 'فحص بعد 500 ساعة',
    ])])[0];
}

it('finishes only when the BR-07 checklist is complete and a test passed (US-29, 5.10, N-28)', function () {
    $j = repairJob($this);
    sync($this, $j['token'], [ev($j['wo'], 'status_changed', ['to' => 'in_progress'], now()->subHour()->toIso8601ZuluString())]);
    $r = finish($this, $j);
    expect($r['result'])->toBe('rejected')->and($r['code'])->toBe('CHECKLIST_INCOMPLETE');

    $d = asUser($this, $j['token'])->getJson(api()."/tech/tasks/{$j['wo']}")->assertOk()->json('data');
    expect($d['checklist'])->toBe(['worked_time' => true, 'hour_meter' => false, 'after_photo' => false, 'parts' => false, 'test_passed' => false])
        ->and($d['template']['fields'])->not->toBeEmpty();

    // اختبار فاشل يُسجَّل ولا يكفي
    $fail = sync($this, $j['token'], [ev($j['wo'], 'test_recorded', ['id' => (string) Str::uuid(), 'values' => ['functions_ok' => true, 'no_leak' => false, 'run_minutes' => 20]], now()->subMinutes(30)->toIso8601ZuluString())]);
    expect($fail[0]['result'])->toBe('applied')->and(DB::table('work_order_tests')->where('work_order_id', $j['wo'])->value('result'))->toBe('fail');

    workDone($this, $j);
    expect(finish($this, $j)['result'])->toBe('applied');
    expect(WorkOrder::query()->findOrFail($j['wo'])->status)->toBe('work_completed')
        ->and(DB::table('time_entries')->where('work_order_id', $j['wo'])->whereNull('ended_at')->exists())->toBeFalse()
        ->and(notified('N-28'))->toBe(1)
        ->and(DB::table('service_reports')->where('work_order_id', $j['wo'])->value('hour_meter_reading'))->toBe(9130);
});

it('verifies with a report PDF, completes the request with a draft invoice from the estimate, and tells the customer (US-34, 4.12, N-14, N-12)', function () {
    $j = repairJob($this);
    workDone($this, $j);
    finish($this, $j);

    $detail = asUser($this, $j['coord'])->getJson(api()."/admin/work-orders/{$j['wo']}")->assertOk()->json('data');
    expect($detail['completion']['report']['findings'])->toBe('تلف جوانات السلندر')->and($detail['completion']['tests'])->toHaveCount(1)
        ->and($detail['approved_scope'])->toHaveCount(1);

    asUser($this, $j['coord'])->postJson(api()."/admin/work-orders/{$j['wo']}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
    $sr = ServiceRequest::query()->findOrFail($j['sr']['id']);
    $invoice = Invoice::query()->where('service_request_id', $sr->id)->firstOrFail();
    expect($sr->status)->toBe('completed')->and($invoice->status)->toBe('draft')->and((string) $invoice->total)->toBe('126.000')
        ->and(DB::table('service_reports')->where('work_order_id', $j['wo'])->value('pdf_id'))->not->toBeNull()
        ->and(notified('N-14'))->toBe(1)->and(notified('N-12'))->toBe(1);

    $view = asUser($this, $j['customer'])->getJson(api()."/me/service-requests/{$sr->id}")->assertOk()->json('data');
    expect($view['customer_status']['key'])->toBe('completed')
        ->and($view['completion']['pickup']['due']['amount'])->toBe('126.000')
        ->and($view['completion']['reports'][0]['report']['pdf_url'])->toContain('signature=')
        ->and($view['completion']['reports'][0]['after'])->toHaveCount(1)
        ->and($view['completion']['reports'][0]['tests'][0]['result'])->toBe('pass');
});

it('returns the task to the technician with a note (5.11, N-18)', function () {
    $j = repairJob($this);
    workDone($this, $j);
    finish($this, $j);
    asUser($this, $j['coord'])->postJson(api()."/admin/work-orders/{$j['wo']}/return", ['note' => 'صورة العداد غير واضحة'])->assertOk()->assertJsonPath('data.status', 'in_progress');
    expect(DB::table('notifications')->where('event_code', 'N-18')->where('user_id', $j['tech']->id)->where('title', 'like', '%أُعيدت%')->count())->toBe(1);
    $history = asUser($this, $j['token'])->getJson(api().'/tech/history')->assertOk()->json('data');
    expect($history['returned'][0]['note'])->toBe('صورة العداد غير واضحة')->and($history['days'])->not->toBeEmpty();
    // يُنهي مجدداً
    expect(finish($this, $j)['result'])->toBe('applied');
});

it('asks for additional work, pauses for approval, and bills it with the estimate once accepted (US-28, 4a.1, 4a.2, 4a.4)', function () {
    $j = repairJob($this);
    sync($this, $j['token'], [ev($j['wo'], 'status_changed', ['to' => 'in_progress'], now()->subHour()->toIso8601ZuluString())]);
    $req = (string) Str::uuid();
    $r = sync($this, $j['token'], [ev($j['wo'], 'additional_work_requested', [
        'id' => $req, 'description' => 'شرخ في عمود السلندر', 'parts_needed' => 'عمود جديد', 'estimated_amount' => '80', 'hold' => true,
        'attachment_id' => techUpload($this, $j['token'], 'crack.jpg'),
    ])]);
    expect($r[0]['result'])->toBe('applied')
        ->and(WorkOrder::query()->findOrFail($j['wo'])->on_hold_reason)->toBe('awaiting_approval')
        ->and(notified('N-13'))->toBe(1);

    $q = asUser($this, $j['coord'])->postJson(api()."/admin/additional-work/{$req}/estimate")->assertCreated()->json('data.quotation_id');
    asUser($this, $j['coord'])->putJson(api()."/admin/quotations/{$q}", ['items' => [
        ['item_type' => 'labor', 'description_ar' => 'تغيير عمود السلندر', 'description_en' => 'Cylinder rod replacement', 'quantity' => '1', 'unit' => 'job', 'unit_price' => '80', 'vat_rate' => '5'],
    ], 'estimated_duration' => 'يوم'])->assertOk();
    asUser($this, $j['coord'])->postJson(api()."/admin/quotations/{$q}/send")->assertOk();
    expect(DB::table('additional_work_requests')->where('id', $req)->value('status'))->toBe('quoted');
    $view = asUser($this, $j['customer'])->getJson(api()."/me/service-requests/{$j['sr']['id']}")->json('data');
    expect($view['customer_status']['key'])->toBe('additional_awaiting')->and($view['completion']['additional'][0]['quotation']['id'])->toBe($q);

    asUser($this, $j['customer'])->postJson(api()."/me/quotations/{$q}/accept")->assertOk();
    expect(DB::table('additional_work_requests')->where('id', $req)->value('status'))->toBe('approved')
        ->and(DB::table('notifications')->where('event_code', 'N-18')->where('user_id', $j['tech']->id)->where('title', 'like', '%وافق%')->count())->toBe(1);

    sync($this, $j['token'], [ev($j['wo'], 'status_changed', ['to' => 'in_progress'])]);
    workDone($this, $j);
    finish($this, $j);
    asUser($this, $j['coord'])->postJson(api()."/admin/work-orders/{$j['wo']}/verify")->assertOk();
    expect((string) Invoice::query()->where('service_request_id', $j['sr']['id'])->value('total'))->toBe('210.000'); // 126 + 84
});

it('rejects additional work with a reason and the approved work goes on (4a.3)', function () {
    $j = repairJob($this);
    sync($this, $j['token'], [ev($j['wo'], 'status_changed', ['to' => 'in_progress'], now()->subHour()->toIso8601ZuluString())]);
    $req = (string) Str::uuid();
    sync($this, $j['token'], [ev($j['wo'], 'additional_work_requested', ['id' => $req, 'description' => 'طلاء'])]);
    asUser($this, $j['coord'])->postJson(api()."/admin/additional-work/{$req}/reject", [])->assertStatus(422);
    asUser($this, $j['coord'])->postJson(api()."/admin/additional-work/{$req}/reject", ['reason' => 'خارج النطاق'])->assertOk();
    expect(DB::table('additional_work_requests')->where('id', $req)->value('status'))->toBe('rejected')
        ->and(WorkOrder::query()->findOrFail($j['wo'])->status)->toBe('in_progress');
});

it('hands over only with the payment, signature and name in one step, then closes everything (US-49, BR-20, 4.13, 5.13)', function () {
    $j = repairJob($this);
    workDone($this, $j);
    finish($this, $j);
    asUser($this, $j['coord'])->postJson(api()."/admin/work-orders/{$j['wo']}/verify")->assertOk();

    $list = asUser($this, $j['coord'])->getJson(api().'/admin/handovers')->assertOk()->json('data');
    expect($list[0]['id'])->toBe($j['sr']['id'])->and($list[0]['due']['amount'])->toBe('126.000');

    $body = ['receiver_name' => 'سائق اللوبد', 'signature_id' => staffUpload($this, $j['coord'], 'out.png')];
    asUser($this, $j['coord'])->postJson(api()."/admin/service-requests/{$j['sr']['id']}/handover", $body)
        ->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_REQUIRED');
    asUser($this, $j['coord'])->postJson(api()."/admin/service-requests/{$j['sr']['id']}/handover", $body + ['payment' => ['method' => 'cash', 'amount' => '100']])
        ->assertStatus(422);
    // الرفض السابق لم يترك فاتورة صادرة بلا تسليم: المعاملة كاملة أو لا شيء
    expect(Invoice::query()->where('service_request_id', $j['sr']['id'])->value('status'))->toBe('draft');

    $done = asUser($this, $j['coord'])->postJson(api()."/admin/service-requests/{$j['sr']['id']}/handover", $body + ['payment' => ['method' => 'cash', 'amount' => '126.000']])
        ->assertCreated()->json('data');
    expect($done['status'])->toBe('closed')->and($done['receipt_url'])->toContain('signature=')
        ->and(Invoice::query()->where('service_request_id', $j['sr']['id'])->value('status'))->toBe('paid')
        ->and(WorkOrder::query()->findOrFail($j['wo'])->status)->toBe('closed')
        ->and(DB::table('notifications')->where('event_code', 'N-32')->where('title', 'like', '%سلّمناك%')->count())->toBe(1);
    asUser($this, $j['coord'])->postJson(api()."/admin/service-requests/{$j['sr']['id']}/handover", $body)->assertStatus(409);

    $view = asUser($this, $j['customer'])->getJson(api()."/me/service-requests/{$j['sr']['id']}")->json('data');
    expect($view['completion']['handover']['receiver_name'])->toBe('سائق اللوبد')->and($view['completion']['pickup'])->toBeNull()
        ->and($view['completion']['invoice']['status'])->toBe('paid')->and($view['completion']['can_review'])->toBeTrue();
});

it('closes work at no charge without an invoice, and hands over without payment (5.13)', function () {
    $j = repairJob($this);
    workDone($this, $j);
    finish($this, $j);
    asUser($this, $j['coord'])->postJson(api()."/admin/work-orders/{$j['wo']}/verify", ['no_charge' => true])->assertStatus(422);
    asUser($this, $j['coord'])->postJson(api()."/admin/work-orders/{$j['wo']}/verify", ['no_charge' => true, 'reason' => 'إعادة إصلاح سابق'])
        ->assertOk()->assertJsonPath('data.status', 'closed');
    expect(ServiceRequest::query()->findOrFail($j['sr']['id'])->status)->toBe('completed')
        ->and(Invoice::query()->where('service_request_id', $j['sr']['id'])->exists())->toBeFalse();
    asUser($this, $j['coord'])->postJson(api()."/admin/service-requests/{$j['sr']['id']}/handover", ['receiver_name' => 'صاحب المعدة', 'signature_id' => staffUpload($this, $j['coord'], 's.png')])
        ->assertCreated()->assertJsonPath('data.status', 'closed');
});

it('charges the inspection fee when a request is cancelled after inspection and handed back (BR-11, BR-20)', function () {
    $t = techTask($this);
    sync($this, $t['token'], [ev($t['wo'], 'status_changed', ['to' => 'in_progress'], now()->subHour()->toIso8601ZuluString()), ev($t['wo'], 'hour_meter_recorded', ['reading' => 9125])]);
    expect(sync($this, $t['token'], [ev($t['wo'], 'report_submitted', ['findings' => 'تسريب من جوان البوم'])])[0]['result'])->toBe('applied');
    asUser($this, $t['coord'])->postJson(api()."/admin/work-orders/{$t['wo']}/verify")->assertOk();
    // الفحص وحده لا يُكمل الطلب (4.7)
    expect(ServiceRequest::query()->findOrFail($t['sr']['id'])->status)->toBe('in_service');
    ServiceRequest::query()->whereKey($t['sr']['id'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);

    $row = collect(asUser($this, $t['coord'])->getJson(api().'/admin/handovers')->json('data'))->firstWhere('id', $t['sr']['id']);
    expect($row['due']['amount'])->toBe('15.750')->and($row['inspection_fee_pending'])->toBeTrue();
    $body = ['receiver_name' => 'العميل', 'signature_id' => staffUpload($this, $t['coord'], 'c.png')];
    asUser($this, $t['coord'])->postJson(api()."/admin/service-requests/{$t['sr']['id']}/handover", $body)->assertStatus(409);
    asUser($this, $t['coord'])->postJson(api()."/admin/service-requests/{$t['sr']['id']}/handover", $body + ['payment' => ['method' => 'pos_card', 'amount' => '15.750']])
        ->assertCreated()->assertJsonPath('data.status', 'cancelled');
    expect(Invoice::query()->where('service_request_id', $t['sr']['id'])->value('status'))->toBe('paid');
});

it('lets the customer rate once within the window, invited after 24 hours (BR-10, US-20, J-05, N-15)', function () {
    $j = repairJob($this);
    workDone($this, $j);
    finish($this, $j);
    asUser($this, $j['coord'])->postJson(api()."/admin/work-orders/{$j['wo']}/verify", ['no_charge' => true, 'reason' => 'ضمان'])->assertOk();
    asUser($this, $j['customer'])->postJson(api()."/me/service-requests/{$j['sr']['id']}/review", ['rating' => 5])->assertStatus(409)->assertJsonPath('error.code', 'REVIEW_CLOSED');
    asUser($this, $j['coord'])->postJson(api()."/admin/service-requests/{$j['sr']['id']}/handover", ['receiver_name' => 'العميل', 'signature_id' => staffUpload($this, $j['coord'], 'r.png')])->assertCreated();

    $this->artisan('service:run reviews')->assertSuccessful();
    expect(notified('N-15'))->toBe(0);
    ServiceRequest::query()->whereKey($j['sr']['id'])->update(['closed_at' => now()->subHours(25)]);
    $this->artisan('service:run reviews')->assertSuccessful();
    $this->artisan('service:run reviews')->assertSuccessful();
    expect(notified('N-15'))->toBe(1);

    asUser($this, $j['customer'])->postJson(api()."/me/service-requests/{$j['sr']['id']}/review", ['rating' => 6])->assertStatus(422);
    $view = asUser($this, $j['customer'])->postJson(api()."/me/service-requests/{$j['sr']['id']}/review", [
        'rating' => 5, 'quality_rating' => 4, 'comment' => 'خدمة ممتازة', 'publish_consent' => true,
    ])->assertOk()->json('data');
    expect($view['completion']['review']['rating'])->toBe(5)->and($view['completion']['can_review'])->toBeFalse();
    asUser($this, $j['customer'])->postJson(api()."/me/service-requests/{$j['sr']['id']}/review", ['rating' => 4])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_REVIEWED');
});

it('reminds the customer to collect a ready item, once per reminder period (BR-20, J-12)', function () {
    $j = repairJob($this);
    workDone($this, $j);
    finish($this, $j);
    asUser($this, $j['coord'])->postJson(api()."/admin/work-orders/{$j['wo']}/verify")->assertOk();
    $base = notified('N-12');
    $this->artisan('service:run pickup')->assertSuccessful();
    expect(notified('N-12'))->toBe($base);
    ServiceRequest::query()->whereKey($j['sr']['id'])->update(['completed_at' => now()->subDays(4)]);
    $this->artisan('service:run pickup')->assertSuccessful();
    $this->artisan('service:run pickup')->assertSuccessful();
    expect(notified('N-12'))->toBe($base + 1);
});
