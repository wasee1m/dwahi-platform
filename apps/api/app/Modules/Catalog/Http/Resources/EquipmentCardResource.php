<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\EquipmentListing;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** بطاقة المعدة (06: W-01، W-02، W-03) @mixin EquipmentListing */
class EquipmentCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $model = $this->model;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'kind' => $this->listing_kind,
            'title' => $this->localized('title'),
            'condition' => $this->condition,
            'status' => $this->status,
            'year' => $this->year,
            'hour_meter' => $this->hour_meter,
            'price' => Money::of($this->shownPrice()),
            'brand' => $model?->brand ? ['name' => $model->brand->name, 'slug' => $model->brand->slug] : null,
            'category' => $model?->category ? ['name' => $model->category->localized('name'), 'slug' => $model->category->slug] : null,
            'model_name' => $model?->model_name,
            'component_model' => $this->component_model,
            'warranty_months' => $this->warranty_months,
            'branch' => $this->branch ? ['name' => $this->branch->localized('name')] : null,
            'cover_image_url' => $this->media->first()?->url(),
            'is_featured' => $this->is_featured,
        ];
    }
}
