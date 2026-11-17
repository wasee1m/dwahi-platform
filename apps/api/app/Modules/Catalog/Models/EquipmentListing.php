<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Models\Branch;
use App\Support\Localized;
use App\Support\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * معدة معروضة (03 §4، SM: draft → published → reserved → sold / archived).
 * الأنواع: آلة، أو مكوّن رئيسي فريد، أو سكراب للقطع (BR-03).
 */
class EquipmentListing extends Model
{
    use Localized;

    /** ما يراه الزائر في القوائم. المباع يبقى صفحته متاحة فقط (للروابط القديمة ومحركات البحث). */
    public const LISTED = ['published', 'reserved'];

    public const VISIBLE = ['published', 'reserved', 'sold'];

    protected $casts = [
        'price' => 'decimal:3',
        'is_featured' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(EquipmentModel::class, 'model_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'listing_media', 'listing_id', 'attachment_id')
            ->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function scopeListed(Builder $query): Builder
    {
        return $query->whereIn('equipment_listings.status', self::LISTED);
    }

    public function shownPrice(): ?string
    {
        return $this->price_visibility === 'shown' ? $this->price : null;
    }
}
