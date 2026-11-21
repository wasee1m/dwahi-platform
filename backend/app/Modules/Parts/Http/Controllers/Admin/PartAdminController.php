<?php

namespace App\Modules\Parts\Http\Controllers\Admin;

use App\Modules\Parts\Models\Part;
use App\Modules\Parts\Models\StockLocation;
use App\Modules\Parts\Services\StockService;
use App\Modules\Parts\Support\PartNumber;
use App\Modules\Platform\Support\Audit;
use App\Support\Money;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A-04 القطع والمخزون (FR-PRT-04، US-37) */
class PartAdminController
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'brand_id' => ['nullable', 'uuid'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $q = Part::query()
            ->when($f['brand_id'] ?? null, fn ($w, $v) => $w->where('brand_id', $v))
            ->when($f['q'] ?? null, fn ($w, $v) => $w->where(fn ($x) => $x
                ->where('part_number_normalized', 'like', PartNumber::normalize($v).'%')
                ->orWhere('name_ar', 'like', "%{$v}%")->orWhere('name_en', 'like', "%{$v}%")));
        $total = (clone $q)->count();
        $rows = $q->with('brand')->orderBy('part_number_normalized')->offset($offset)->limit(51)->get();

        $stock = DB::table('stock_levels')->whereIn('part_id', $rows->pluck('id'))
            ->groupBy('part_id')->selectRaw('part_id, SUM(qty_on_hand) AS on_hand, SUM(qty_reserved) AS reserved')->get()->keyBy('part_id');

        return response()->json([
            'data' => $rows->take(50)->map(fn (Part $p) => [
                'id' => $p->id,
                'brand' => $p->brand->name,
                'part_number' => $p->part_number,
                'name_ar' => $p->name_ar,
                'name_en' => $p->name_en,
                'price' => Money::of($p->price),
                'is_active' => $p->is_active,
                'on_hand' => (string) ($stock[$p->id]->on_hand ?? '0'),
                'reserved' => (string) ($stock[$p->id]->reserved ?? '0'),
            ])->values(),
            'next_cursor' => $rows->count() > 50 ? OffsetCursor::encode($offset + 50) : null,
            'total' => $total,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $part = DB::transaction(function () use ($data) {
            $part = Part::query()->create($data['part']);
            $this->syncRelations($part, $data);

            return $part;
        });
        Audit::record('part.created', 'part', $part->id, null, ['part_number' => $part->part_number, 'price' => $part->price]);

        return response()->json(['data' => $this->detail($this->find($part->id))], 201);
    }

    /** PUT: النموذج يرسل كل الحقول، والأرقام البديلة والتوافق تُستبدل */
    public function update(Request $request, string $id): JsonResponse
    {
        $part = $this->find($id);
        $data = $this->validated($request, $part);
        $before = $part->only(['price', 'vat_rate', 'is_active']);
        DB::transaction(function () use ($part, $data) {
            $part->update($data['part']);
            $this->syncRelations($part, $data);
        });
        $after = $part->fresh()->only(['price', 'vat_rate', 'is_active']);
        if ($before != $after) {
            Audit::record('part.updated', 'part', $part->id, $before, $after);
        }

        return response()->json(['data' => $this->detail($this->find($id))]);
    }

    public function locations(): JsonResponse
    {
        return response()->json(['data' => StockLocation::query()->orderBy('name')->get(['id', 'branch_id', 'name'])]);
    }

    /** POST /admin/stock/receipts: استلام شحنة مورد، حركة "استلام" لكل صنف (US-37) */
    public function receive(Request $request, StockService $stock): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'uuid', 'exists:stock_locations,id'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.part_id' => ['required', 'uuid', 'distinct', 'exists:parts,id'],
            'lines.*.qty' => ['required', 'decimal:0,2', 'gt:0', 'max:1000000'],
        ]);
        DB::transaction(function () use ($data, $stock, $request) {
            foreach ($data['lines'] as $line) {
                $stock->move($line['part_id'], $data['location_id'], (string) $line['qty'], 'receipt', $request->user(), $data['note'] ?? null);
            }
        });

        return response()->json(['data' => ['lines' => count($data['lines'])]], 201);
    }

    /** POST /admin/stock/adjustments: تسوية يدوية بسبب إلزامي، في الدفتر والتدقيق (US-37) */
    public function adjust(Request $request, StockService $stock): JsonResponse
    {
        $data = $request->validate([
            'part_id' => ['required', 'uuid', 'exists:parts,id'],
            'location_id' => ['required', 'uuid', 'exists:stock_locations,id'],
            'qty_delta' => ['required', 'decimal:0,2', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $stock->move($data['part_id'], $data['location_id'], (string) $data['qty_delta'], 'adjustment', $request->user(), $data['reason']);
        Audit::record('stock.adjusted', 'part', $data['part_id'], null, [
            'location_id' => $data['location_id'], 'qty_delta' => $data['qty_delta'], 'reason' => $data['reason'],
        ]);

        return response()->json(['data' => $this->detail($this->find($data['part_id']))], 201);
    }

    /** @return array{part: array<string, mixed>, alternatives: list<array{number: string, brand?: string|null}>, compatible_model_ids: list<string>} */
    private function validated(Request $request, ?Part $part): array
    {
        $normalized = PartNumber::normalize((string) $request->input('part_number'));
        $data = $request->validate([
            'brand_id' => ['required', 'uuid', 'exists:brands,id'],
            'part_number' => ['required', 'string', 'max:100', 'regex:/[A-Za-z0-9]/',
                function ($attr, $value, $fail) use ($request, $normalized, $part) {
                    $dup = Part::query()->where('brand_id', $request->input('brand_id'))->where('part_number_normalized', $normalized)
                        ->when($part, fn ($q) => $q->whereKeyNot($part->id))->exists();
                    if ($dup) {
                        $fail(__('validation.unique', ['attribute' => $attr]));
                    }
                }],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', Rule::in(['piece', 'set', 'liter', 'meter', 'kg'])],
            'price' => ['required', 'decimal:0,3', 'min:0', 'max:999999999'],
            'vat_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'is_returnable' => ['required', 'boolean'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'weight_kg' => ['nullable', 'decimal:0,2', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'alternatives' => ['present', 'array', 'max:50'],
            'alternatives.*.number' => ['required', 'string', 'max:100'],
            'alternatives.*.brand' => ['nullable', 'string', 'max:100'],
            'compatible_model_ids' => ['present', 'array', 'max:200'],
            'compatible_model_ids.*' => ['uuid', 'distinct', 'exists:equipment_models,id'],
        ]);

        $fields = collect($data)->except(['alternatives', 'compatible_model_ids'])->all();
        $fields['price'] = number_format((float) $fields['price'], 3, '.', '');

        return ['part' => $fields, 'alternatives' => $data['alternatives'], 'compatible_model_ids' => $data['compatible_model_ids']];
    }

    private function syncRelations(Part $part, array $data): void
    {
        DB::table('part_cross_references')->where('part_id', $part->id)->delete();
        $seen = [];
        foreach ($data['alternatives'] as $alt) {
            $n = PartNumber::normalize($alt['number']);
            if ($n === '' || isset($seen[$n])) {
                continue; // تكرار بنفس الرقم المطبّع
            }
            $seen[$n] = true;
            DB::table('part_cross_references')->insert([
                'id' => (string) Str::uuid7(), 'part_id' => $part->id, 'alt_number' => trim($alt['number']), 'alt_brand' => $alt['brand'] ?? null,
            ]);
        }
        $part->compatibleModels()->sync($data['compatible_model_ids']);
    }

    private function find(string $id): Part
    {
        abort_unless(Str::isUuid($id), 404);

        return Part::query()->with(['brand', 'crossReferences', 'compatibleModels.brand'])->findOrFail($id);
    }

    private function detail(Part $p): array
    {
        $levels = DB::table('stock_levels as l')->join('stock_locations as s', 's.id', '=', 'l.location_id')
            ->where('l.part_id', $p->id)->get(['s.id', 's.name', 'l.qty_on_hand', 'l.qty_reserved']);
        $movements = DB::table('stock_movements as m')->join('stock_locations as s', 's.id', '=', 'm.location_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')->where('m.part_id', $p->id)
            ->orderByDesc('m.created_at')->limit(30)->get(['m.qty_delta', 'm.reason', 'm.note', 'm.created_at', 's.name as location', 'u.full_name as by']);

        return [
            ...$p->only(['id', 'brand_id', 'part_number', 'part_number_normalized', 'name_ar', 'name_en', 'description_ar', 'description_en',
                'category', 'unit', 'is_returnable', 'warranty_months', 'is_active']),
            'price' => $p->price,
            'vat_rate' => $p->vat_rate,
            'weight_kg' => $p->weight_kg,
            'brand' => $p->brand->name,
            'alternatives' => $p->crossReferences->map(fn ($x) => ['number' => $x->alt_number, 'brand' => $x->alt_brand])->values(),
            'compatible_models' => $p->compatibleModels->map(fn ($m) => ['id' => $m->id, 'name' => $m->brand->name.' '.$m->model_name])->values(),
            'stock' => $levels->map(fn ($l) => ['location_id' => $l->id, 'location' => $l->name, 'on_hand' => (string) $l->qty_on_hand, 'reserved' => (string) $l->qty_reserved])->values(),
            'movements' => $movements->map(fn ($m) => [
                'qty_delta' => (string) $m->qty_delta, 'reason' => $m->reason, 'note' => $m->note, 'location' => $m->location, 'by' => $m->by,
                'at' => Carbon::parse($m->created_at, 'UTC')->toIso8601ZuluString(),
            ])->values(),
        ];
    }
}
