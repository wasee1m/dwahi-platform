<?php

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use App\Modules\Identity\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** المستخدم الحالي (GET /me) @mixin User */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $this->isStaff();

        // الأنواع بتعليقات @var ليولّدها Scramble بدقة في العقد (NFR-19)
        return [
            'id' => $this->id,
            /** @var 'customer'|'staff' */
            'user_type' => $this->user_type,
            'full_name' => $this->full_name,
            /** @var string|null */
            'phone' => $this->phone_e164,
            /** @var string|null */
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            /** @var 'ar'|'en' */
            'preferred_locale' => $this->preferred_locale,
            'has_password' => $this->password_hash !== null,
            'needs_onboarding' => $this->needsOnboarding(),
            /** @var list<array{id: string, name: string, role: 'owner'|'member', can_approve_quotes: bool}> */
            'organizations' => $staff ? [] : $this->organizations->map(fn ($o) => [
                'id' => $o->id, 'name' => $o->name, 'role' => $o->pivot->role,
                'can_approve_quotes' => (bool) $o->pivot->can_approve_quotes,
            ])->values()->all(),
            /** @var list<string> */
            'roles' => $staff ? $this->roleNames() : [],
            /** @var list<string> */
            'permissions' => $staff ? Permissions::forRoles($this->roleNames()) : [],
            /** @var bool|null */
            'two_factor_enabled' => $staff ? $this->staffProfile?->totp_enabled_at !== null : null,
        ];
    }
}
