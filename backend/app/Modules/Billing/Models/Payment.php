<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Platform\Models\Branch;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** الدفعة (SM-6b). في P1 و P2 يدوية فقط: نقد، أو بطاقة بنقاط البيع، أو تحويل، أو شيك (FR-PAY-02) */
class Payment extends Model
{
    public const MANUAL_METHODS = ['cash', 'pos_card', 'bank_transfer', 'cheque'];

    /** حالات المبلغ المحصَّل فعلاً (الاسترداد لا يلغي أن الدفعة حدثت) */
    public const COLLECTED = ['succeeded', 'partially_refunded', 'refunded'];

    protected $casts = ['received_at' => 'datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('created_at');
    }

    public function refundable(): string
    {
        return bcsub((string) $this->amount, (string) $this->refunded_amount, 3);
    }
}
