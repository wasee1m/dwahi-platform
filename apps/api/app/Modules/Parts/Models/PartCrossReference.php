<?php

namespace App\Modules\Parts\Models;

use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartCrossReference extends Model
{
    public $timestamps = false;

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
