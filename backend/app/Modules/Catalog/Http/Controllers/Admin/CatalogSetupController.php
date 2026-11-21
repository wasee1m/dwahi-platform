<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\EquipmentCategory;
use App\Modules\Catalog\Models\EquipmentModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A-03 الماركات والفئات والموديلات (FR-CAT-06، US-36) */
class CatalogSetupController
{
    // ---------- الماركات ----------
    public function brands(): JsonResponse
    {
        $brands = Brand::query()->with('logo')->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $brands->map(fn (Brand $b) => [
            ...$b->only(['id', 'name', 'slug', 'logo_id', 'is_represented', 'sort_order', 'is_active']),
            'logo_url' => $b->logo?->url(),
        ])->values()]);
    }

    public function saveBrand(Request $request, ?string $id = null): JsonResponse
    {
        $brand = $id ? Brand::query()->findOrFail($id) : new Brand;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'alpha_dash', 'max:100', Rule::unique('brands', 'slug')->ignore($brand->id)],
            'logo_id' => ['nullable', 'uuid', 'exists:attachments,id'],
            'is_represented' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        $brand->fill($data)->save();

        return response()->json(['data' => $brand->only(['id', 'name', 'slug'])], $id ? 200 : 201);
    }

    // ---------- الفئات (شجرة) ----------
    public function categories(): JsonResponse
    {
        return response()->json(['data' => EquipmentCategory::query()->orderBy('sort_order')->orderBy('name_ar')
            ->get(['id', 'parent_id', 'name_ar', 'name_en', 'slug', 'sort_order'])]);
    }

    public function saveCategory(Request $request, ?string $id = null): JsonResponse
    {
        $cat = $id ? EquipmentCategory::query()->findOrFail($id) : new EquipmentCategory;
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:100'],
            'name_en' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'alpha_dash', 'max:100', Rule::unique('equipment_categories', 'slug')->ignore($cat->id)],
            'parent_id' => ['nullable', 'uuid', 'exists:equipment_categories,id', Rule::notIn(array_filter([$cat->id]))],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name_en']);
        $cat->fill($data)->save();

        return response()->json(['data' => $cat->only(['id', 'slug'])], $id ? 200 : 201);
    }

    // ---------- الموديلات ----------
    public function models(Request $request): JsonResponse
    {
        $models = EquipmentModel::query()->with(['brand', 'category'])
            ->when($request->query('brand_id'), fn ($q, $v) => $q->where('brand_id', $v))
            ->orderBy('model_name')->get();

        return response()->json(['data' => $models->map(fn (EquipmentModel $m) => [
            ...$m->only(['id', 'brand_id', 'category_id', 'model_name', 'slug', 'operating_weight_kg', 'lifting_capacity_kg', 'spec_sheet_id', 'specs']),
            'engine_power_kw' => $m->engine_power_kw,
            'bucket_capacity_m3' => $m->bucket_capacity_m3,
            'brand' => $m->brand->name,
            'category' => $m->category->name_ar,
        ])->values()]);
    }

    public function saveModel(Request $request, ?string $id = null): JsonResponse
    {
        $model = $id ? EquipmentModel::query()->findOrFail($id) : new EquipmentModel;
        $data = $request->validate([
            'brand_id' => ['required', 'uuid', 'exists:brands,id'],
            'category_id' => ['required', 'uuid', 'exists:equipment_categories,id'],
            'model_name' => ['required', 'string', 'max:100',
                Rule::unique('equipment_models')->where('brand_id', $request->input('brand_id'))->ignore($model->id)],
            'operating_weight_kg' => ['nullable', 'integer', 'min:0'],
            'engine_power_kw' => ['nullable', 'decimal:0,1', 'min:0'],
            'bucket_capacity_m3' => ['nullable', 'decimal:0,2', 'min:0'],
            'lifting_capacity_kg' => ['nullable', 'integer', 'min:0'],
            'spec_sheet_id' => ['nullable', 'uuid', 'exists:attachments,id'],
            // مواصفات للعرض فقط (03 §4)
            'specs' => ['sometimes', 'array', 'max:40'],
            'specs.*.label_ar' => ['required', 'string', 'max:100'],
            'specs.*.label_en' => ['required', 'string', 'max:100'],
            'specs.*.value' => ['required', 'string', 'max:100'],
            'specs.*.unit' => ['nullable', 'string', 'max:20'],
        ]);
        $brand = Brand::query()->findOrFail($data['brand_id']);
        $data['slug'] = $model->slug ?? Str::slug($brand->name.' '.$data['model_name']);
        $data['specs'] = array_values(array_map(fn ($s) => ['key' => Str::slug($s['label_en'], '_')] + $s, $data['specs'] ?? []));
        $model->fill($data)->save();

        return response()->json(['data' => $model->only(['id', 'slug'])], $id ? 200 : 201);
    }
}
