<?php

use App\Modules\Catalog\Models\EquipmentModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// A-02 (FR-CAT-06، FR-CAT-07، US-36)، ورفع الصور (NFR-11)

beforeEach(fn () => Storage::fake('public'));

function componentListing(array $over = []): array
{
    return $over + [
        'listing_kind' => 'component', 'component_type' => 'diesel_engine', 'component_model' => 'CAT 3306',
        'condition' => 'rebuilt', 'warranty_months' => 3, 'title_ar' => 'محرك كاتربيلر 3306', 'title_en' => 'Caterpillar 3306 engine',
        'price_visibility' => 'shown', 'price' => '3800', 'branch_code' => 'SOH',
    ];
}

it('lets sales create a component, upload photos and publish it (US-36)', function () {
    $t = staffToken($this, 'sales');

    $id = $this->withToken($t)->postJson(api().'/admin/equipment', componentListing())
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.price', '3800.000')->json('data.id');

    // لا نشر دون صورة (US-36)
    $this->withToken($t)->postJson(api()."/admin/equipment/{$id}/status", ['to' => 'published'])
        ->assertStatus(422)->assertJsonValidationErrors(['media'], 'error.details');

    $photo = $this->withToken($t)->post(api().'/admin/uploads', ['file' => UploadedFile::fake()->image('engine.jpg', 3000, 2000)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    expect($photo['width'])->toBe(2400)->and($photo['height'])->toBe(1600); // يُصغَّر ويُعاد ترميزه (NFR-11)

    $this->withToken($t)->putJson(api()."/admin/equipment/{$id}/media", ['attachment_ids' => [$photo['id']]])->assertOk();
    $this->withToken($t)->postJson(api()."/admin/equipment/{$id}/status", ['to' => 'published'])
        ->assertOk()->assertJsonPath('data.status', 'published');

    // تظهر في الكتالوج العام مع الضمان
    $this->getJson(api().'/equipment/caterpillar-3306-engine')->assertOk()->assertJsonPath('data.warranty_months', 3);
});

it('enforces the rules of each listing kind (BR-03)', function () {
    $t = staffToken($this, 'sales');
    $model = EquipmentModel::query()->value('id');

    // السكراب: الحالة تُفرض for_parts، ويُطلب ما ينقصها والسنة
    $this->withToken($t)->postJson(api().'/admin/equipment', [
        'listing_kind' => 'scrap', 'model_id' => $model, 'condition' => 'used',
        'title_ar' => 'سكراب', 'title_en' => 'Scrap', 'price_visibility' => 'on_request', 'branch_code' => 'SOH',
    ])->assertStatus(422)->assertJsonValidationErrors(['missing_parts', 'year'], 'error.details');

    // الآلة المستعملة: السنة والساعات إلزاميتان
    $this->withToken($t)->postJson(api().'/admin/equipment', [
        'listing_kind' => 'machine', 'model_id' => $model, 'condition' => 'used',
        'title_ar' => 'حفار', 'title_en' => 'Excavator', 'price_visibility' => 'on_request', 'branch_code' => 'SOH',
    ])->assertStatus(422)->assertJsonValidationErrors(['year', 'hour_meter'], 'error.details');

    // "سعر معروض" يتطلب السعر
    $this->withToken($t)->postJson(api().'/admin/equipment', componentListing(['price' => null]))
        ->assertStatus(422)->assertJsonValidationErrors(['price'], 'error.details');
});

it('audits price changes and requires a reason to reserve (US-36، BR-19)', function () {
    $t = staffToken($this, 'sales');
    $listing = DB::table('equipment_listings')->where('slug', 'volvo-bl71-2012')->first();
    $id = $listing->id;
    $body = $this->withToken($t)->getJson(api()."/admin/equipment/{$id}")->json('data');

    $this->withToken($t)->putJson(api()."/admin/equipment/{$id}", [...$body, 'branch_code' => 'SOH', 'price' => '13900'])->assertOk();
    $audit = DB::table('audit_logs')->where('action', 'equipment.price_changed')->where('entity_id', $id)->first();
    expect(json_decode($audit->before, true)['price'])->toBe('14500.000')
        ->and(json_decode($audit->after, true)['price'])->toBe('13900.000');

    $this->withToken($t)->postJson(api()."/admin/equipment/{$id}/status", ['to' => 'reserved'])
        ->assertStatus(422)->assertJsonValidationErrors(['reason'], 'error.details');
    $this->withToken($t)->postJson(api()."/admin/equipment/{$id}/status", ['to' => 'reserved', 'reason' => 'صفقة بالهاتف'])
        ->assertOk()->assertJsonPath('data.status', 'reserved');
    $this->withToken($t)->postJson(api()."/admin/equipment/{$id}/status", ['to' => 'sold'])->assertOk();

    // المباع لا يعود منشوراً
    $this->withToken($t)->postJson(api()."/admin/equipment/{$id}/status", ['to' => 'published'])
        ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION');
});

it('manages brands, categories and models (A-03)', function () {
    $t = staffToken($this, 'sales');
    $brand = $this->withToken($t)->postJson(api().'/admin/brands', ['name' => 'Hyundai', 'is_represented' => true])
        ->assertCreated()->assertJsonPath('data.slug', 'hyundai')->json('data.id');
    $cat = $this->withToken($t)->postJson(api().'/admin/categories', ['name_ar' => 'رافعات شوكية', 'name_en' => 'Forklifts'])
        ->assertCreated()->json('data.id');
    $this->withToken($t)->postJson(api().'/admin/models', [
        'brand_id' => $brand, 'category_id' => $cat, 'model_name' => '30D-9', 'lifting_capacity_kg' => 3000,
        'specs' => [['label_ar' => 'نوع الوقود', 'label_en' => 'Fuel', 'value' => 'Diesel']],
    ])->assertCreated()->assertJsonPath('data.slug', 'hyundai-30d-9');

    $this->withToken($t)->postJson(api().'/admin/models', ['brand_id' => $brand, 'category_id' => $cat, 'model_name' => '30D-9'])
        ->assertStatus(422)->assertJsonValidationErrors(['model_name'], 'error.details');
});

it('keeps the catalog admin to sales and admins', function () {
    $this->withToken(staffToken($this, 'parts_staff'))->getJson(api().'/admin/equipment')->assertForbidden();
});

it('rejects files that are not images or PDFs, by content not extension', function () {
    $t = staffToken($this, 'sales');
    $fake = UploadedFile::fake()->createWithContent('photo.jpg', "<?php echo 'x';");
    $this->withToken($t)->post(api().'/admin/uploads', ['file' => $fake], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
});
