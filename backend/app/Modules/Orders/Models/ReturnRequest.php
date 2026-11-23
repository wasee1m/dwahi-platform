<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** طلب الإرجاع (SM-3b، BR-12) */
class ReturnRequest extends Model
{
    public const OPEN = ['requested', 'approved', 'received'];

    protected $casts = ['decided_at' => 'datetime', 'received_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'return_request_attachments');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
