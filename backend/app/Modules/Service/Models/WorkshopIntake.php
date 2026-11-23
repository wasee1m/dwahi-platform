<?php

namespace App\Modules\Service\Models;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** بطاقة الاستلام في الورشة (FR-SRV-08، BR-20) */
class WorkshopIntake extends Model
{
    protected $casts = ['received_at' => 'datetime'];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'workshop_intake_photos', 'intake_id', 'attachment_id');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'signature_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'receipt_pdf_id');
    }
}
