<?php

namespace App\Modules\Notifications\Push;

use Illuminate\Support\Facades\Log;

/** للتطوير والاختبار: يكتب في السجل بدل الإرسال */
final class LogPushSender implements PushSender
{
    public function send(string $token, string $title, string $body, array $data): void
    {
        Log::info('push', ['token' => substr($token, 0, 8).'…', 'title' => $title, 'data' => $data]);
    }
}
