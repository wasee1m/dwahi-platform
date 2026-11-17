<?php

namespace App\Modules\Parts\Support;

/**
 * تطبيع رقم القطعة: أحرف وأرقام لاتينية كبيرة فقط. يطابق العمود المولّد part_number_normalized (03 §5).
 */
final class PartNumber
{
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
