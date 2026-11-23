<?php

namespace App\Modules\Billing\Models;

use App\Support\Model;

class InvoiceItem extends Model
{
    public $timestamps = false;

    protected $casts = ['warranty_until' => 'date'];
}
