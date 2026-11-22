<?php

namespace App\Modules\Notifications\Support;

use Illuminate\Support\Carbon;

/** لا SMS بين 10 مساءً و 7 صباحاً بتوقيت مسقط، إلا رمز التحقق والعاجل (01 §8). التأجيل في الطابور (04). */
final class QuietHours
{
    private const FROM = 22;

    private const UNTIL = 7;

    /** موعد الإرسال: null الآن، أو السابعة صباحاً التالية */
    public static function sendAt(?Carbon $now = null): ?Carbon
    {
        $local = ($now ?? now())->copy()->timezone('Asia/Muscat');
        if ($local->hour >= self::UNTIL && $local->hour < self::FROM) {
            return null;
        }
        if ($local->hour >= self::FROM) {
            $local->addDay();
        }

        return $local->setTime(self::UNTIL, 0)->utc();
    }
}
