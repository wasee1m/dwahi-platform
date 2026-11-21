<?php

namespace App\Modules\Notifications\Models;

use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حالة الإرسال لكل قناة. داخلية، لا تُعرض للعميل (03 §11). */
class NotificationDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $casts = ['sent_at' => 'datetime'];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(UserNotification::class, 'notification_id');
    }
}
