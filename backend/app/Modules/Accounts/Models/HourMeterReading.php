<?php

namespace App\Modules\Accounts\Models;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** قراءة عداد الساعات. الأقل من السابقة تُقبل بتأكيد وملاحظة وتُعلَّم للمراجعة (03 §6). */
class HourMeterReading extends Model
{
    protected $casts = ['reading' => 'integer', 'needs_review' => 'boolean', 'recorded_at' => 'datetime'];

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'photo_id');
    }
}
