<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** الاسترداد (FR-PAY-06): يدوي في P1، ومعه إشعار دائن دائماً */
class Refund extends Model
{
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
