<?php

namespace App\Modules\Content\Models;

use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Banner extends Model
{
    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];

    public function imageAr(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'image_ar_id');
    }

    public function imageEn(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'image_en_id');
    }
}
