<?php

namespace App\Modules\Parts\Services;

use App\Models\User;
use App\Support\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * حركات المخزون (03 §5، FR-PRT-04): كل تغيير في الكمية حركة في الدفتر الإلحاقي stock_movements،
 * و stock_levels إسقاط منها يُحدَّث في نفس المعاملة (03 §1). قيود CHECK تمنع الكمية السالبة.
 */
final class StockService
{
    /**
     * @param  array{sales_order_id?: string, work_order_id?: string, return_request_id?: string}  $refs
     */
    public function move(string $partId, string $locationId, string $delta, string $reason, User $by, ?string $note = null, array $refs = []): void
    {
        try {
            DB::transaction(function () use ($partId, $locationId, $delta, $reason, $by, $note, $refs) {
                $level = DB::table('stock_levels')->where('part_id', $partId)->where('location_id', $locationId)->lockForUpdate()->first();
                if (! $level) {
                    DB::table('stock_levels')->insert(['part_id' => $partId, 'location_id' => $locationId, 'qty_on_hand' => 0, 'qty_reserved' => 0]);
                }
                DB::table('stock_levels')->where('part_id', $partId)->where('location_id', $locationId)
                    ->update(['qty_on_hand' => DB::raw('qty_on_hand + '.(float) $delta)]);

                DB::table('stock_movements')->insert($refs + [
                    'id' => (string) Str::uuid7(),
                    'part_id' => $partId,
                    'location_id' => $locationId,
                    'qty_delta' => $delta,
                    'reason' => $reason,
                    'note' => $note,
                    'created_by' => $by->id,
                    'created_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            // stock_levels_on_hand_chk أو stock_levels_reserved_chk: الكمية ستصبح أقل من الصفر أو من المحجوز
            if (str_contains($e->getMessage(), 'stock_levels_')) {
                throw new ApiProblem('STOCK_INSUFFICIENT', 422, null, ['part_id' => $partId]);
            }
            throw $e;
        }
    }
}
