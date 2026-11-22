<?php

namespace App\Modules\Accounts\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * سجل يتبع حساباً شخصياً أو شركة (03 §13.1). كل استعلام للعميل على هذه الجداول يبدأ بـ visibleTo،
 * والسجل غير المرئي 404 لا 403 فلا يُكشف وجوده.
 */
trait BelongsToAccount
{
    /** عمود صاحب السجل الشخصي */
    abstract public function personalColumn(): string;

    /** الشخصي لصاحبه، وسجل الشركة لأعضائها النشطين فقط */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $org = $this->qualifyColumn('organization_id');
        $personal = $this->qualifyColumn($this->personalColumn());

        return $query->where(fn ($w) => $w
            ->where(fn ($x) => $x->whereNull($org)->where($personal, $user->id))
            ->orWhereIn($org, Memberships::organizationIds($user)));
    }

    /** تصفية بمحدد الحساب (06 §2): "personal"، أو معرّف شركة، أو الكل */
    public function scopeForAccount(Builder $query, ?string $account): Builder
    {
        $org = $this->qualifyColumn('organization_id');

        return match ($account) {
            null, '' => $query,
            'personal' => $query->whereNull($org),
            default => $query->where($org, $account),
        };
    }

    /** السجل من الحساب المحدد نفسه، شخصياً أو شركة */
    public function scopeOwnedBy(Builder $query, User $user, ?string $organizationId): Builder
    {
        $org = $this->qualifyColumn('organization_id');

        return $organizationId
            ? $query->where($org, $organizationId)
            : $query->whereNull($org)->where($this->qualifyColumn($this->personalColumn()), $user->id);
    }
}
