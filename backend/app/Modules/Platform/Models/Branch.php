<?php

namespace App\Modules\Platform\Models;

use App\Support\Localized;
use App\Support\Model;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * الفروع (FR-ADM-02). الموقع POINT SRID 4326، ويُقرأ بـ ST_Latitude و ST_Longitude
 * لأن ترتيب المحاور في 4326 عند MySQL هو خط العرض أولاً.
 */
class Branch extends Model
{
    use Localized;

    protected $hidden = ['location'];

    protected $casts = [
        'working_hours' => 'array',
        'covered_governorates' => 'array',
        'is_active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function scopeWithCoordinates(Builder $query): Builder
    {
        return $query->select('branches.*')
            ->addSelect(DB::raw('ST_Latitude(location) AS latitude, ST_Longitude(location) AS longitude'));
    }

    public static function point(float $latitude, float $longitude): Expression
    {
        return DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)', 4326, 'axis-order=lat-long')", $latitude, $longitude));
    }
}
