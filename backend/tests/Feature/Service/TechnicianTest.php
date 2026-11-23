<?php

use App\Modules\Parts\Models\Part;
use App\Modules\Platform\Models\Setting;
use App\Modules\Service\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// SM-5 (5.7 إلى 5.9)، FR-TEC-01..04، FR-TEC-07، FR-WO-03، FR-WO-04، BR-20، 04 §6 (الصندوق الصادر)، N-13

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Setting::query()->updateOrCreate(['key' => 'fees.inspection'], ['value' => '15.000']);
});

it('lists only my tasks for today and opens one by scanning its receiving card (US-24, T-02, T-03, FR-TEC-01)', function () {
    $t = techTask($this);
    $same = fn (array $o = []) => techTask($this, $o + ['tech' => $t['tech'], 'token' => $t['token'], 'coord' => $t['coord'], 'customer' => $t['customer']]);
    $later = $same(['start' => now()->addDays(3)]);

    $list = asUser($this, $t['token'])->getJson(api().'/tech/tasks')->assertOk()->json('data');
    expect(collect($list['today'])->pluck('id')->all())->toBe([$t['wo']])
        ->and(collect($list['upcoming'])->pluck('id')->all())->toBe([$later['wo']])
        ->and($list['today'][0]['received'])->toBeTrue()->and($list['today'][0]['my_role'])->toBe('lead')
        ->and($list['server_time'])->not->toBeNull();

    $d = asUser($this, $t['token'])->getJson(api()."/tech/tasks/{$t['wo']}")->assertOk()->json('data');
    expect($d['problem_description'])->toBe('تسريب زيت من سلندر البوم')->and($d['intake']['hour_meter_reading'])->toBe(9120)
        ->and($d['equipment']['current_hour_meter'])->toBe(9120);

    asUser($this, $t['token'])->getJson(api().'/tech/tasks/by-tag/'.strtolower($d['card']))->assertOk()->assertJsonPath('data.id', $t['wo']);

    // فني آخر لا يرى المهمة ولا يفتحها بالملصق
    $other = staffUser(['technician'], 'other@dwahi.test');
    $otherToken = techToken($this, $other);
    expect(asUser($this, $otherToken)->getJson(api().'/tech/tasks')->json('data.today'))->toBe([]);
    asUser($this, $otherToken)->getJson(api()."/tech/tasks/{$t['wo']}")->assertNotFound();
    asUser($this, $otherToken)->getJson(api().'/tech/tasks/by-tag/'.$d['card'])->assertNotFound()->assertJsonPath('error.code', 'NOT_YOUR_TASK');

    // جلسة عميل لا تدخل واجهة الفني
    asUser($this, $t['customer'])->getJson(api().'/tech/tasks')->assertForbidden();
});

it('starts only received tasks due today, one at a time (BR-20, FR-TEC-03, 5.7)', function () {
    $t = techTask($this, ['receive' => false]);
    $r = sync($this, $t['token'], [ev($t['wo'], 'status_changed', ['to' => 'in_progress'])]);
    expect($r[0]['result'])->toBe('rejected')->and($r[0]['code'])->toBe('NOT_RECEIVED')->and($r[0]['message'])->not->toBeEmpty();

    $same = fn (array $o = []) => techTask($this, $o + ['tech' => $t['tech'], 'token' => $t['token'], 'coord' => $t['coord'], 'customer' => $t['customer']]);
    $future = $same(['start' => now()->addDays(2)]);
    expect(sync($this, $t['token'], [ev($future['wo'], 'status_changed', ['to' => 'in_progress'])])[0]['code'])->toBe('NOT_TODAY');

    [$a, $b] = [$same(), $same()];
    expect(sync($this, $t['token'], [ev($a['wo'], 'status_changed', ['to' => 'in_progress'])])[0]['result'])->toBe('applied');
    expect(sync($this, $t['token'], [ev($b['wo'], 'status_changed', ['to' => 'in_progress'])])[0]['code'])->toBe('ANOTHER_TASK_ACTIVE');
    expect(WorkOrder::query()->findOrFail($a['wo'])->status)->toBe('in_progress');
});

it('pauses with a reason, resumes, and counts only working time (5.8, 5.9, FR-WO-03, N-13)', function () {
    $t = techTask($this);
    $at = fn (int $minutesAgo) => now()->subMinutes($minutesAgo)->toIso8601ZuluString();
    $r = sync($this, $t['token'], [
        ev($t['wo'], 'status_changed', ['to' => 'in_progress'], $at(120)),
        ev($t['wo'], 'status_changed', ['to' => 'on_hold', 'reason' => 'awaiting_parts', 'note' => 'جوان 80 ملم'], $at(90)),
        ev($t['wo'], 'status_changed', ['to' => 'in_progress'], $at(30)),
        ev($t['wo'], 'status_changed', ['to' => 'on_hold', 'reason' => 'end_of_shift'], $at(0)),
    ]);
    expect(collect($r)->pluck('result')->unique()->all())->toBe(['applied']);

    $d = asUser($this, $t['token'])->getJson(api()."/tech/tasks/{$t['wo']}")->json('data');
    expect($d['status'])->toBe('on_hold')->and($d['on_hold_reason'])->toBe('end_of_shift')
        ->and($d['worked_seconds'])->toBeGreaterThanOrEqual(3599)->toBeLessThanOrEqual(3601)
        ->and(collect($d['events'])->pluck('to')->filter(fn ($s) => in_array($s, ['in_progress', 'on_hold'], true))->values()->all())
        ->toBe(['in_progress', 'on_hold', 'in_progress', 'on_hold']);
    // N-13 للمنسق مرة واحدة: نهاية الدوام لا تحتاج تدخلاً
    expect(notified('N-13'))->toBe(1);

    // سبب خارج القائمة
    sync($this, $t['token'], [ev($t['wo'], 'status_changed', ['to' => 'in_progress'])]);
    expect(sync($this, $t['token'], [ev($t['wo'], 'status_changed', ['to' => 'on_hold', 'reason' => 'tea'])])[0]['code'])->toBe('VALIDATION_FAILED');
});

it('applies each event once and replaces a device clock that runs ahead (04 §6.2, §6.4)', function () {
    $t = techTask($this);
    $batch = [ev($t['wo'], 'status_changed', ['to' => 'in_progress'], now()->addHour()->toIso8601ZuluString())];
    expect(sync($this, $t['token'], $batch)[0]['result'])->toBe('applied');
    expect(sync($this, $t['token'], $batch)[0]['result'])->toBe('duplicate');

    expect(DB::table('work_order_status_events')->where('work_order_id', $t['wo'])->where('to_status', 'in_progress')->count())->toBe(1);
    $row = DB::table('tech_sync_events')->where('client_event_id', $batch[0]['client_event_id'])->first();
    expect((bool) $row->clock_adjusted)->toBeTrue()->and($row->device_id)->not->toBeNull()
        ->and(abs(strtotime($row->occurred_at.' UTC') - time()))->toBeLessThan(60);
});

it('keeps photos from a reassigned technician but rejects their status change (04 §6.4, FR-TEC-04)', function () {
    $t = techTask($this);
    $photo = techUpload($this, $t['token']);
    $other = staffUser(['technician'], 'other@dwahi.test');
    asUser($this, $t['coord'])->postJson(api()."/admin/work-orders/{$t['wo']}/schedule", [
        'lead_id' => $other->id, 'start' => now()->toIso8601String(), 'end' => now()->addHours(2)->toIso8601String(), 'reason' => 'إجازة', 'force' => true,
    ])->assertOk();

    $r = sync($this, $t['token'], [
        ev($t['wo'], 'photo_added', ['attachment_id' => $photo, 'phase' => 'before']),
        ev($t['wo'], 'status_changed', ['to' => 'in_progress']),
    ]);
    expect($r[0]['result'])->toBe('applied')->and($r[1]['code'])->toBe('NOT_ASSIGNED');
    expect((bool) DB::table('work_order_photos')->where('attachment_id', $photo)->value('from_previous_technician'))->toBeTrue();
    // المهمة تبقى مقروءة له بحالتها، ولا تظهر في قائمته
    asUser($this, $t['token'])->getJson(api()."/tech/tasks/{$t['wo']}")->assertOk()->assertJsonPath('data.my_role', null);
    expect(asUser($this, $t['token'])->getJson(api().'/tech/tasks')->json('data.today'))->toBe([]);

    // من لم يُسند إليه يوماً لا يضيف شيئاً
    $stranger = staffUser(['technician'], 'stranger@dwahi.test');
    $st = techToken($this, $stranger);
    expect(sync($this, $st, [ev($t['wo'], 'photo_added', ['attachment_id' => techUpload($this, $st), 'phase' => 'before'])])[0]['code'])->toBe('NOT_ASSIGNED');
});

it('takes parts from the branch store at the current price and returns them when removed (FR-WO-04, T-07)', function () {
    $t = techTask($this);
    $part = Part::query()->where('part_number', '1R-0750')->firstOrFail();
    $branch = (string) WorkOrder::query()->findOrFail($t['wo'])->branch_id;
    $loc = (string) Str::uuid7();
    DB::table('stock_locations')->insert(['id' => $loc, 'branch_id' => $branch, 'name' => 'مخزن الورشة', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('stock_levels')->insert(['part_id' => $part->id, 'location_id' => $loc, 'qty_on_hand' => 5, 'qty_reserved' => 1]);
    $levels = fn () => DB::table('stock_levels')->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
        ->where('stock_locations.branch_id', $branch)->where('part_id', $part->id);
    $onHand = fn () => (float) $levels()->sum('qty_on_hand');
    $before = $onHand();
    $free = $before - (float) $levels()->sum('qty_reserved');

    $found = asUser($this, $t['token'])->getJson(api().'/tech/parts?q=1r0750')->assertOk()->json('data');
    expect($found[0]['part_number'])->toBe('1R-0750')->and((float) $found[0]['available'])->toBe($free);

    $line = (string) Str::uuid();
    $r = sync($this, $t['token'], [
        ev($t['wo'], 'status_changed', ['to' => 'in_progress']),
        ev($t['wo'], 'part_used', ['id' => $line, 'part_id' => $part->id, 'quantity' => '2', 'source' => 'store']),
        ev($t['wo'], 'part_used', ['id' => (string) Str::uuid(), 'part_id' => $part->id, 'quantity' => '999', 'source' => 'store']),
        ev($t['wo'], 'part_used', ['id' => (string) Str::uuid(), 'quantity' => '1', 'source' => 'customer_supplied']),
        ev($t['wo'], 'part_used', ['id' => (string) Str::uuid(), 'quantity' => '1', 'source' => 'customer_supplied', 'description' => 'جوان من العميل']),
    ]);
    expect(collect($r)->pluck('code')->all())->toBe([null, null, 'STOCK_INSUFFICIENT', 'VALIDATION_FAILED', null]);
    expect($onHand())->toBe($before - 2)
        ->and((string) DB::table('work_order_parts')->where('id', $line)->value('unit_price'))->toBe((string) $part->price)
        ->and(DB::table('stock_movements')->where('work_order_id', $t['wo'])->value('reason'))->toBe('work_order');

    $parts = asUser($this, $t['token'])->getJson(api()."/tech/tasks/{$t['wo']}")->json('data.parts');
    expect(collect($parts)->pluck('name')->all())->toContain('جوان من العميل');

    sync($this, $t['token'], [ev($t['wo'], 'part_removed', ['id' => $line])]);
    expect($onHand())->toBe($before)->and(DB::table('work_order_parts')->where('id', $line)->value('removed_at'))->not->toBeNull();
});

it('records the hour meter with its photo and flags a lower reading for review (FR-TEC-04)', function () {
    $t = techTask($this);
    sync($this, $t['token'], [ev($t['wo'], 'status_changed', ['to' => 'in_progress'])]);
    $photo = techUpload($this, $t['token'], 'meter.jpg');
    $r = sync($this, $t['token'], [
        ev($t['wo'], 'hour_meter_recorded', ['reading' => 9200, 'photo_id' => $photo]),
        ev($t['wo'], 'hour_meter_recorded', ['reading' => 9100]),
        ev($t['wo'], 'note_added', ['body' => 'التسريب من الجوان الداخلي']),
    ]);
    expect(collect($r)->pluck('result')->unique()->all())->toBe(['applied']);

    $eq = DB::table('customer_equipment')->where('id', DB::table('service_requests')->where('id', $t['sr']['id'])->value('customer_equipment_id'))->first();
    expect((int) $eq->current_hour_meter)->toBe(9200)
        ->and(DB::table('hour_meter_readings')->where('work_order_id', $t['wo'])->where('needs_review', true)->value('reading'))->toBe(9100)
        ->and(DB::table('work_order_photos')->where('attachment_id', $photo)->value('phase'))->toBe('hour_meter')
        ->and(DB::table('work_order_notes')->where('work_order_id', $t['wo'])->value('body'))->toBe('التسريب من الجوان الداخلي');
});
