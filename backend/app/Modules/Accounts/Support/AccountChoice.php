<?php

namespace App\Modules\Accounts\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * حساب السجل الجديد (06 §2 "شخصي / الشركة"): null شخصي، أو شركة العميل عضو نشط فيها.
 * غياب الحقل يأخذ أقدم شركة له، وهو سلوك نسخ التطبيق السابقة (طلبات العروض فقط).
 */
final class AccountChoice
{
    /** @param array<string, mixed> $data */
    public static function resolve(User $customer, array $data, bool $defaultToCompany = true): ?string
    {
        $orgs = DB::table('organization_members')->where('user_id', $customer->id)->whereNull('removed_at')
            ->orderBy('joined_at')->pluck('organization_id');
        if (! array_key_exists('organization_id', $data)) {
            return $defaultToCompany ? $orgs->first() : null;
        }
        if ($data['organization_id'] !== null && ! $orgs->contains($data['organization_id'])) {
            throw ValidationException::withMessages(['organization_id' => [__('quoting.not_a_member')]]);
        }

        return $data['organization_id'];
    }
}
