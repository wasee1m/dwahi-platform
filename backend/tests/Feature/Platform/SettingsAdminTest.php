<?php

use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Setting;
use Illuminate\Support\Facades\DB;

// A-18 (FR-ADM-02، FR-ADM-03، US-40)

function adminToken($test): string
{
    staffUser();

    return adminSignIn($test)['access_token'];
}

it('saves settings with before and after in the audit log (US-40)', function () {
    $t = adminToken($this);
    $rows = collect($this->withToken($t)->getJson(api().'/admin/settings')->assertOk()->json('data'))->keyBy('key');
    expect($rows['quote.validity_days']['value'])->toBe(14)->and($rows['quote.validity_days']['group'])->toBe('quotes');

    $fees = $rows['delivery.fee_by_governorate']['value'];
    $fees['dhofar'] = '25';
    $this->withToken($t)->putJson(api().'/admin/settings', ['values' => ['quote.validity_days' => 10, 'delivery.fee_by_governorate' => $fees]])->assertOk();

    expect(Setting::read('quote.validity_days'))->toBe(10)
        ->and(Setting::read('delivery.fee_by_governorate')['dhofar'])->toBe('25.000');
    $audit = DB::table('audit_logs')->where('action', 'setting.changed')->get()->map(fn ($r) => [json_decode($r->before, true), json_decode($r->after, true)]);
    expect($audit)->toContain([['quote.validity_days' => 14], ['quote.validity_days' => 10]]);

    // لم يتغير: لا سطر تدقيق جديد
    $this->withToken($t)->putJson(api().'/admin/settings', ['values' => ['quote.validity_days' => 10]])->assertOk();
    expect(DB::table('audit_logs')->where('action', 'setting.changed')->count())->toBe(2);
});

it('rejects invalid and read-only settings with errors per field', function () {
    $t = adminToken($this);
    $this->withToken($t)->putJson(api().'/admin/settings', ['values' => [
        'parts.availability_thresholds' => ['limited' => 5, 'available' => 2],
        'business_hours' => ['days' => ['sun'], 'start' => '17:00', 'end' => '08:00'],
        'numbering.prefixes' => ['X'],
        'unknown.key' => 1,
    ]])->assertStatus(422)->assertJsonValidationErrors([
        'values.parts.availability_thresholds.available', 'values.business_hours.end', 'values.numbering.prefixes', 'values.unknown.key',
    ], 'error.details');
});

it('keeps settings to admins', function () {
    $this->withToken(staffToken($this, 'sales'))->getJson(api().'/admin/settings')->assertForbidden();
});

it('adds a branch with its location and hours, shown on the public branches list (FR-ADM-02)', function () {
    $t = adminToken($this);
    $closed = ['fri' => null, 'sat' => null];
    $open = ['open' => '08:00', 'close' => '17:00'];
    $body = [
        'code' => 'MCT', 'name_ar' => 'فرع مسقط', 'name_en' => 'Muscat branch', 'governorate' => 'muscat', 'wilayat' => 'bawshar',
        'latitude' => 23.5880, 'longitude' => 58.3829, 'phone' => '2412 3456', 'whatsapp_sales' => '9123 4567',
        'working_hours' => $closed + array_fill_keys(['sun', 'mon', 'tue', 'wed', 'thu'], $open),
        'covered_governorates' => ['muscat', 'dakhiliyah'],
    ];

    $this->withToken($t)->postJson(api().'/admin/branches', ['latitude' => 58.38, 'longitude' => 23.58] + $body)
        ->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude'], 'error.details');

    $b = $this->withToken($t)->postJson(api().'/admin/branches', $body)->assertCreated()->json('data');
    expect($b['latitude'])->toEqualWithDelta(23.588, 0.0001)->and($b['phone'])->toBe('+96824123456')->and($b['working_hours']['fri'])->toBeNull();

    $this->withToken($t)->putJson(api()."/admin/branches/{$b['id']}", ['is_active' => false] + collect($body)->except('code')->all())
        ->assertOk()->assertJsonPath('data.is_active', false);
    expect(collect($this->getJson(api().'/public/branches')->json('data'))->pluck('code'))->not->toContain('MCT')
        ->and(Branch::query()->count())->toBe(2);
});
