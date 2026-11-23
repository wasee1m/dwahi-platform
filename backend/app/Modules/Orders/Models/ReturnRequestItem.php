<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بند مرتجع. مفتاح مركّب (طلب الإرجاع، بند الطلب)، فلا معرّف خاص ويُعدَّل بالاستعلام */
class ReturnRequestItem extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected $casts = ['restocked' => 'boolean'];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'order_item_id');
    }
}
