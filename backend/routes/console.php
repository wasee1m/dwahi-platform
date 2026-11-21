<?php

use Illuminate\Support\Facades\Schedule;

// المهام المجدولة (02 §9). كلها متكررة الأمان، و onOneServer لأن Scheduler نسخة واحدة (04 §9).
Schedule::command('quotations:run expire')->hourly()->onOneServer()->withoutOverlapping(); // J-02
Schedule::command('quotations:run remind')->hourly()->onOneServer()->withoutOverlapping(); // J-03
