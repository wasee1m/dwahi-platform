<?php

namespace App\Modules\Catalog\Models;

use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentModel extends Model
{
    protected $casts = ['specs' => 'array', 'engine_power_kw' => 'decimal:1', 'bucket_capacity_m3' => 'decimal:2'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'category_id');
    }
}
