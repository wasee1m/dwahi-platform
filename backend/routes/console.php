<?php

use Illuminate\Support\Facades\Schedule;

// المهام المجدولة (02 §9). كلها متكررة الأمان، و onOneServer لأن Scheduler نسخة واحدة (04 §9).
Schedule::command('orders:run expire')->everyFiveMinutes()->onOneServer()->withoutOverlapping(); // J-01
Schedule::command('service:run sla')->everyFiveMinutes()->onOneServer()->withoutOverlapping(); // J-04
Schedule::command('quotations:run expire')->hourly()->onOneServer()->withoutOverlapping(); // J-02
Schedule::command('quotations:run remind')->hourly()->onOneServer()->withoutOverlapping(); // J-03
Schedule::command('accounts:run anonymize')->dailyAt('02:30')->timezone('Asia/Muscat')->onOneServer()->withoutOverlapping(); // J-08
Schedule::command('accounts:run purge-exports')->dailyAt('03:00')->timezone('Asia/Muscat')->onOneServer()->withoutOverlapping();
