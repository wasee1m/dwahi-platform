<?php

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** J-10: ملخص الرسائل غير المقروءة بلغة المستلم، وزر يفتح أحدثها */
class DigestMail extends Mailable
{
    /** @param list<object> $rows */
    public function __construct(public array $rows, public string $lang) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.digest.title', ['count' => count($this->rows)], $this->lang));
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.web_url'), '/');
        $latest = end($this->rows);
        $lines = array_map(fn ($r) => "• {$r->title}: {$r->body}", array_slice($this->rows, -5));

        return new Content(view: 'mail.notification', with: [
            'title' => __('notifications.digest.title', ['count' => count($this->rows)], $this->lang),
            'body' => implode("\n", $lines),
            'url' => "{$base}/{$this->lang}{$latest->deep_link}",
            'lang' => $this->lang,
            'action' => $this->lang === 'en' ? 'Open' : 'فتح',
        ]);
    }
}
