<?php

namespace App\Modules\Accounts\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** العضوية النشطة في الشركات: أساس قواعد الرؤية (03 §13.1) */
final class Memberships
{
    /** استعلام فرعي بمعرّفات شركات المستخدم النشطة */
    public static function organizationIds(User $user): Builder
    {
        return DB::table('organization_members')->where('user_id', $user->id)->whereNull('removed_at')->select('organization_id');
    }

    public static function role(User $user, string $organizationId): ?string
    {
        return DB::table('organization_members')->where('organization_id', $organizationId)->where('user_id', $user->id)
            ->whereNull('removed_at')->value('role');
    }

    public static function includes(User $user, string $organizationId): bool
    {
        return self::role($user, $organizationId) !== null;
    }
}
