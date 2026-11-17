<?php

namespace App\Modules\Catalog\Queries;

use App\Modules\Catalog\Models\EquipmentListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * تصفح المعدات وفلترتها وعدّادات الفلاتر (FR-CAT-01، FR-CAT-02، US-01).
 * الفلاتر: kind، category، brand، condition، featured. كل عدّاد يطبق الفلاتر الأخرى عدا نفسه.
 */
final class EquipmentSearch
{
    public const SORTS = ['newest', 'price_asc', 'price_desc'];

    /** @param array<string, string|null> $filters */
    public function __construct(private readonly array $filters) {}

    public function query(?string $except = null): Builder
    {
        $q = EquipmentListing::query()->listed();
        $f = $this->filters;

        if ($except !== 'kind' && ! empty($f['kind'])) {
            $q->where('listing_kind', $f['kind']);
        }
        if ($except !== 'condition' && ! empty($f['condition'])) {
            $q->where('condition', $f['condition']);
        }
        if ($except !== 'category' && ! empty($f['category'])) {
            $q->whereHas('model.category', fn ($c) => $c->where('slug', $f['category']));
        }
        if ($except !== 'brand' && ! empty($f['brand'])) {
            $q->whereHas('model.brand', fn ($b) => $b->where('slug', $f['brand']));
        }
        if (! empty($f['featured'])) {
            $q->where('is_featured', true);
        }

        return $q;
    }

    public function sorted(Builder $q, string $sort): Builder
    {
        return match ($sort) {
            // "اطلب السعر" في آخر الترتيب بالسعر
            'price_asc' => $q->orderByRaw("price_visibility <> 'shown'")->orderBy('price')->orderBy('id'),
            'price_desc' => $q->orderByRaw("price_visibility <> 'shown'")->orderByDesc('price')->orderBy('id'),
            default => $q->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function facets(): array
    {
        $count = fn (Builder $q, string $col) => $q->toBase()->select($col)->selectRaw('COUNT(*) AS c')
            ->groupBy($col)->pluck('c', $col);

        $kinds = $count($this->query('kind'), 'listing_kind');
        $conditions = $count($this->query('condition'), 'condition');

        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $categories = $this->query('category')->toBase()
            ->join('equipment_models as m', 'm.id', '=', 'equipment_listings.model_id')
            ->join('equipment_categories as c', 'c.id', '=', 'm.category_id')
            ->groupBy('c.slug', "c.name_{$locale}", 'c.sort_order')
            ->orderBy('c.sort_order')
            ->get(['c.slug', DB::raw("c.name_{$locale} AS name"), DB::raw('COUNT(*) AS count')]);

        $brands = $this->query('brand')->toBase()
            ->join('equipment_models as m', 'm.id', '=', 'equipment_listings.model_id')
            ->join('brands as b', 'b.id', '=', 'm.brand_id')
            ->groupBy('b.slug', 'b.name')
            ->orderBy('b.name')
            ->get(['b.slug', 'b.name', DB::raw('COUNT(*) AS count')]);

        return [
            'kinds' => collect(['machine', 'component', 'scrap'])
                ->map(fn ($v) => ['value' => $v, 'count' => (int) ($kinds[$v] ?? 0)])->values()->all(),
            'conditions' => collect(['new', 'used', 'rebuilt', 'for_parts'])
                ->map(fn ($v) => ['value' => $v, 'count' => (int) ($conditions[$v] ?? 0)])->values()->all(),
            'categories' => $categories->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name, 'count' => (int) $r->count])->all(),
            'brands' => $brands->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name, 'count' => (int) $r->count])->all(),
        ];
    }
}
