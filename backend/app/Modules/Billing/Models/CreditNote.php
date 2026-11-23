<?php

namespace App\Modules\Billing\Models;

use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إشعار دائن (CN-): إلغاء فاتورة صادرة أو استرداد بعد الدفع (SM-6a، FR-PAY-06) */
class CreditNote extends Model
{
    protected $casts = ['issued_at' => 'datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function pdf(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'pdf_id');
    }
}
