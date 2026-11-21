<?php

namespace App\Modules\Quoting\Models;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** عرض السعر بنسخه (SM-2). لا يُعدَّل بعد الإرسال: كل تعديل نسخة جديدة. */
class Quotation extends Model
{
    protected $casts = [
        'valid_until' => 'date', 'sent_at' => 'datetime', 'responded_at' => 'datetime',
        'expiry_reminded_at' => 'datetime', 'approval_requested_at' => 'datetime',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('line_no');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function pdf(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'pdf_id');
    }

    public function lpo(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'lpo_attachment_id');
    }

    /** صالح للقبول: مرسل ولم تنتهِ صلاحيته بتوقيت مسقط (BR-02) */
    public function isAcceptable(): bool
    {
        return $this->status === 'sent' && $this->valid_until && now('Asia/Muscat')->startOfDay()->lte($this->valid_until);
    }
}
