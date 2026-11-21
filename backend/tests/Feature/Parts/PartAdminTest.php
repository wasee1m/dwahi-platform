<?php

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\EquipmentModel;
use App\Modules\Parts\Models\Part;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// A-04 (FR-PRT-04، US-37)

function partBody(array $over = []): array
{
    return $over + [
        'brand_id' => Brand::query()->where('slug', 'komatsu')->value('id'), 'part_number' => '6754-71-6140',
        'name_ar' => 'فلتر هواء', 'name_en' => 'Air filter', 'unit' => 'piece', 'price' => '18.5', 'vat_rate' => '5',
        'is_returnable' => true, 'is_active' => true, 'alternatives' => [['number' => 'SMP-6140', 'brand' => 'Sample']],
        'compatible_model_ids' => [EquipmentModel::query()->where('slug', 'komatsu-pc200-8')->value('id')],
    ];
}

it('lets parts staff add a part with alternatives and compatible models', function () {
    $t = staffToken($this, 'parts_staff');
    $this->withToken($t)->postJson(api().'/admin/parts', partBody())
        ->assertCreated()->assertJsonPath('data.price', '18.500')
        ->assertJsonPath('data.alternatives.0.number', 'SMP-6140')
        ->assertJsonPath('data.compatible_models.0.name', 'Komatsu PC200-8');

    // نفس الرقم بصيغة مختلفة لنفس الماركة = مكرر
    $this->withToken($t)->postJson(api().'/admin/parts', partBody(['part_number' => '6754716140']))
        ->assertStatus(422)->assertJsonValidationErrors(['part_number'], 'error.details');

    // يظهر في البحث العام بالرقم البديل
    $this->getJson(api().'/parts/search?q=SMP-6140')->assertOk()->assertJsonPath('data.0.part_number', '6754-71-6140');
});

it('receives stock and adjusts it only with a reason, in the ledger and audit (US-37)', function () {
    $t = staffToken($this, 'parts_staff');
    $part = Part::query()->where('part_number', '600-211-1340')->value('id'); // مخزونها صفر
    $location = DB::table('stock_locations')->value('id');

    $this->withToken($t)->postJson(api().'/admin/stock/receipts', ['location_id' => $location, 'lines' => [['part_id' => $part, 'qty' => '10']]])
        ->assertCreated();
    $this->withToken($t)->postJson(api().'/admin/stock/adjustments', ['part_id' => $part, 'location_id' => $location, 'qty_delta' => '-2'])
        ->assertStatus(422)->assertJsonValidationErrors(['reason'], 'error.details');
    $detail = $this->withToken($t)->postJson(api().'/admin/stock/adjustments', [
        'part_id' => $part, 'location_id' => $location, 'qty_delta' => '-2', 'reason' => 'تالف',
    ])->assertCreated()->json('data');

    expect($detail['stock'][0]['on_hand'])->toBe('8.00')
        ->and(collect($detail['movements'])->pluck('reason')->all())->toBe(['adjustment', 'receipt'])
        ->and(DB::table('audit_logs')->where('action', 'stock.adjusted')->exists())->toBeTrue();

    // لا مخزون سالب
    $this->withToken($t)->postJson(api().'/admin/stock/adjustments', ['part_id' => $part, 'location_id' => $location, 'qty_delta' => '-50', 'reason' => 'x'])
        ->assertStatus(422)->assertJsonPath('error.code', 'STOCK_INSUFFICIENT');
    expect(DB::table('stock_levels')->where('part_id', $part)->value('qty_on_hand'))->toBe('8.00');
});

it('previews an Excel/CSV import and applies it all or nothing (US-37)', function () {
    $t = staffToken($this, 'parts_staff');
    $csv = fn (string $rows) => UploadedFile::fake()->createWithContent('parts.csv',
        "\xEF\xBB\xBFbrand,part_number,name_ar,name_en,price,unit,alt_numbers\n".$rows);

    $preview = $this->withToken($t)->post(api().'/admin/parts/import/preview', ['file' => $csv(
        "Caterpillar,1R-0750,فلتر ديزل,Fuel filter,7.250,piece,\n".   // موجودة: تحديث
        "Komatsu,KM-NEW-1,قطعة جديدة,New part,12,piece,ALT-9;ALT-10\n". // جديدة
        "Nobrand,X-1,خطأ,Bad,5,piece,\n".                               // ماركة غير معروفة
        "Komatsu,KM-NEW-1,مكرر,Dup,abc,piece,\n"                        // مكرر وسعر غير صالح
    )], ['Accept' => 'application/json'])->assertOk()->json('data');

    expect($preview['summary'])->toBe(['new' => 1, 'updated' => 1, 'errors' => 2, 'total' => 4]);
    $this->withToken($t)->postJson(api().'/admin/parts/import/commit', ['token' => $preview['token']])
        ->assertStatus(422)->assertJsonPath('error.code', 'IMPORT_HAS_ERRORS');
    expect(Part::query()->where('part_number', 'KM-NEW-1')->exists())->toBeFalse(); // لا شيء طُبق

    $ok = $this->withToken($t)->post(api().'/admin/parts/import/preview', ['file' => $csv(
        "Caterpillar,1R-0750,فلتر ديزل,Fuel filter,7.250,piece,\nKomatsu,KM-NEW-1,قطعة جديدة,New part,12,piece,ALT-9;ALT-10\n"
    )], ['Accept' => 'application/json'])->json('data');
    $this->withToken($t)->postJson(api().'/admin/parts/import/commit', ['token' => $ok['token']])
        ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);

    expect(Part::query()->where('part_number', '1R-0750')->value('price'))->toBe('7.250')
        ->and(DB::table('part_cross_references')->where('alt_number', 'ALT-10')->exists())->toBeTrue();
});

it('keeps parts admin to parts staff and admins', function () {
    $this->withToken(staffToken($this, 'sales'))->getJson(api().'/admin/parts')->assertForbidden();
});

it('lets parts staff read brands and models for compatibility, but not change them', function () {
    $t = staffToken($this, 'parts_staff');
    $this->withToken($t)->getJson(api().'/admin/models')->assertOk();
    $this->withToken($t)->getJson(api().'/admin/brands')->assertOk();
    $this->withToken($t)->postJson(api().'/admin/brands', ['name' => 'X'])->assertForbidden();
    $this->withToken($t)->getJson(api().'/admin/equipment')->assertForbidden();
});
