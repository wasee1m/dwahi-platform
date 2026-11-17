<?php

namespace App\Models;

use App\Modules\Identity\Authorization\Permissions;
use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\Organization;
use App\Modules\Identity\Models\StaffProfile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * 03 §3: العملاء والموظفون في جدول واحد (user_type). الأدوار في user_roles، والصلاحيات من Permissions.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['user_type', 'full_name', 'phone_e164', 'email', 'password_hash', 'preferred_locale', 'status'];

    protected $hidden = ['password_hash'];

    /** @var list<string>|null */
    private ?array $roleCache = null;

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'internal_flag_at' => 'datetime',
            'deleted_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getRememberTokenName(): ?string
    {
        return null; // لا "تذكرني" بالكوكي، والجلسات الطويلة برموز التحديث (FR-AUTH-04)
    }

    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot(['role', 'can_approve_quotes', 'joined_at', 'removed_at'])
            ->wherePivotNull('removed_at');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return $this->roleCache ??= DB::table('user_roles')->where('user_id', $this->id)->orderBy('role')->pluck('role')->all();
    }

    /** @param list<string> $roles */
    public function syncRoles(array $roles): void
    {
        DB::table('user_roles')->where('user_id', $this->id)->delete();
        DB::table('user_roles')->insert(array_map(fn ($r) => ['user_id' => $this->id, 'role' => $r], array_values(array_unique($roles))));
        $this->roleCache = null;
    }

    public function isStaff(): bool
    {
        return $this->user_type === 'staff';
    }

    public function hasAbility(string $ability): bool
    {
        return $this->isStaff() && $this->status === 'active' && Permissions::allows($this->roleNames(), $ability);
    }

    /** إكمال الملف بعد أول OTP (US-06): يكتمل بقبول الشروط */
    public function needsOnboarding(): bool
    {
        return ! $this->isStaff() && ! $this->consents()->where('kind', 'terms')->where('granted', true)->exists();
    }
}
