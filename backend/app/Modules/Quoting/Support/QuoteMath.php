<?php

namespace App\Modules\Quoting\Support;

/**
 * حساب السطر والإجماليات (BR-01): ريال عُماني بثلاث خانات، والضريبة لكل سطر بنسبته وتُقرَّب لأقرب بيسة
 * (نصف للأعلى) على مستوى السطر. bcmath بالكامل، فلا أرقام عائمة.
 */
final class QuoteMath
{
    /** @return array{line_subtotal: string, line_vat: string, line_total: string} */
    public static function line(string $quantity, string $unitPrice, string $discount, string $vatRate): array
    {
        $gross = self::round(bcmul($quantity, $unitPrice, 6), 3);
        $subtotal = bcsub($gross, self::round($discount, 3), 3);
        if (bccomp($subtotal, '0', 3) < 0) {
            throw new \InvalidArgumentException('discount exceeds line amount');
        }
        $vat = self::round(bcdiv(bcmul($subtotal, $vatRate, 6), '100', 6), 3);

        return ['line_subtotal' => $subtotal, 'line_vat' => $vat, 'line_total' => bcadd($subtotal, $vat, 3)];
    }

    /**
     * @param  iterable<array{quantity: string, unit_price: string, discount_amount: string, line_subtotal: string, line_vat: string}>  $lines
     * @return array{subtotal: string, discount_total: string, vat_total: string, total: string}
     */
    public static function totals(iterable $lines): array
    {
        $subtotal = $discount = $vat = '0.000';
        foreach ($lines as $l) {
            // المجموع قبل الخصم، ثم الخصم سطراً منفصلاً في العرض
            $subtotal = bcadd($subtotal, bcadd((string) $l['line_subtotal'], (string) $l['discount_amount'], 3), 3);
            $discount = bcadd($discount, (string) $l['discount_amount'], 3);
            $vat = bcadd($vat, (string) $l['line_vat'], 3);
        }

        return ['subtotal' => $subtotal, 'discount_total' => $discount, 'vat_total' => $vat, 'total' => bcadd(bcsub($subtotal, $discount, 3), $vat, 3)];
    }

    /** تقريب نصف للأعلى لعدد موجب أو سالب (bcmath يقطع ولا يقرّب) */
    public static function round(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return str_starts_with($value, '-') ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);
    }
}
