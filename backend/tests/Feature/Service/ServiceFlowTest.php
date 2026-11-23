<?php

use App\Models\User;
use App\Modules\Platform\Models\Setting;
use App\Modules\Service\Models\ServiceRequest;
use App\Modules\Service\Models\WorkOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// SM-4، SM-5، FR-SRV-01، FR-SRV-02، FR-SRV-03، FR-SRV-08، FR-WO-01، FR-WO-02، BR-06، BR-09، BR-11، BR-20، J-04

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Setting::query()->updateOrCreate(['key' => 'fees.inspection'], ['value' => '15.000']);
});

function machine($test, string $token): string
{
    return asUser($test, $token)->postJson(api().'/me/fleet', ['brand_text' => 'CAT', 'model_text' => '320D', 'organization_id' => null, 'hour_meter' => 9000])
        ->assertCreated()->json('data.id');
}

function serviceRequest($test, string $token, array $extra = []): array
{
    return asUser($test, $token)->postJson(api().'/me/service-requests', $extra + [
        'customer_equipment_id' => machine($test, $token), 'organization_id' => null,
        'problem_description' => 'تسريب زيت من سلندر البوم', 'urgency' => 'normal', 'fees_acknowledged' => true,
    ])->assertCreated()->json('data');
}

function staffUpload($test, string $token, string $name = 'x.png'): string
{
    return asUser($test, $token)->post(api().'/admin/staff-uploads', ['file' => UploadedFile::fake()->image($name)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
}

function sendEstimate($test, string $coordinator, string $srId, string $price = '120'): string
{
    $q = asUser($test, $coordinator)->postJson(api()."/admin/service-requests/{$srId}/estimate")->assertCreated()->json('data');
    asUser($test, $coordinator)->putJson(api()."/admin/quotations/{$q['quotation_id']}", ['items' => [
        ['item_type' => 'labor', 'description_ar' => 'تغيير جوانات السلندر', 'description_en' => 'Cylinder reseal', 'quantity' => '1', 'unit' => 'job', 'unit_price' => $price, 'vat_rate' => '5'],
    ], 'estimated_duration' => 'يومان'])->assertOk();
    asUser($test, $coordinator)->postJson(api()."/admin/quotations/{$q['quotation_id']}/send")->assertOk();

    return $q['quotation_id'];
}

it('takes a service request with photos and a video after the inspection fee is acknowledged (US-17, BR-11, BR-09)', function () {
    staffUser(['service_coordinator'], 'coord@dwahi.test');
    $c = customerToken($this);
    $meta = asUser($this, $c)->getJson(api().'/me/service-requests/meta')->assertOk()->json('data');
    expect($meta['inspection_fee']['amount'])->toBe('15.000')->and($meta['workshop'])->not->toBeNull();

    $photo = asUser($this, $c)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->image('leak.jpg')], ['Accept' => 'application/json'])->json('data.id');
    $video = asUser($this, $c)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->createWithContent('leak.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64))],
        ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    asUser($this, $c)->postJson(api().'/me/service-requests', ['component_type' => 'hydraulic_pump', 'problem_description' => 'x', 'urgency' => 'normal'])
        ->assertStatus(422); // الإقرار بالرسوم شرط

    $sr = serviceRequest($this, $c, ['urgency' => 'urgent', 'attachments' => [['id' => $photo, 'kind' => 'photo'], ['id' => $video, 'kind' => 'video']]]);
    expect($sr['number'])->toMatch('/^SR-\d{4}-\d{6}$/')->and($sr['status'])->toBe('submitted')
        ->and($sr['customer_status']['key'])->toBe('submitted')->and($sr['inspection_fee']['amount'])->toBe('15.000')
        ->and($sr['attachments'])->toHaveCount(2)->and($sr['equipment']['name'])->toBe('CAT 320D');
    $model = ServiceRequest::query()->findOrFail($sr['id']);
    // العاجل خلال ساعة على مدار الساعة (BR-09)
    expect((int) round(now()->diffInMinutes($model->sla_due_at)))->toBe(60)
        ->and(notified('N-08-urgent'))->toBe(1);

    // مكوّن منفصل بلا عداد، ومعدة أخرى تُضاف للأسطول (US-17)
    $pump = asUser($this, $c)->postJson(api().'/me/service-requests', ['component_type' => 'hydraulic_pump', 'component_details' => 'Kawasaki K3V112',
        'problem_description' => 'ضعف الضغط', 'urgency' => 'normal', 'fees_acknowledged' => true, 'organization_id' => null])->assertCreated()->json('data');
    expect($pump['equipment'])->toBeNull()->and($pump['component_type'])->toBe('hydraulic_pump');
    $new = asUser($this, $c)->postJson(api().'/me/service-requests', ['new_equipment' => ['brand_text' => 'JCB', 'model_text' => '3CX'],
        'problem_description' => 'لا يشتغل', 'urgency' => 'normal', 'fees_acknowledged' => true, 'organization_id' => null])->assertCreated()->json('data');
    expect($new['equipment']['name'])->toBe('JCB 3CX')
        ->and(collect(asUser($this, $c)->getJson(api().'/me/fleet')->json('data'))->pluck('name'))->toContain('JCB 3CX');

    // عميل آخر لا يرى الطلب
    asUser($this, customerToken($this, '91234568'))->getJson(api()."/me/service-requests/{$sr['id']}")->assertNotFound();
});

it('normal requests get four working hours from the business hours (BR-09)', function () {
    // الخميس 16:00 بتوقيت مسقط: ساعة الخميس ثم ثلاث ساعات من الأحد
    $this->travelTo(Carbon::parse('2026-11-26 16:00', 'Asia/Muscat'));
    $c = customerToken($this);
    $sr = serviceRequest($this, $c);
    expect(ServiceRequest::query()->findOrFail($sr['id'])->sla_due_at->timezone('Asia/Muscat')->format('Y-m-d H:i'))->toBe('2026-11-29 11:00');
});

it('triages to inspection with an unassigned inspection order, or declines with a reason (US-31, 4.2, 4.3, 4.6)', function () {
    $c = customerToken($this);
    $a = serviceRequest($this, $c);
    $b = serviceRequest($this, $c);
    $coord = staffToken($this, 'service_coordinator');
    $branch = DB::table('branches')->value('id');

    $list = asUser($this, $coord)->getJson(api().'/admin/service-requests')->assertOk()->json();
    expect($list['counts']['triage'])->toBe(2)->and($list['data'][0]['sla_due_at'])->not->toBeNull();

    $done = asUser($this, $coord)->postJson(api()."/admin/service-requests/{$a['id']}/triage", ['route' => 'inspection', 'urgency' => 'normal', 'branch_id' => $branch, 'instructions' => 'افحص ضغط السلندر'])
        ->assertOk()->json('data');
    expect($done['status'])->toBe('in_service')->and($done['work_orders'][0]['type'])->toBe('inspection')
        ->and($done['work_orders'][0]['status'])->toBe('unassigned')->and($done['work_orders'][0]['number'])->toMatch('/^WO-/');
    // العميل يرى "بانتظار إحضار المعدة" حتى الاستلام
    expect(asUser($this, $c)->getJson(api()."/me/service-requests/{$a['id']}")->json('data.customer_status.key'))->toBe('awaiting_dropoff');

    asUser($this, $coord)->postJson(api()."/admin/service-requests/{$b['id']}/decline")->assertStatus(422);
    asUser($this, $coord)->postJson(api()."/admin/service-requests/{$b['id']}/decline", ['reason' => 'نوع معدة غير مدعوم'])->assertOk()->assertJsonPath('data.status', 'declined');
    expect(notified('N-25'))->toBe(1);
});

it('sends an estimate the customer approves, then a repair order follows (US-18, FR-SRV-03, 4.5, 4.8, 4.11)', function () {
    $c = customerToken($this);
    $sr = serviceRequest($this, $c);
    $coord = staffToken($this, 'service_coordinator');
    asUser($this, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/triage", ['route' => 'direct_estimate', 'urgency' => 'normal', 'branch_id' => DB::table('branches')->value('id')])->assertOk();

    $quote = sendEstimate($this, $coord, $sr['id']);
    $view = asUser($this, $c)->getJson(api()."/me/service-requests/{$sr['id']}")->json('data');
    expect($view['status'])->toBe('awaiting_approval')->and($view['estimate']['quotation']['total']['amount'])->toBe('126.000')
        ->and(notified('N-10'))->toBe(1)->and(notified('N-02'))->toBe(0);

    asUser($this, $c)->postJson(api()."/me/quotations/{$quote}/accept")->assertOk();
    expect(ServiceRequest::query()->findOrFail($sr['id'])->status)->toBe('approved');

    $wo = asUser($this, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/repair")->assertCreated()->json('data');
    expect(ServiceRequest::query()->findOrFail($sr['id'])->status)->toBe('in_service')
        ->and(WorkOrder::query()->findOrFail($wo['id'])->quotation_id)->toBe($quote);
});

it('approves an estimate within the company pre-approval limit by itself (BR-06, 4.14, N-24)', function () {
    $c = customerToken($this, '91234567', 'شركة الخوض');
    $org = DB::table('organizations')->value('id');
    DB::table('organizations')->where('id', $org)->update(['preapproval_limit' => '200.000']);
    $sr = asUser($this, $c)->postJson(api().'/me/service-requests', ['component_type' => 'hydraulic_cylinder', 'problem_description' => 'تسريب',
        'urgency' => 'normal', 'fees_acknowledged' => true, 'organization_id' => $org])->assertCreated()->json('data');
    $coord = staffToken($this, 'service_coordinator');
    asUser($this, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/triage", ['route' => 'direct_estimate', 'urgency' => 'normal', 'branch_id' => DB::table('branches')->value('id')])->assertOk();
    $quote = sendEstimate($this, $coord, $sr['id'], '150');

    expect(ServiceRequest::query()->findOrFail($sr['id'])->status)->toBe('approved')
        ->and(DB::table('quotations')->where('id', $quote)->value('status'))->toBe('accepted')
        ->and(notified('N-24'))->toBe(1)->and(notified('N-10'))->toBe(0);
});

it('cancels the request when the estimate is rejected, and returns it for review on a revision request (4.9, 4.10)', function () {
    $c = customerToken($this);
    $coord = staffToken($this, 'service_coordinator');
    $branch = DB::table('branches')->value('id');
    $a = serviceRequest($this, $c);
    $b = serviceRequest($this, $c);
    foreach ([$a, $b] as $sr) {
        asUser($this, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/triage", ['route' => 'direct_estimate', 'urgency' => 'normal', 'branch_id' => $branch])->assertOk();
    }
    $qa = sendEstimate($this, $coord, $a['id']);
    $qb = sendEstimate($this, $coord, $b['id']);
    asUser($this, $c)->postJson(api()."/me/quotations/{$qa}/reject", ['reason' => 'غالي'])->assertOk();
    asUser($this, $c)->postJson(api()."/me/quotations/{$qb}/revision", ['note' => 'بدون قطع أصلية'])->assertOk();

    expect(ServiceRequest::query()->findOrFail($a['id'])->status)->toBe('cancelled')
        ->and(ServiceRequest::query()->findOrFail($b['id'])->status)->toBe('triaged');
    // نسخة جديدة من نفس التقدير
    $again = asUser($this, $coord)->postJson(api()."/admin/service-requests/{$b['id']}/estimate")->assertCreated()->json('data');
    expect($again['quotation_id'])->not->toBe($qb);
});

it('schedules a lead and an assistant, warns on a conflict, and needs a reason to reschedule (US-33, 5.2, 5.3)', function () {
    $tech = staffUser(['technician'], 'tech1@dwahi.test');
    $helper = staffUser(['technician'], 'tech2@dwahi.test');
    DB::table('staff_profiles')->where('user_id', $tech->id)->update(['skills' => json_encode(['سلندرات هيدروليك'])]);
    $c = customerToken($this);
    $coord = staffToken($this, 'service_coordinator');
    $branch = DB::table('branches')->value('id');
    $ids = [];
    foreach ([serviceRequest($this, $c), serviceRequest($this, $c)] as $sr) {
        $ids[] = asUser($this, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/triage", ['route' => 'inspection', 'urgency' => 'normal', 'branch_id' => $branch])
            ->json('data.work_orders.0.id');
    }
    $start = now('Asia/Muscat')->addDay()->setTime(8, 0);
    $slot = ['lead_id' => $tech->id, 'assistant_ids' => [$helper->id], 'start' => $start->toIso8601String(), 'end' => $start->copy()->addHours(4)->toIso8601String()];

    $one = asUser($this, $coord)->postJson(api()."/admin/work-orders/{$ids[0]}/schedule", $slot)->assertOk()->json('data');
    expect($one['status'])->toBe('scheduled')->and(collect($one['technicians'])->pluck('role')->sort()->values()->all())->toBe(['assistant', 'lead'])
        ->and(notified('N-11'))->toBe(1)->and(notified('N-18'))->toBe(2);

    $conflict = asUser($this, $coord)->postJson(api()."/admin/work-orders/{$ids[1]}/schedule", ['lead_id' => $helper->id, 'assistant_ids' => []] + $slot)
        ->assertStatus(409)->assertJsonPath('error.code', 'SCHEDULE_CONFLICT')->json('error.details.conflicts');
    expect($conflict[0]['work_order'])->toBe($one['number']);
    asUser($this, $coord)->postJson(api()."/admin/work-orders/{$ids[1]}/schedule", ['lead_id' => $helper->id, 'assistant_ids' => [], 'force' => true] + $slot)->assertOk();

    asUser($this, $coord)->postJson(api()."/admin/work-orders/{$ids[0]}/schedule", $slot)->assertStatus(422); // السبب لإعادة الجدولة
    // نفس الوقت مع المساعد المشغول الآن: تعارض يُؤكَّد
    asUser($this, $coord)->postJson(api()."/admin/work-orders/{$ids[0]}/schedule", ['reason' => 'طلب العميل', 'force' => true] + $slot)->assertOk();

    // A-10: الفلترة بالمهارة، والمهام في يوم الموعد
    $board = asUser($this, $coord)->getJson(api().'/admin/dispatch?from='.$start->toDateString().'&skill='.urlencode('سلندرات هيدروليك'))->assertOk()->json('data');
    expect($board['technicians'])->toHaveCount(1)->and($board['technicians'][0]['tasks'])->toHaveCount(1);

    // غير الفني لا يُسند
    $sales = staffUser(['sales'], 'sales2@dwahi.test');
    asUser($this, $coord)->postJson(api()."/admin/work-orders/{$ids[1]}/schedule", ['lead_id' => $sales->id, 'reason' => 'x'] + collect($slot)->except('lead_id', 'assistant_ids')->all())
        ->assertStatus(422);
});

it('receives the machine with photos, signature and the hour meter, and sends the receipt (US-48, FR-SRV-08, N-32)', function () {
    $c = customerToken($this);
    $sr = serviceRequest($this, $c);
    $coord = staffToken($this, 'service_coordinator');
    $signature = staffUpload($this, $coord, 'signature.png');
    $photo = staffUpload($this, $coord);

    // المعدة كاملة تحتاج القراءة أو سبب تعذرها
    asUser($this, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/intake", ['brought_by_name' => 'سائق اللوبد', 'signature_id' => $signature])->assertStatus(422);
    $done = asUser($this, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/intake", [
        'brought_by_name' => 'سائق اللوبد', 'brought_by_phone' => '+96899112233', 'signature_id' => $signature, 'photo_ids' => [$photo],
        'hour_meter_reading' => 9120, 'accessories' => 'مفتاح التشغيل', 'condition_notes' => 'خدوش على البوم',
    ])->assertOk()->json('data');

    expect($done['intake']['number'])->toMatch('/^RC-\d{4}-\d{6}$/')->and($done['received'])->toBeTrue()
        ->and(notified('N-32'))->toBe(1)
        ->and(DB::table('hour_meter_readings')->where('source', 'intake')->value('reading'))->toBe(9120);
    asUser($this, $coord)->get(api()."/admin/service-requests/{$sr['id']}/receipt")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    asUser($this, $coord)->getJson(api().'/admin/intakes/'.strtolower($done['intake']['number']))->assertOk()->assertJsonPath('data.service_request_id', $sr['id']);
    // مرة واحدة فقط
    asUser($this, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/intake", ['brought_by_name' => 'x', 'signature_id' => $signature, 'hour_meter_reading' => 1])
        ->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_RECEIVED');
    // العميل يرى الإيصال برابط موقّع
    expect(asUser($this, $c)->getJson(api()."/me/service-requests/{$sr['id']}")->json('data.intake.receipt_url'))->toContain('signature=');
});

it('creates and receives a walk-in request in one step (US-48)', function () {
    customerToken($this);
    $customer = User::query()->where('user_type', 'customer')->firstOrFail();
    $coord = staffToken($this, 'service_coordinator');
    $done = asUser($this, $coord)->postJson(api().'/admin/service-requests/walk-in', [
        'customer_id' => $customer->id, 'organization_id' => null, 'component_type' => 'diesel_engine', 'component_details' => 'CAT 3306',
        'problem_description' => 'دخان أسود', 'urgency' => 'normal', 'fees_acknowledged' => true,
        'brought_by_name' => 'صاحب الورشة', 'signature_id' => staffUpload($this, $coord, 'sig.png'),
    ])->assertCreated()->json('data');
    expect($done['source'])->toBe('walk_in')->and($done['intake']['number'])->toMatch('/^RC-/');
});

it('lets the customer cancel before work starts and cancels the open order (4.4, N-29)', function () {
    $tech = staffUser(['technician'], 'tech1@dwahi.test');
    $c = customerToken($this);
    $sr = serviceRequest($this, $c);
    $coord = staffToken($this, 'service_coordinator');
    $wo = asUser($this, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/triage", ['route' => 'inspection', 'urgency' => 'normal', 'branch_id' => DB::table('branches')->value('id')])
        ->json('data.work_orders.0.id');
    $start = now()->addDay();
    asUser($this, $coord)->postJson(api()."/admin/work-orders/{$wo}/schedule", ['lead_id' => $tech->id, 'start' => $start->toIso8601String(), 'end' => $start->copy()->addHours(2)->toIso8601String()])->assertOk();

    // قيد التنفيذ: لا إلغاء من العميل
    WorkOrder::query()->whereKey($wo)->update(['status' => 'in_progress']);
    asUser($this, $c)->postJson(api()."/me/service-requests/{$sr['id']}/cancel")->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_WORK_STARTED');
    WorkOrder::query()->whereKey($wo)->update(['status' => 'scheduled']);

    asUser($this, $c)->postJson(api()."/me/service-requests/{$sr['id']}/cancel", ['reason' => 'أصلحناها بأنفسنا'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(WorkOrder::query()->findOrFail($wo)->status)->toBe('cancelled');
});

it('alerts once when a request passes its triage deadline (J-04, N-09)', function () {
    staffUser(['service_coordinator'], 'coord@dwahi.test');
    $c = customerToken($this);
    $sr = serviceRequest($this, $c, ['urgency' => 'urgent']);
    $this->artisan('service:run sla')->assertSuccessful();
    expect(notified('N-09'))->toBe(0);
    $this->travelTo(ServiceRequest::query()->findOrFail($sr['id'])->sla_due_at->copy()->addMinute());
    $this->artisan('service:run sla')->assertSuccessful();
    $this->artisan('service:run sla')->assertSuccessful();
    expect(notified('N-09'))->toBeGreaterThanOrEqual(1)
        ->and(DB::table('notifications')->where('event_code', 'N-09')->distinct()->count('user_id'))->toBe(notified('N-09'));
});

it('blocks account deletion while a service request is open', function () {
    $c = customerToken($this);
    $sr = serviceRequest($this, $c);
    $check = asUser($this, $c)->getJson(api().'/me/deletion')->json('data');
    expect(collect($check['requests'])->pluck('number'))->toContain($sr['number']);
});
