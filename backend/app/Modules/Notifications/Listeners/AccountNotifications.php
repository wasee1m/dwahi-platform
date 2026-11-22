<?php

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Accounts\Events\DataExportReady;
use App\Modules\Accounts\Events\MemberInvited;
use App\Modules\Accounts\Models\DataExport;
use App\Modules\Notifications\Jobs\SendInvitationSms;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Notifications\Support\QuietHours;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Str;

/** N-19 دعوة للانضمام لحساب شركة، و N-33 نسخة البيانات جاهزة */
class AccountNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly Notifier $notifier) {}

    public function exportReady(DataExportReady $e): void
    {
        $this->notifier->notify($e->export->loadMissing('user')->user, 'N-33', ['days' => DataExport::KEEP_DAYS], '/account/settings');
    }

    public function invited(MemberInvited $e): void
    {
        $invitation = $e->invitation->loadMissing('organization');
        $params = ['company' => self::plain($invitation->organization->name), 'inviter' => self::plain($e->inviter->full_name)];
        $user = User::query()->where('phone_e164', $invitation->phone_e164)->first();
        if ($user) {
            $this->notifier->notify($user, 'N-19', $params, '/account/company');

            return;
        }
        $locale = $e->inviter->preferred_locale === 'en' ? 'en' : 'ar';
        $url = rtrim((string) config('app.web_url'), '/')."/{$locale}/account/company";
        $text = __('notifications.N-19.title', $params, $locale)."\n".__('notifications.N-19.sms', $params, $locale)."\n{$url}";
        SendInvitationSms::dispatch($invitation->id, $text)->afterCommit()->delay(QuietHours::sendAt());
    }

    /** اسم يكتبه المستخدم ويخرج في رسالة باسم الشركة: لا روابط فيه، حتى لا يُستعمل للتصيد */
    private static function plain(string $text): string
    {
        $clean = preg_replace('/\S*(:\/\/|www\.|\w\.[a-z]{2,})\S*/iu', '', $text);

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $clean)), 60);
    }
}
