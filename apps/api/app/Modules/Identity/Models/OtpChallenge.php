<?php

namespace App\Modules\Identity\Models;

use App\Support\Model;

class OtpChallenge extends Model
{
    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
