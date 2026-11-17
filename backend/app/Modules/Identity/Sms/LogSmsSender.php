<?php

namespace App\Modules\Identity\Sms;

use Illuminate\Support\Facades\Log;

/** للتطوير والاختبار فقط: يكتب الرسالة في السجل بدل إرسالها. */
final class LogSmsSender implements SmsSender
{
    /** @var list<array{to: string, text: string}> */
    public array $sent = [];

    public function send(string $toE164, string $text): void
    {
        $this->sent[] = ['to' => $toE164, 'text' => $text];
        Log::channel(config('services.sms.log_channel', 'stack'))->info('[sms]', ['to' => $toE164, 'text' => $text]);
    }
}
