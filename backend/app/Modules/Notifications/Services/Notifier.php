<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Notifications\Support\EventCatalog;
use App\Modules\Notifications\Support\Preferences;
use App\Modules\Notifications\Support\QuietHours;

/**
 * إنشاء الإشعار بلغة المستلم في مركز الإشعارات، ثم إرساله على قنواته في الطابور (FR-NOT-01، FR-NOT-02).
 * النص من lang/{ar,en}/notifications.php بالمعاملات، والرابط نسبي يبدأ بـ /account أو /admin.
 */
final class Notifier
{
    /**
     * @param  iterable<User>|User  $users
     * @param  array<string, string|int|\Closure(string): string>  $params  الدالة تُعطى لغة المستلم (نص مترجم داخل النص)
     * @param  array<string, mixed>  $data
     * @param  string|null  $template  نص بديل لنفس الحدث (مثل N-01-staff لفريق المبيعات)
     */
    public function notify(iterable|User $users, string $code, array $params, string $deepLink, array $data = [], ?string $template = null): void
    {
        $template ??= $code;
        $def = EventCatalog::EVENTS[$code] ?? throw new \InvalidArgumentException("unknown event {$code}");
        $seen = [];
        foreach ($users instanceof User ? [$users] : $users as $user) {
            if (isset($seen[$user->id]) || $user->status !== 'active') {
                continue; // مستلم مكرر (المالك هو الطالب) أو حساب موقوف
            }
            $seen[$user->id] = true;
            $locale = $user->preferred_locale === 'en' ? 'en' : 'ar';
            $p = array_map(fn ($v) => $v instanceof \Closure ? $v($locale) : (string) $v, $params);

            $n = UserNotification::query()->create([
                'user_id' => $user->id,
                'event_code' => $code,
                'title' => __("notifications.{$template}.title", $p, $locale),
                'body' => __("notifications.{$template}.body", $p, $locale),
                'deep_link' => $deepLink,
                'data' => $data + ['category' => $def['category']],
                'created_at' => now(),
            ]);

            foreach ($def['channels'] as $channel) {
                if (! $def['mandatory'] && ! Preferences::enabled($user->id, $def['category'], $channel)) {
                    continue;
                }
                $d = NotificationDelivery::query()->create(['notification_id' => $n->id, 'channel' => $channel, 'status' => 'queued', 'created_at' => now()]);
                DeliverNotification::dispatch($d->id)->afterCommit()
                    ->delay($channel === 'sms' ? QuietHours::sendAt() : null);
            }
        }
    }
}
