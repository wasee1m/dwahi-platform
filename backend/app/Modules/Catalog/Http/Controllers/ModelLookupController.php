<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\EquipmentModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** قائمة الموديلات العامة: إضافة معدة للأسطول (C-05، M-13) و"حسب المعدة" في بحث القطع (W-04، M-09) */
class ModelLookupController
{
    public function index(Request $request): JsonResponse
    {
        $brand = $request->validate(['brand' => ['nullable', 'string', 'max:100']])['brand'] ?? null;
        $models = EquipmentModel::query()->with('brand')
            ->whereHas('brand', fn ($b) => $b->where('is_active', true)->when($brand, fn ($q, $slug) => $q->where('slug', $slug)))
            ->orderBy('model_name')->limit(2000)->get();

        return response()->json(['data' => $models->map(fn (EquipmentModel $m) => [
            'id' => $m->id,
            'model_name' => $m->model_name,
            'brand' => $m->brand->name,
            'brand_slug' => $m->brand->slug,
        ])->sortBy('brand')->values()]);
    }
}
