<?php

namespace App\Modules\Accounts\Models;

use App\Modules\Accounts\Support\BelongsToAccount;
use App\Modules\Identity\Models\Organization;
use App\Support\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** عنوان توصيل أو موقع معدات (FR-ACC-04). الحذف أرشفة، والمستندات تحفظ نسختها (BR-17). */
class Address extends Model
{
    use BelongsToAccount;

    public const KINDS = ['delivery', 'site', 'both'];

    protected $hidden = ['location'];

    protected $casts = ['archived_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];

    public function personalColumn(): string
    {
        return 'user_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** ترتيب المحاور في 4326 عند MySQL: خط العرض أولاً */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        return $query->select('addresses.*')
            ->addSelect(DB::raw('ST_Latitude(location) AS latitude, ST_Longitude(location) AS longitude'));
    }

    /** النص الذي يُنسخ في الطلب (BR-17) */
    public function snapshot(string $locale): string
    {
        return collect([
            $this->label,
            __("accounts.governorates.{$this->governorate}", [], $locale),
            $this->wilayat,
            $this->description,
            trim("{$this->contact_name} {$this->contact_phone}"),
        ])->filter()->implode($locale === 'en' ? ', ' : '، ');
    }
}
