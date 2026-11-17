<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** جلسة تحديث (جدول sessions، FR-AUTH-04). رمز الوصول القصير ينتمي لها. */
class AuthSession extends Model
{
    protected $table = 'sessions';

    protected $casts = [
        'remember' => 'boolean',
        'rotated_at' => 'datetime',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
