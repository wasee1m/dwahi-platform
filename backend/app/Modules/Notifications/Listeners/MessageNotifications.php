<?php

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Messaging\Events\MessagePosted;
use App\Modules\Notifications\Services\Notifier;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** N-17: رسالة جديدة للطرف الآخر. رسالة العميل للموظف المسند (وإلا فريق المبيعات)، ورسالة الموظف للعميل. */
class MessageNotifications implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(MessagePosted $e): void
    {
        $rfq = $e->rfq;
        $sender = $e->message->sender;
        $params = [
            'number' => $rfq->number,
            'sender' => (string) $sender?->full_name,
            'preview' => Str::limit((string) ($e->message->body ?? '📎'), 80),
        ];

        if ($sender?->isStaff()) {
            $this->notifier->notify($rfq->customerRecipients(), 'N-17', $params, "/account/quotes/{$rfq->id}#messages");

            return;
        }
        $staff = $rfq->assigned_to
            ? User::query()->whereKey($rfq->assigned_to)->get()
            : User::query()->where('status', 'active')->whereIn('id', DB::table('user_roles')->where('role', 'sales')->select('user_id'))->get();
        $this->notifier->notify($staff, 'N-17', $params, "/admin/rfqs/{$rfq->id}#messages");
    }
}
