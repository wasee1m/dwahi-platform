<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * أساس كل نماذج المجال (03 §1): مفتاح UUIDv7، وأوقات DATETIME(6) بتوقيت UTC.
 */
abstract class Model extends Eloquent
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];
}
