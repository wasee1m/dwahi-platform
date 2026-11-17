<?php

namespace App\Modules\Identity\Sms;

/** مزود الرسائل القصيرة (FR-INT-02). المزود الفعلي واسم المرسل قرار الشركة (Q-04). */
interface SmsSender
{
    public function send(string $toE164, string $text): void;
}
