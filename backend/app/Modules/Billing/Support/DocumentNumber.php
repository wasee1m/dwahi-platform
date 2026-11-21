<?php

namespace App\Modules\Billing\Support;

use Illuminate\Support\Facades\DB;

/**
 * الأرقام المرجعية (BR-14): <بادئة>-<سنة>-<تسلسل 6 أرقام>. تسلسل لكل بادئة وسنة بقفل صف
 * (SELECT ... FOR UPDATE) داخل معاملة المستند نفسه، فلا تكرار ولا فجوة عند فشل المعاملة.
 */
final class DocumentNumber
{
    public static function next(string $prefix, ?int $year = null): string
    {
        $year ??= (int) now('Asia/Muscat')->format('Y');

        return DB::transaction(function () use ($prefix, $year) {
            $row = DB::table('number_sequences')->where('prefix', $prefix)->where('year', $year)->lockForUpdate()->first();
            if ($row) {
                $value = $row->last_value + 1;
                DB::table('number_sequences')->where('prefix', $prefix)->where('year', $year)->update(['last_value' => $value]);
            } else {
                $value = 1;
                DB::table('number_sequences')->insert(['prefix' => $prefix, 'year' => $year, 'last_value' => $value]);
            }

            return sprintf('%s-%d-%06d', $prefix, $year, $value);
        });
    }
}
