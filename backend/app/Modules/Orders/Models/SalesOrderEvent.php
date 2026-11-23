<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر في الخط الزمني للطلب (C-14، M-19) */
class SalesOrderEvent extends Model
{
    public const UPDATED_AT = null;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
