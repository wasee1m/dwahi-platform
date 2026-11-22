<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** رمز توثيق البريد (C-02) */
class EmailCode extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $en = ($notifiable->preferred_locale ?? 'ar') === 'en';

        return (new MailMessage)
            ->subject($en ? 'Confirm your email' : 'وثّق بريدك')
            ->line($en ? "Your confirmation code: {$this->code}" : "رمز التوثيق: {$this->code}")
            ->line($en
                ? 'It is valid for 15 minutes. If you did not ask for it, ignore this email.'
                : 'صالح 15 دقيقة. إن لم تطلبه فتجاهل هذه الرسالة.');
    }
}
