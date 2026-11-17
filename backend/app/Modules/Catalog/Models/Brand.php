<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Brand extends Model
{
    protected $casts = ['is_represented' => 'boolean', 'is_active' => 'boolean'];

    public function logo(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'logo_id');
    }
}
