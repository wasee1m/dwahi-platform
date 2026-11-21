<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Identity\Sms\SmsSender;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Push\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** إرسال إشعار على قناة واحدة. متكرر الأمان: المرسل لا يُعاد إرساله (02 §9). */
class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public string $deliveryId) {}

    public function handle(SmsSender $sms, PushSender $push): void
    {
        $d = NotificationDelivery::query()->with('notification.user')->find($this->deliveryId);
        if (! $d || in_array($d->status, ['sent', 'delivered', 'skipped'], true)) {
            return;
        }
        $n = $d->notification;
        $user = $n->user;
        $d->increment('attempts');

        try {
            $sent = match ($d->channel) {
                'email' => $user->email ? (bool) Mail::to($user->email)->send(new NotificationMail($n, $user->preferred_locale === 'en' ? 'en' : 'ar')) : false,
                'sms' => $user->phone_e164 ? $this->sms($sms, $user->phone_e164, $n->title, $n->body) : false,
                'push' => $this->push($push, $user->id, $n->title, $n->body, ['link' => $n->deep_link, 'id' => $n->id]),
                default => false,
            };
            $d->update(['status' => $sent ? 'sent' : 'skipped', 'sent_at' => $sent ? now() : null]);
        } catch (\Throwable $e) {
            $d->update(['status' => 'failed', 'last_error' => substr($e->getMessage(), 0, 1000)]);
            throw $e; // يعيد الطابور المحاولة
        }
    }

    private function sms(SmsSender $sms, string $to, string $title, string $body): bool
    {
        $sms->send($to, "{$title}\n{$body}");

        return true;
    }

    /** على كل جهاز للمستخدم برمز Push. لا جهاز = تُتخطى القناة. */
    private function push(PushSender $push, string $userId, string $title, string $body, array $data): bool
    {
        $tokens = DB::table('devices')->where('user_id', $userId)->where('status', 'active')->whereNotNull('push_token')->pluck('push_token');
        foreach ($tokens as $t) {
            $push->send($t, $title, $body, $data);
        }

        return $tokens->isNotEmpty();
    }
}
