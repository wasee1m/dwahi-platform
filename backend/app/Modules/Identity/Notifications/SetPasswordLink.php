<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** رابط إعداد كلمة المرور: تفعيل حساب موظف جديد (US-39) أو استعادة كلمة المرور (FR-AUTH-04) */
class SetPasswordLink extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token, private readonly bool $staff, private readonly bool $activation) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $en = ($notifiable->preferred_locale ?? 'ar') === 'en';
        $path = $this->staff ? 'admin/set-password' : 'account/set-password';
        $url = rtrim((string) config('app.web_url'), '/').'/'.($en ? 'en' : 'ar').'/'.$path
            .'?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]);

        return $this->activation
            ? (new MailMessage)
                ->subject($en ? 'Activate your DWAHI-ALBADIA account' : 'فعّل حسابك في ضواحي البادية')
                ->line($en ? 'An account was created for you. Set your password to activate it. You will then set up two-factor authentication.'
                    : 'أُنشئ لك حساب موظف. عيّن كلمة المرور لتفعيله، ثم تُعدّ التحقق الثنائي عند أول دخول.')
                ->action($en ? 'Set password' : 'تعيين كلمة المرور', $url)
            : (new MailMessage)
                ->subject($en ? 'Reset your password' : 'استعادة كلمة المرور')
                ->line($en ? 'Use this link to set a new password. It expires in 60 minutes.' : 'استخدم هذا الرابط لتعيين كلمة مرور جديدة. ينتهي خلال 60 دقيقة.')
                ->action($en ? 'Set a new password' : 'تعيين كلمة مرور جديدة', $url);
    }
}
