<?php

namespace App\Modules\Parts\Http\Resources;

use App\Modules\Parts\Models\Part;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** سطر نتيجة بحث القطع (W-04) @mixin Part */
class PartCardResource extends JsonResource
{
    /** @param array<string, string> $availability */
    public function __construct($resource, protected readonly array $availability = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand' => ['name' => $this->brand->name, 'slug' => $this->brand->slug],
            'part_number' => $this->part_number,
            'part_number_normalized' => $this->part_number_normalized,
            'name' => $this->localized('name'),
            'price' => Money::of($this->price),
            'availability' => $this->availability[$this->id] ?? 'on_request',
            'warranty_months' => $this->warranty_months,
            'matched_alternative' => $this->resource->getAttributes()['matched_alternative'] ?? null,
            'cover_image_url' => $this->media->first()?->url(),
        ];
    }
}
