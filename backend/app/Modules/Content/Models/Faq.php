<?php

namespace App\Modules\Content\Models;

use App\Support\Localized;
use App\Support\Model;

class Faq extends Model
{
    use Localized;

    protected $casts = ['is_active' => 'boolean'];
}
