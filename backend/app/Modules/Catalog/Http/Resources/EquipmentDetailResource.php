<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\EquipmentListing;
use Illuminate\Http\Request;

/** صفحة المعدة (W-03، FR-CAT-03) @mixin EquipmentListing */
class EquipmentDetailResource extends EquipmentCardResource
{
    public function __construct($resource, private readonly iterable $similar = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $model = $this->model;

        $specs = [];
        if ($model) {
            $filterable = [
                'operating_weight_kg' => ['ar' => 'الوزن التشغيلي', 'en' => 'Operating weight', 'unit' => 'kg'],
                'engine_power_kw' => ['ar' => 'قدرة المحرك', 'en' => 'Engine power', 'unit' => 'kW'],
                'bucket_capacity_m3' => ['ar' => 'سعة البوكت', 'en' => 'Bucket capacity', 'unit' => 'm³'],
                'lifting_capacity_kg' => ['ar' => 'سعة الرفع', 'en' => 'Lifting capacity', 'unit' => 'kg'],
            ];
            foreach ($filterable as $column => $label) {
                if ($model->{$column} !== null) {
                    $specs[] = ['label' => $label[$locale], 'value' => (string) $model->{$column}, 'unit' => $label['unit']];
                }
            }
            foreach ($model->specs ?? [] as $spec) {
                $specs[] = [
                    'label' => $spec["label_{$locale}"] ?? $spec['label_ar'] ?? $spec['key'],
                    'value' => (string) $spec['value'],
                    'unit' => $spec['unit'] ?? null,
                ];
            }
        }

        return [
            ...parent::toArray($request),
            'description' => $this->localized('description'),
            'serial_number' => $this->serial_number,
            'source_machine' => $this->source_machine,
            'missing_parts' => $this->missing_parts,
            'specs' => $specs,
            'media' => $this->media->map(fn ($m) => ['url' => $m->url(), 'width' => $m->width, 'height' => $m->height])
                ->filter(fn ($m) => $m['url'] !== null)->values(),
            'branch' => $this->branch ? [
                'name' => $this->branch->localized('name'),
                'phone' => $this->branch->phone,
                'whatsapp_sales' => $this->branch->whatsapp_sales,
            ] : null,
            'similar' => EquipmentCardResource::collection($this->similar)->resolve($request),
        ];
    }
}
