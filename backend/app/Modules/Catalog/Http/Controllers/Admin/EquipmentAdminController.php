<?php

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Models\EquipmentListing;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Support\Audit;
use App\Support\ApiProblem;
use App\Support\Money;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A-02 المعدات المعروضة (FR-CAT-06، FR-CAT-07، US-36). الأنواع الثلاثة بقواعدها (BR-03)،
 * وحالات النشر، وتسجيل تغيير السعر والحالة في التدقيق.
 */
class EquipmentAdminController
{
    /** الانتقالات المسموحة (FR-CAT-06، BR-19). الحجز الآلي عند قبول عرض يأتي مع العروض (S4). */
    public const TRANSITIONS = [
        'draft' => ['published', 'archived'],
        'published' => ['reserved', 'sold', 'archived', 'draft'],
        'reserved' => ['published', 'sold'],
        'sold' => ['archived'],
        'archived' => ['draft'],
    ];

    /** يتطلب سبباً: الحجز اليدوي، وإلغاء الحجز (BR-19) */
    private const NEEDS_REASON = ['reserved', 'published_from_reserved'];

    private const WITH = ['model.brand', 'model.category', 'branch', 'media'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(self::TRANSITIONS))],
            'kind' => ['nullable', Rule::in(['machine', 'component', 'scrap'])],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $q = EquipmentListing::query()
            ->when($f['status'] ?? null, fn ($w, $v) => $w->where('status', $v))
            ->when($f['kind'] ?? null, fn ($w, $v) => $w->where('listing_kind', $v))
            ->when($f['q'] ?? null, fn ($w, $v) => $w->where(fn ($x) => $x->where('title_ar', 'like', "%{$v}%")
                ->orWhere('title_en', 'like', "%{$v}%")->orWhere('component_model', 'like', "%{$v}%")));
        $total = (clone $q)->count();
        $rows = $q->with(self::WITH)->orderByDesc('updated_at')->offset($offset)->limit(51)->get();

        return response()->json([
            'data' => $rows->take(50)->map(fn (EquipmentListing $l) => $this->row($l))->values(),
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
        $data = $this->validated($request);
        $listing = EquipmentListing::query()->create($data + [
            'slug' => $this->uniqueSlug($data['title_en']),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);
        Audit::record('equipment.created', 'equipment_listing', $listing->id, null, ['title' => $listing->title_ar]);

        return response()->json(['data' => $this->detail($this->find($listing->id))], 201);
    }

    /** PUT: النموذج يرسل كل الحقول */
    public function update(Request $request, string $id): JsonResponse
    {
        $listing = $this->find($id);
        $data = $this->validated($request);
        $before = ['price' => $listing->price, 'price_visibility' => $listing->price_visibility];
        $listing->update($data);

        $after = ['price' => $listing->price, 'price_visibility' => $listing->price_visibility];
        if ($before != $after) {
            Audit::record('equipment.price_changed', 'equipment_listing', $listing->id, $before, $after); // US-36
        }

        return response()->json(['data' => $this->detail($this->find($id))]);
    }

    /** PUT /admin/equipment/{id}/media: الصور بالترتيب (سحب وترتيب في A-02) */
    public function media(Request $request, string $id): JsonResponse
    {
        $listing = $this->find($id);
        $ids = $request->validate([
            'attachment_ids' => ['present', 'array', 'max:30'],
            'attachment_ids.*' => ['uuid', 'distinct', Rule::exists('attachments', 'id')->where('visibility', 'public')],
        ])['attachment_ids'];
        $listing->media()->sync(collect($ids)->values()->mapWithKeys(fn ($a, $i) => [$a => ['sort_order' => $i]])->all());

        return response()->json(['data' => $this->detail($this->find($id))]);
    }

    /** POST /admin/equipment/{id}/status: نشر، حجز، بيع، أرشفة، إعادة لمسودة */
    public function status(Request $request, string $id): JsonResponse
    {
        $listing = $this->find($id);
        $data = $request->validate([
            'to' => ['required', Rule::in(array_keys(self::TRANSITIONS))],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $from = $listing->status;
        $to = $data['to'];

        if (! in_array($to, self::TRANSITIONS[$from], true)) {
            throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $from, 'to' => $to]);
        }
        $key = $to === 'published' && $from === 'reserved' ? 'published_from_reserved' : $to;
        if (in_array($key, self::NEEDS_REASON, true) && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => __('validation.required', ['attribute' => 'reason'])]);
        }
        if ($to === 'published') {
            $this->assertPublishable($listing);
        }

        DB::transaction(function () use ($listing, $from, $to, $data) {
            $listing->update([
                'status' => $to,
                'published_at' => $to === 'published' ? ($listing->published_at ?? now()) : $listing->published_at,
                'reserved_for_rfq_id' => in_array($to, ['reserved', 'sold'], true) ? $listing->reserved_for_rfq_id : null,
            ]);
            Audit::record('equipment.status_changed', 'equipment_listing', $listing->id, ['status' => $from], ['status' => $to, 'reason' => $data['reason'] ?? null]);
        });

        return response()->json(['data' => $this->detail($this->find($id))]);
    }

    /** US-36: لا نشر دون صورة واحدة على الأقل والعنوانين باللغتين */
    private function assertPublishable(EquipmentListing $l): void
    {
        $missing = [];
        if ($l->media()->count() === 0) {
            $missing['media'] = [__('validation.required', ['attribute' => 'media'])];
        }
        foreach (['title_ar', 'title_en'] as $f) {
            if (blank($l->{$f})) {
                $missing[$f] = [__('validation.required', ['attribute' => $f])];
            }
        }
        if ($missing) {
            throw ValidationException::withMessages($missing);
        }
    }

    /** قواعد الأنواع (BR-03) برسائل واضحة قبل أن ترفضها قيود قاعدة البيانات */
    private function validated(Request $request): array
    {
        $kind = $request->input('listing_kind');
        $condition = $kind === 'scrap' ? 'for_parts' : $request->input('condition');
        $request->merge(['condition' => $condition]);
        $usedMachine = $kind === 'machine' && $condition !== 'new';

        $data = $request->validate([
            'listing_kind' => ['required', Rule::in(['machine', 'component', 'scrap'])],
            'model_id' => [$kind === 'component' ? 'nullable' : 'required', 'uuid', 'exists:equipment_models,id'],
            'component_type' => [$kind === 'component' ? 'required' : 'nullable', Rule::in(['diesel_engine', 'transmission', 'hydraulic_pump', 'hydraulic_cylinder', 'arm', 'bucket', 'other'])],
            'component_model' => [$kind === 'component' ? 'required' : 'nullable', 'string', 'max:255'],
            'source_machine' => ['nullable', 'string', 'max:255'],
            'missing_parts' => [$kind === 'scrap' ? 'required' : 'nullable', 'string', 'max:2000'],
            'condition' => ['required', Rule::in($kind === 'scrap' ? ['for_parts'] : ['new', 'used', 'rebuilt'])],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'year' => [$usedMachine || $kind === 'scrap' ? 'required' : 'nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'hour_meter' => [$usedMachine ? 'required' : 'nullable', 'integer', 'min:0', 'max:500000'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:10000'],
            'description_en' => ['nullable', 'string', 'max:10000'],
            'price_visibility' => ['required', Rule::in(['shown', 'on_request'])],
            'price' => [$request->input('price_visibility') === 'shown' ? 'required' : 'nullable', 'decimal:0,3', 'min:0', 'max:999999999'],
            'branch_code' => ['required', 'exists:branches,code'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        $data['branch_id'] = Branch::query()->where('code', $data['branch_code'])->value('id');
        unset($data['branch_code']);
        if ($kind === 'component') {
            $data['model_id'] = null;
        } else {
            $data['component_type'] = $data['component_model'] = null;
        }
        $data['price'] = isset($data['price']) ? number_format((float) $data['price'], 3, '.', '') : null;
        $data['is_featured'] = (bool) ($data['is_featured'] ?? false);

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'listing';
        $slug = $base;
        for ($i = 2; EquipmentListing::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function find(string $id): EquipmentListing
    {
        abort_unless(Str::isUuid($id), 404);

        return EquipmentListing::query()->with(self::WITH)->findOrFail($id);
    }

    private function row(EquipmentListing $l): array
    {
        return [
            'id' => $l->id,
            'slug' => $l->slug,
            'kind' => $l->listing_kind,
            'title_ar' => $l->title_ar,
            'title_en' => $l->title_en,
            'model' => $l->model ? $l->model->brand->name.' '.$l->model->model_name : $l->component_model,
            'condition' => $l->condition,
            'price' => Money::of($l->price),
            'price_visibility' => $l->price_visibility,
            'branch' => $l->branch?->code,
            'status' => $l->status,
            'is_featured' => $l->is_featured,
            'cover_url' => $l->media->first()?->url(),
            'updated_at' => $l->updated_at?->toIso8601ZuluString(),
        ];
    }

    private function detail(EquipmentListing $l): array
    {
        return [
            ...$l->only(['id', 'slug', 'listing_kind', 'model_id', 'component_type', 'component_model', 'source_machine', 'missing_parts',
                'condition', 'warranty_months', 'year', 'hour_meter', 'serial_number', 'title_ar', 'title_en', 'description_ar',
                'description_en', 'price_visibility', 'status', 'is_featured']),
            'price' => $l->price,
            'branch_code' => $l->branch?->code,
            'published_at' => $l->published_at?->toIso8601ZuluString(),
            'media' => $l->media->map(fn (Attachment $a) => ['id' => $a->id, 'url' => $a->url()])->values(),
            'allowed_transitions' => self::TRANSITIONS[$l->status],
        ];
    }
}
