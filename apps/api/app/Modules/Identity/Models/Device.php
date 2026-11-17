<?php

namespace App\Modules\Identity\Models;

use App\Support\Model;

class Device extends Model
{
    protected $casts = ['last_seen_at' => 'datetime'];
}
