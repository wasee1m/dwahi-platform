<?php

namespace App\Modules\Content\Models;

use App\Models\User;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ملاحظة أو تغيير حالة على استفسار (US-43). إلحاقية: لا تُعدّل بعد حفظها. */
class LeadNote extends Model
{
    public const UPDATED_AT = null;

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
