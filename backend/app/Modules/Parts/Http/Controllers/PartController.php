<?php

namespace App\Modules\Parts\Http\Controllers;

use App\Modules\Parts\Http\Resources\PartCardResource;
use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Queries\PartSearch;
use App\Modules\Parts\Support\Availability;
use App\Modules\Parts\Support\PartNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartController
{
    /** GET /parts/search?q= (FR-PRT-01) */
    public function search(Request $request, PartSearch $search): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'max:100']])['q'];
        $parts = $search->run($q);
        $availability = Availability::forParts($parts->pluck('id')->all());

        return response()->json([
            'data' => $parts->map(fn (Part $p) => (new PartCardResource($p, $availability))->resolve($request))->values(),
        ]);
    }

    /** GET /parts/{brand}/{number} (FR-PRT-03، W-05) */
    public function show(Request $request, string $brand, string $number): JsonResponse
    {
        $part = Part::query()
            ->where('is_active', true)
            ->where('part_number_normalized', PartNumber::normalize($number))
            ->whereHas('brand', fn ($b) => $b->where('slug', $brand))
            ->with(['brand', 'media', 'crossReferences', 'compatibleModels.brand'])
            ->firstOrFail();

        $availability = Availability::forParts([$part->id]);

        return response()->json(['data' => [
            ...(new PartCardResource($part, $availability))->resolve($request),
            'description' => $part->localized('description'),
            'unit' => $part->unit,
            'vat_rate' => $part->vat_rate,
            'is_returnable' => $part->is_returnable,
            'alternatives' => $part->crossReferences->map(fn ($x) => ['number' => $x->alt_number, 'brand' => $x->alt_brand])->values(),
            'compatible_models' => $part->compatibleModels->map(fn ($m) => [
                'brand' => $m->brand->name, 'model_name' => $m->model_name, 'slug' => $m->slug,
            ])->values(),
            'media' => $part->media->map(fn ($m) => ['url' => $m->url()])->filter(fn ($m) => $m['url'])->values(),
        ]]);
    }
}
