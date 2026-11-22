<?php

namespace App\Modules\Notifications\Support;

use Illuminate\Support\Facades\DB;

/** هل يصل الإشعار غير الإلزامي على هذه القناة؟ (FR-NOT-03) */
final class Preferences
{
    public static function enabled(string $userId, string $category, string $channel): bool
    {
        // التسويقي يتطلب موافقة صريحة أولاً (01 §8)
        if ($category === 'marketing' && ! self::marketingConsent($userId)) {
            return false;
        }
        $pref = DB::table('notification_preferences')
            ->where(['user_id' => $userId, 'category' => $category, 'channel' => $channel])->value('enabled');

        return $pref === null || (bool) $pref;
    }

    /** آخر قرار في سجل الموافقات */
    public static function marketingConsent(string $userId): bool
    {
        return (bool) DB::table('consents')->where(['user_id' => $userId, 'kind' => 'marketing'])
            ->orderByDesc('created_at')->orderByDesc('id')->value('granted');
    }
}
