<?php

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Models\UserNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** بريد الإشعار بلغة المستلم، مع زر يفتح الشاشة المعنية */
class NotificationMail extends Mailable
{
    public function __construct(public UserNotification $notification, public string $lang) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->title);
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.web_url'), '/');

        return new Content(view: 'mail.notification', with: [
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'url' => "{$base}/{$this->lang}{$this->notification->deep_link}",
            'lang' => $this->lang,
            'action' => $this->lang === 'en' ? 'Open' : 'فتح',
        ]);
    }
}
