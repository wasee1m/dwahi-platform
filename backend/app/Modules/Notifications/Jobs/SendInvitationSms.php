<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Accounts\Models\Invitation;
use App\Modules\Identity\Sms\SmsSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** N-19 لرقم ليس له حساب بعد: رسالة SMS فقط، فلا مركز إشعارات له */
class SendInvitationSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public string $invitationId, public string $text) {}

    public function handle(SmsSender $sms): void
    {
        $invitation = Invitation::query()->open()->find($this->invitationId);
        if ($invitation?->phone_e164) {
            $sms->send($invitation->phone_e164, $this->text); // الملغاة أثناء ساعات الهدوء لا تُرسل
        }
    }
}
