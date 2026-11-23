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

    /**
     * حجز كمية لطلب (BR-05، SM-3a انتقال 3.0): يزيد المحجوز بشرط أن يبقى المتاح كافياً، بتحديث شرطي واحد
     * فلا يحجز طلبان نفس القطعة الأخيرة.
     */
    public function reserve(string $partId, string $locationId, string $qty): void
    {
        $n = DB::table('stock_levels')->where('part_id', $partId)->where('location_id', $locationId)
            ->whereRaw('qty_on_hand - qty_reserved >= ?', [$qty])
            ->update(['qty_reserved' => DB::raw('qty_reserved + '.(float) $qty)]);
        if ($n === 0) {
            throw new ApiProblem('STOCK_INSUFFICIENT', 422, null, ['part_id' => $partId]);
        }
    }

    /** تحرير الحجز عند الإلغاء (3.6، 3.7، 3.13) */
    public function release(string $partId, string $locationId, string $qty): void
    {
        DB::table('stock_levels')->where('part_id', $partId)->where('location_id', $locationId)
            ->update(['qty_reserved' => DB::raw('GREATEST(qty_reserved - '.(float) $qty.', 0)')]);
    }

    /** البيع عند التسليم (3.11، 3.12): يُحرَّر الحجز ويُخصم المخزون بحركة sale مرتبطة بالطلب */
    public function sell(string $partId, string $locationId, string $qty, User $by, string $orderId): void
    {
        DB::transaction(function () use ($partId, $locationId, $qty, $by, $orderId) {
            $this->release($partId, $locationId, $qty);
            $this->move($partId, $locationId, '-'.$qty, 'sale', $by, null, ['sales_order_id' => $orderId]);
        });
    }

    /**
     * موقع في الفرع يكفي متاحه الكمية كلها، الأكبر متاحاً أولاً.
     *
     * @return string|null معرّف الموقع
     */
    public function locationFor(string $partId, string $branchId, string $qty): ?string
    {
        return DB::table('stock_levels')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')
            ->where('stock_locations.branch_id', $branchId)
            ->where('stock_levels.part_id', $partId)
            ->whereRaw('qty_on_hand - qty_reserved >= ?', [$qty])
            ->orderByRaw('qty_on_hand - qty_reserved DESC')
            ->value('stock_levels.location_id');
    }
}
