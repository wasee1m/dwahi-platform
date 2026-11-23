<?php

namespace App\Modules\Orders\Models;

use App\Modules\Parts\Models\Part;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بند طلب القطع: نسخة ثابتة من القطعة وسعرها لحظة الطلب (BR-17) */
class SalesOrderItem extends Model
{
    public $timestamps = false;

    protected $casts = ['is_returnable' => 'boolean'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'order_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
