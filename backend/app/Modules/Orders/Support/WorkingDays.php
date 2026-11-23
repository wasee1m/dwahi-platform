<?php

namespace App\Modules\Orders\Support;

use App\Modules\Platform\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * أيام العمل بتوقيت مسقط من إعداد ساعات العمل (business_hours، A-18). مهلة الاستلام BR-05:
 * نهاية دوام يوم العمل الـ N بعد يوم الجاهزية، فالجاهز الأحد صباحاً بمهلة 3 أيام ينتهي الأربعاء نهاية الدوام.
 * العطل الرسمية غير محسوبة بعد، فتُمدَّد المهلة يدوياً عند الحاجة (Q-07).
 */
final class WorkingDays
{
    private const MAP = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    public static function deadline(CarbonInterface $from, int $days): CarbonImmutable
    {
        $hours = Setting::read('business_hours', ['days' => ['sun', 'mon', 'tue', 'wed', 'thu'], 'start' => '08:00', 'end' => '17:00']);
        $working = array_map(fn ($d) => self::MAP[$d], $hours['days']);
        [$h, $m] = array_map('intval', explode(':', $hours['end']));

        $day = CarbonImmutable::instance($from)->setTimezone('Asia/Muscat')->startOfDay();
        $left = max(1, $days);
        while ($left > 0) {
            $day = $day->addDay();
            if (in_array($day->dayOfWeek, $working, true)) {
                $left--;
            }
        }

        return $day->setTime($h, $m)->utc();
    }
}
