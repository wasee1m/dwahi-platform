<?php

namespace App\Modules\Accounts\Models;

use App\Models\User;
use App\Modules\Identity\Models\Organization;
use App\Support\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** دعوة عضو (SM-7): pending ← accepted أو revoked، وتنتهي بعد 7 أيام */
class Invitation extends Model
{
    public const DAYS = 7;

    protected $table = 'organization_invitations';

    protected $hidden = ['pending_key'];

    protected $casts = ['can_approve_quotes' => 'boolean', 'expires_at' => 'datetime'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'pending')->where('expires_at', '>', now());
    }
}
