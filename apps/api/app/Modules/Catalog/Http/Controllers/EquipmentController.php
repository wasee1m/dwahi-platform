<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\EquipmentCardResource;
use App\Modules\Catalog\Http\Resources\EquipmentDetailResource;
use App\Modules\Catalog\Models\EquipmentListing;
use App\Modules\Catalog\Queries\EquipmentSearch;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipmentController
{
    private const WITH = ['model.brand', 'model.category', 'branch', 'media'];

    /** GET /equipment (FR-CAT-01، FR-CAT-02) */
    public function index(Request $request): JsonResponse
    {
        // التحقق داخل الدالة نفسها ليقرأه Scramble ويوثقه في العقد
        $f = $request->validate([
            'kind' => ['nullable', Rule::in(['machine', 'component', 'scrap'])],
            'condition' => ['nullable', Rule::in(['new', 'used', 'rebuilt', 'for_parts'])],
            'category' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'featured' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:48'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $limit = (int) ($f['limit'] ?? 24);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);

        $search = new EquipmentSearch($f);
        $base = $search->query();
        $total = (clone $base)->count();
        $rows = $search->sorted($base, $f['sort'] ?? 'newest')
            ->with(self::WITH)->offset($offset)->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;

        return response()->json([
            'data' => EquipmentCardResource::collection($rows->take($limit)),
            'next_cursor' => $hasMore ? OffsetCursor::encode($offset + $limit) : null,
            'total' => $total,
        ]);
    }

    /** GET /equipment/facets */
    public function facets(Request $request): JsonResponse
    {
        $f = $request->validate([
            'kind' => ['nullable', Rule::in(['machine', 'component', 'scrap'])],
            'condition' => ['nullable', Rule::in(['new', 'used', 'rebuilt', 'for_parts'])],
            'category' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json((new EquipmentSearch($f))->facets());
    }

    /** GET /equipment/{slug} (FR-CAT-03) */
    public function show(Request $request, string $slug): EquipmentDetailResource
    {
        $listing = EquipmentListing::query()
            ->whereIn('status', EquipmentListing::VISIBLE)
            ->where('slug', $slug)
            ->with(self::WITH)
            ->firstOrFail();

        $similar = EquipmentListing::query()->listed()
            ->whereKeyNot($listing->id)
            ->where('listing_kind', $listing->listing_kind)
            ->when($listing->model?->category_id, fn ($q, $cat) => $q->whereHas('model', fn ($m) => $m->where('category_id', $cat)))
            ->with(self::WITH)->orderByDesc('published_at')->limit(4)->get();

        return new EquipmentDetailResource($listing, $similar);
    }
}
