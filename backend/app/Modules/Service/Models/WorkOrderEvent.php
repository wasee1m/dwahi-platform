<?php

namespace App\Modules\Service\Models;

use App\Models\User;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر في دفتر حالات أمر العمل (FR-WO-03) */
class WorkOrderEvent extends Model
{
    protected $table = 'work_order_status_events';

    public $timestamps = false;

    protected $casts = ['occurred_at' => 'datetime', 'received_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
