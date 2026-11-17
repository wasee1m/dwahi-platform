<?php

namespace App\Modules\Identity\Models;

use App\Support\Model;

/** سجل الموافقات الإلحاقي (NFR-13) */
class Consent extends Model
{
    public const UPDATED_AT = null;

    protected $casts = ['granted' => 'boolean'];
}
