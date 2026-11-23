<?php

namespace App\Modules\Service\Support;

use App\Modules\Platform\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * مهلة الفرز (BR-09): "عاجل" بالساعات على مدار الساعة، و"عادي" بساعات العمل فقط من إعداد business_hours
 * (A-18) بتوقيت مسقط. العطل الرسمية لم تُحسب بعد.
 */
final class Sla
{
    private const MAP = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    public static function due(string $urgency, CarbonInterface $from): CarbonImmutable
    {
        $sla = Setting::read('sla.triage', ['normal_working_hours' => 4, 'urgent_hours' => 1]);
        $start = CarbonImmutable::instance($from);
        if ($urgency === 'urgent') {
            return $start->addHours((int) $sla['urgent_hours'])->utc();
        }

        $hours = Setting::read('business_hours', ['days' => ['sun', 'mon', 'tue', 'wed', 'thu'], 'start' => '08:00', 'end' => '17:00']);
        $days = array_map(fn ($d) => self::MAP[$d], $hours['days']);
        [$sh, $sm] = array_map('intval', explode(':', $hours['start']));
        [$eh, $em] = array_map('intval', explode(':', $hours['end']));

        $left = (int) $sla['normal_working_hours'] * 60;
        $t = $start->setTimezone('Asia/Muscat');
        for ($guard = 0; $guard < 400 && $left > 0; $guard++) {
            $open = $t->setTime($sh, $sm);
            $close = $t->setTime($eh, $em);
            if (! in_array($t->dayOfWeek, $days, true) || $t->gte($close)) {
                $t = $t->addDay()->setTime($sh, $sm);

                continue;
            }
            if ($t->lt($open)) {
                $t = $open;
            }
            $available = (int) $t->diffInMinutes($close);
            if ($left <= $available) {
                return $t->addMinutes($left)->utc();
            }
            $left -= $available;
            $t = $t->addDay()->setTime($sh, $sm);
        }

        return $t->utc();
    }
}
