<?php

namespace App\Modules\Parts\Models;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\EquipmentModel;
use App\Modules\Platform\Models\Attachment;
use App\Support\Localized;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Part extends Model
{
    use Localized;

    protected $casts = [
        'price' => 'decimal:3',
        'vat_rate' => 'decimal:2',
        'is_returnable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function crossReferences(): HasMany
    {
        return $this->hasMany(PartCrossReference::class);
    }

    public function compatibleModels(): BelongsToMany
    {
        return $this->belongsToMany(EquipmentModel::class, 'part_compatibility', 'part_id', 'model_id')->withPivot('notes');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'part_media', 'part_id', 'attachment_id')
            ->withPivot('sort_order')->orderByPivot('sort_order');
    }
}
