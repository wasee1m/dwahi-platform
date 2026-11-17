<?php

namespace App\Modules\Parts\Support;

use App\Modules\Platform\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * مستوى التوفر للعميل (BR-04): يُحسب من المخزون المتاح في فروع نشطة، ولا يُعرض الرقم نفسه.
 */
final class Availability
{
    /**
     * @param  list<string>  $partIds
     * @return array<string, string> part_id => available|limited|on_request
     */
    public static function forParts(array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }

        $free = DB::table('stock_levels')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->join('branches', 'branches.id', '=', 'stock_locations.branch_id')
            ->where('branches.is_active', true)
            ->whereIn('stock_levels.part_id', $partIds)
            ->groupBy('stock_levels.part_id')
            ->selectRaw('stock_levels.part_id AS part_id, SUM(qty_on_hand - qty_reserved) AS free')
            ->pluck('free', 'part_id');

        $t = Setting::read('parts.availability_thresholds', ['limited' => 1, 'available' => 5]);

        $result = [];
        foreach ($partIds as $id) {
            $qty = (float) ($free[$id] ?? 0);
            $result[$id] = match (true) {
                $qty >= $t['available'] => 'available',
                $qty >= $t['limited'] => 'limited',
                default => 'on_request',
            };
        }

        return $result;
    }
}
