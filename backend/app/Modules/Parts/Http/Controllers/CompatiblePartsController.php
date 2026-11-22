<?php

namespace App\Modules\Parts\Http\Controllers;

use App\Modules\Catalog\Models\EquipmentModel;
use App\Modules\Parts\Http\Resources\PartCardResource;
use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Support\Availability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /parts/compatible?model_id=: القطع المرتبطة بالموديل مجمعة حسب الفئة (FR-PRT-02، US-04) */
class CompatiblePartsController
{
    private const LIMIT = 300;

    public function index(Request $request): JsonResponse
    {
        $id = $request->validate(['model_id' => ['required', 'uuid']])['model_id'];
        $model = EquipmentModel::query()->with('brand')->findOrFail($id);
        $parts = Part::query()->where('is_active', true)
            ->whereHas('compatibleModels', fn ($m) => $m->where('equipment_models.id', $model->id))
            ->with(['brand', 'media'])->orderBy('category')->orderBy('part_number')->limit(self::LIMIT)->get()
            ->each(fn (Part $p) => $p->setAttribute('matched_alternative', null));
        $availability = Availability::forParts($parts->pluck('id')->all());

        $groups = $parts->groupBy(fn (Part $p) => (string) $p->category)->map(fn ($group) => [
            'category' => self::categoryName($group->first()->category),
            'parts' => $group->map(fn (Part $p) => (new PartCardResource($p, $availability))->resolve($request))->values()->all(),
        ])->values()->all();

        return response()->json(['data' => [
            'model' => ['id' => $model->id, 'model_name' => $model->model_name, 'brand' => $model->brand->name],
            /** @var list<array{category: string|null, parts: list<PartCardResource>}> */
            'groups' => $groups,
        ]]);
    }

    /** الفئة بلغة الطلب إن كانت معروفة */
    private static function categoryName(?string $category): ?string
    {
        if (! $category) {
            return null;
        }
        $names = __('parts.categories');

        return $names[strtolower($category)] ?? $category;
    }
}
