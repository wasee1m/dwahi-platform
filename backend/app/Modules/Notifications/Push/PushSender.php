<?php

namespace App\Modules\Notifications\Push;

/** الإشعارات الفورية (FR-NOT-02): FCM و APNs. يُربط المزود الفعلي مع بناء التطبيقات للمتاجر. */
interface PushSender
{
    /** @param array<string, string> $data */
    public function send(string $token, string $title, string $body, array $data): void;
}
