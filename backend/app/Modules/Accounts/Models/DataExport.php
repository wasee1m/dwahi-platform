<?php

namespace App\Modules\Accounts\Models;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نسخة بيانات العميل (FR-ACC-06) */
class DataExport extends Model
{
    /** مدة بقاء الملف. الرابط نفسه موقّع 15 دقيقة ويتجدد عند فتح الصفحة. */
    public const KEEP_DAYS = 7;

    protected $casts = ['completed_at' => 'datetime', 'expires_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
