<?php

use App\Models\User;
use App\Modules\Notifications\Mail\DigestMail;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// FR-RPT-01، FR-RPT-03، US-41 (A-01، A-16)، J-10

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Setting::query()->updateOrCreate(['key' => 'fees.inspection'], ['value' => '15.000']);
});

/** طلب صيانة بتقدير قبله العميل: طلب عرض مقبول (won) وطلب صيانة من المنصة */
function acceptedEstimate($test): array
{
    $c = customerToken($test);
    $sr = serviceRequest($test, $c);
    $coord = staffToken($test, 'service_coordinator');
    asUser($test, $coord)->postJson(api()."/admin/service-requests/{$sr['id']}/triage", ['route' => 'direct_estimate', 'urgency' => 'normal', 'branch_id' => Branch::query()->value('id')])->assertOk();
    $quote = sendEstimate($test, $coord, $sr['id']);
    asUser($test, $c)->postJson(api()."/me/quotations/{$quote}/accept")->assertOk();

    return ['customer' => $c, 'coord' => $coord, 'sr' => $sr];
}

it('shows the admin the KPIs for the period with the attention list (US-41, FR-RPT-01)', function () {
    acceptedEstimate($this);
    staffUser();
    $admin = adminSignIn($this)['access_token'];
    // طلب متجاوز للمهلة
    DB::table('service_requests')->insert(DB::table('service_requests')->first() ? (array) collect((array) DB::table('service_requests')->first())
        ->merge(['id' => (string) Str::uuid7(), 'number' => 'SR-2026-999999', 'status' => 'submitted', 'sla_due_at' => now()->subHour(), 'triaged_at' => null])->all() : []);

    $d = asUser($this, $admin)->getJson(api().'/admin/dashboard')->assertOk()->json('data');
    expect($d['sections']['quotes']['rfqs'])->toBe(1)
        ->and($d['sections']['quotes']['acceptance_rate'])->toEqual(100)
        ->and($d['sections']['quotes']['median_response_hours'])->not->toBeNull()
        ->and($d['sections']['service']['sla_breached'])->toBe(1)
        ->and($d['sections']['service']['platform_share'])->toEqual(100)
        ->and($d['sections']['revenue']['invoiced']['amount'])->toBe('0.000')
        ->and($d['attention']['sla_breached'][0]['number'])->toBe('SR-2026-999999')
        ->and($d['series'])->not->toBeEmpty();

    // فترة سابقة بلا بيانات
    $old = asUser($this, $admin)->getJson(api().'/admin/dashboard?from=2025-01-01&to=2025-01-31')->assertOk()->json('data');
    expect($old['sections']['quotes']['rfqs'])->toBe(0)->and($old['series'])->toHaveCount(31);
    asUser($this, $admin)->getJson(api().'/admin/dashboard?from=2025-02-01&to=2025-01-01')->assertStatus(422);
});

it('shows each role only its sections, and limits branch staff to their branch (FR-RPT-01)', function () {
    acceptedEstimate($this);
    $sales = staffToken($this, 'sales');
    $d = asUser($this, $sales)->getJson(api().'/admin/dashboard')->assertOk()->json('data');
    expect(array_keys($d['sections']))->toBe(['quotes'])->and($d['branch_id'])->toBe(Branch::query()->value('id'));

    $other = Branch::query()->where('id', '!=', Branch::query()->value('id'))->value('id');
    if ($other) {
        $scoped = asUser($this, $sales)->getJson(api()."/admin/dashboard?branch_id={$other}")->json('data');
        expect($scoped['branch_id'])->toBe(Branch::query()->value('id'));
    }

    $accountant = staffToken($this, 'accountant');
    expect(array_keys(asUser($this, $accountant)->getJson(api().'/admin/dashboard')->json('data.sections')))->toBe(['revenue', 'ratings']);
    asUser($this, $accountant)->getJson(api().'/admin/reports/quotes')->assertForbidden();

    $editor = staffToken($this, 'content_editor');
    asUser($this, $editor)->getJson(api().'/admin/dashboard')->assertForbidden();
});

it('builds the ready-made reports with their summaries (A-16)', function () {
    $j = acceptedEstimate($this);
    staffUser();
    $admin = adminSignIn($this)['access_token'];

    $q = asUser($this, $admin)->getJson(api().'/admin/reports/quotes')->assertOk()->json('data');
    expect($q['columns'])->toContain('response_hours')->and($q['rows'][0]['status'])->toBe('مقبول')
        ->and($q['headers']['response_hours'])->toBe('زمن الرد (ساعات)')->and($q['summary']['acceptance_rate'])->toEqual(100);

    $s = asUser($this, $admin)->getJson(api().'/admin/reports/service')->assertOk()->json('data');
    expect($s['rows'][0]['number'])->toBe($j['sr']['number'])->and($s['summary']['open_by_age']['0-2'])->toBe(1);

    $out = asUser($this, $admin)->getJson(api().'/admin/reports/stockouts')->assertOk()->json('data');
    expect($out['summary']['count'])->toBeGreaterThanOrEqual(0);
    asUser($this, $admin)->getJson(api().'/admin/reports/nope')->assertNotFound();
});

it('exports a report to CSV or Excel with the current filters and records it in the audit log (FR-RPT-03)', function () {
    acceptedEstimate($this);
    staffUser();
    $admin = adminSignIn($this)['access_token'];

    $csv = asUser($this, $admin)->get(api().'/admin/reports/quotes/export?format=csv')->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');
    $body = $csv->streamedContent();
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($body)->toContain('زمن الرد (ساعات)')->and($body)->toContain('مقبول');

    $xlsx = asUser($this, $admin)->get(api().'/admin/reports/service/export?format=xlsx&from='.now('Asia/Muscat')->toDateString())->assertOk();
    expect($xlsx->headers->get('content-type'))->toContain('spreadsheetml')->and(substr($xlsx->streamedContent(), 0, 2))->toBe('PK');

    $audit = DB::table('audit_logs')->where('action', 'report.exported')->orderBy('created_at')->get();
    expect($audit)->toHaveCount(2)->and(json_decode($audit[0]->after, true)['report'])->toBe('quotes');
});

it('emails one digest of unread messages after 15 minutes, once, unless the user turned it off (J-10)', function () {
    Mail::fake();
    $c = customerToken($this);
    $user = User::query()->where('user_type', 'customer')->firstOrFail();
    $user->forceFill(['email' => 'c@example.com'])->save();
    $add = fn (int $minutesAgo, ?string $read = null) => DB::table('notifications')->insert([
        'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'event_code' => 'N-17', 'title' => 'رسالة جديدة', 'body' => 'هل القطعة متوفرة؟',
        'deep_link' => '/account/quotes/x#messages', 'data' => '{}', 'read_at' => $read, 'created_at' => now()->subMinutes($minutesAgo),
    ]);
    $add(20);
    $add(30);
    $add(5);          // لم يمض ربع ساعة
    $add(40, now());  // مقروءة

    $this->artisan('messages:run digest')->assertSuccessful();
    Mail::assertSent(DigestMail::class, 1);
    Mail::assertSent(DigestMail::class, fn (DigestMail $m) => count($m->rows) === 2 && $m->hasTo('c@example.com'));
    $this->artisan('messages:run digest')->assertSuccessful();
    Mail::assertSent(DigestMail::class, 1);

    // إيقافه من التفضيلات: لا بريد، ويُعلَّم ليُستبعد
    asUser($this, $c)->putJson(api().'/me/notification-preferences', ['preferences' => [['category' => 'messages', 'channel' => 'email', 'enabled' => false]]])->assertOk();
    $add(16);
    $this->artisan('messages:run digest')->assertSuccessful();
    Mail::assertSent(DigestMail::class, 1);
    expect(DB::table('notification_deliveries')->where('channel', 'email')->where('status', 'skipped')->count())->toBe(1);
});
