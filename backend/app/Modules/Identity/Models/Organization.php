<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** حساب شركة (FR-ACC-02). الإدارة الكاملة للأعضاء والتوثيق في S5. */
class Organization extends Model
{
    protected $casts = ['credit_limit' => 'decimal:3', 'preapproval_limit' => 'decimal:3'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot(['role', 'can_approve_quotes', 'joined_at', 'removed_at']);
    }
}
