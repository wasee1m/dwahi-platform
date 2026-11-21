<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** إشعار في مركز الإشعارات (C-19). اسمه هكذا لتجنب الالتباس مع Notification في Laravel. */
class UserNotification extends Model
{
    protected $table = 'notifications';

    public const UPDATED_AT = null;

    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'notification_id');
    }
}
