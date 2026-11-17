<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\EquipmentCategory;
use App\Modules\Catalog\Models\EquipmentListing;
use App\Modules\Catalog\Models\EquipmentModel;
use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Models\StockLocation;
use App\Modules\Platform\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * بيانات تجريبية للتطوير فقط: الأسعار والكميات والأرقام البديلة مختلَقة.
 * لا تُشغَّل في الإنتاج، والكتالوج الحقيقي يأتي من ملفات الشركة (09 §9).
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (Brand::query()->exists()) {
            return;
        }

        DB::transaction(fn () => $this->seed());
    }

    private function seed(): void
    {
        $importer = User::query()->create([
            'user_type' => 'staff', 'full_name' => 'Demo importer', 'email' => 'demo-importer@dwahi.test', 'status' => 'active',
        ]);
        $branch = Branch::query()->where('code', 'SOH')->firstOrFail();

        $brands = collect(['caterpillar' => 'Caterpillar', 'komatsu' => 'Komatsu', 'volvo' => 'Volvo', 'jcb' => 'JCB', 'generic' => 'Generic'])
            ->map(fn ($name, $slug) => Brand::query()->create(['name' => $name, 'slug' => $slug, 'is_active' => $slug !== 'generic']));

        $cats = collect([
            'excavators' => ['حفارات', 'Excavators'],
            'backhoe-loaders' => ['باكهو لودر', 'Backhoe loaders'],
            'wheel-loaders' => ['شيولات', 'Wheel loaders'],
            'dump-trucks' => ['شاحنات قلابة', 'Dump trucks'],
        ])->map(fn ($n, $slug) => EquipmentCategory::query()->create(['slug' => $slug, 'name_ar' => $n[0], 'name_en' => $n[1]]));

        $model = fn ($brand, $cat, $name, $weight = null, $kw = null) => EquipmentModel::query()->create([
            'brand_id' => $brands[$brand]->id, 'category_id' => $cats[$cat]->id, 'model_name' => $name,
            'slug' => Str::slug("{$brand} {$name}"), 'operating_weight_kg' => $weight, 'engine_power_kw' => $kw,
            'specs' => [['key' => 'origin', 'label_ar' => 'بلد المنشأ', 'label_en' => 'Origin', 'value' => '—']],
        ]);
        $m = [
            'ec210' => $model('volvo', 'excavators', 'EC210', 21000, 107),
            'bl71' => $model('volvo', 'backhoe-loaders', 'BL71', 8200, 74),
            '320d' => $model('caterpillar', 'excavators', '320D', 21500, 110),
            '950h' => $model('caterpillar', 'wheel-loaders', '950H', 18300, 147),
            '3cx' => $model('jcb', 'backhoe-loaders', '3CX', 8070, 68),
            'pc200' => $model('komatsu', 'excavators', 'PC200-8', 19800, 110),
        ];

        $listing = function (array $a) use ($branch, $importer) {
            return EquipmentListing::query()->create($a + [
                'branch_id' => $branch->id, 'created_by' => $importer->id, 'status' => 'published',
                'published_at' => now()->subDays(random_int(1, 40)), 'listing_kind' => 'machine',
            ]);
        };

        $listing(['model_id' => $m['bl71']->id, 'condition' => 'used', 'year' => 2012, 'hour_meter' => 9800, 'slug' => 'volvo-bl71-2012',
            'title_ar' => 'باكهو فولفو BL71 موديل 2012', 'title_en' => 'Volvo BL71 backhoe loader, 2012',
            'description_ar' => 'باكهو بحالة تشغيل جيدة، فُحص في الورشة.', 'description_en' => 'Backhoe in good working order, inspected in our workshop.',
            'price' => '14500.000', 'price_visibility' => 'shown', 'is_featured' => true]);
        $listing(['model_id' => $m['ec210']->id, 'condition' => 'used', 'year' => 2010, 'hour_meter' => 11200, 'slug' => 'volvo-ec210-2010',
            'title_ar' => 'حفار فولفو EC210 موديل 2010', 'title_en' => 'Volvo EC210 excavator, 2010',
            'price_visibility' => 'on_request', 'is_featured' => true]);
        $listing(['model_id' => $m['3cx']->id, 'condition' => 'new', 'year' => 2026, 'slug' => 'jcb-3cx-new',
            'title_ar' => 'باكهو JCB 3CX جديد', 'title_en' => 'New JCB 3CX backhoe loader',
            'price_visibility' => 'on_request', 'warranty_months' => 12, 'is_featured' => true]);
        $listing(['model_id' => $m['950h']->id, 'condition' => 'used', 'year' => 2008, 'hour_meter' => 15400, 'slug' => 'cat-950h-2008',
            'title_ar' => 'شيول كاتربيلر 950H موديل 2008', 'title_en' => 'Caterpillar 950H wheel loader, 2008',
            'price' => '21000.000', 'price_visibility' => 'shown']);
        $listing(['model_id' => $m['320d']->id, 'condition' => 'used', 'year' => 2011, 'hour_meter' => 10100, 'slug' => 'cat-320d-2011',
            'title_ar' => 'حفار كاتربيلر 320D موديل 2011', 'title_en' => 'Caterpillar 320D excavator, 2011',
            'price' => '19500.000', 'price_visibility' => 'shown', 'status' => 'reserved']);
        $listing(['listing_kind' => 'component', 'component_type' => 'diesel_engine', 'component_model' => 'CAT 3306', 'condition' => 'rebuilt',
            'source_machine' => 'شيول CAT 950', 'warranty_months' => 3, 'slug' => 'cat-3306-engine-rebuilt',
            'title_ar' => 'محرك كاتربيلر 3306 مجدد', 'title_en' => 'Rebuilt Caterpillar 3306 engine',
            'price' => '3800.000', 'price_visibility' => 'shown', 'is_featured' => true]);
        $listing(['listing_kind' => 'component', 'component_type' => 'transmission', 'component_model' => 'ZF 4WG200', 'condition' => 'used',
            'slug' => 'zf-4wg200-transmission', 'title_ar' => 'جيربوكس ZF 4WG200 مستعمل', 'title_en' => 'Used ZF 4WG200 transmission',
            'price_visibility' => 'on_request']);
        $listing(['listing_kind' => 'component', 'component_type' => 'hydraulic_pump', 'component_model' => 'Kawasaki K3V112', 'condition' => 'used',
            'slug' => 'kawasaki-k3v112-pump', 'title_ar' => 'مضخة هيدروليك كاواساكي K3V112', 'title_en' => 'Kawasaki K3V112 hydraulic pump',
            'price' => '950.000', 'price_visibility' => 'shown']);
        $listing(['listing_kind' => 'scrap', 'model_id' => $m['pc200']->id, 'condition' => 'for_parts', 'year' => 2006,
            'missing_parts' => 'بدون محرك وجير', 'slug' => 'komatsu-pc200-scrap', 'is_featured' => true,
            'title_ar' => 'حفار كوماتسو PC200-8 سكراب للقطع', 'title_en' => 'Komatsu PC200-8 excavator, scrap for parts',
            'price_visibility' => 'on_request']);

        $location = StockLocation::query()->create(['branch_id' => $branch->id, 'name' => 'المخزن الرئيسي']);

        $part = function (string $brand, string $number, string $ar, string $en, string $price, int $qty, array $extra = []) use ($brands, $location) {
            $p = Part::query()->create($extra + [
                'brand_id' => $brands[$brand]->id, 'part_number' => $number, 'name_ar' => $ar, 'name_en' => $en,
                'unit' => 'piece', 'price' => $price, 'vat_rate' => '5.00',
            ]);
            DB::table('stock_levels')->insert(['part_id' => $p->id, 'location_id' => $location->id, 'qty_on_hand' => $qty, 'qty_reserved' => 0]);

            return $p;
        };

        $fuel = $part('caterpillar', '1R-0750', 'فلتر ديزل', 'Fuel filter', '6.500', 12, ['category' => 'filters']);
        $oil = $part('caterpillar', '1R-1808', 'فلتر زيت محرك', 'Engine oil filter', '7.800', 3, ['category' => 'filters']);
        $kOil = $part('komatsu', '600-211-1340', 'فلتر زيت محرك', 'Engine oil filter', '5.200', 0, ['category' => 'filters']);
        $part('generic', '2XL-KIT', 'نظام قلاب هيدروليكي 2XL مع المضخة', '2XL hydraulic tipper kit with pump', '1450.000', 2,
            ['category' => 'hydraulics', 'unit' => 'set', 'warranty_months' => 12, 'is_returnable' => false]);
        $part('generic', '3XL-KIT', 'نظام قلاب هيدروليكي 3XL مع المضخة', '3XL hydraulic tipper kit with pump', '1850.000', 1,
            ['category' => 'hydraulics', 'unit' => 'set', 'warranty_months' => 12, 'is_returnable' => false]);
        $seal = $part('generic', 'SK-PC200-BKT', 'طقم جوانات سلندر البوكت PC200', 'PC200 bucket cylinder seal kit', '38.000', 8,
            ['category' => 'hydraulics', 'unit' => 'set']);

        DB::table('part_cross_references')->insert([
            ['id' => (string) Str::uuid7(), 'part_id' => $fuel->id, 'alt_number' => 'SMP-0750-A', 'alt_brand' => 'Sample'],
            ['id' => (string) Str::uuid7(), 'part_id' => $oil->id, 'alt_number' => 'SMP-1808-B', 'alt_brand' => 'Sample'],
        ]);
        DB::table('part_compatibility')->insert([
            ['part_id' => $fuel->id, 'model_id' => $m['320d']->id, 'notes' => null],
            ['part_id' => $fuel->id, 'model_id' => $m['950h']->id, 'notes' => null],
            ['part_id' => $kOil->id, 'model_id' => $m['pc200']->id, 'notes' => null],
            ['part_id' => $seal->id, 'model_id' => $m['pc200']->id, 'notes' => null],
        ]);
    }
}
