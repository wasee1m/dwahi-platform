<?php

namespace App\Modules\Service\Models;

use App\Models\User;
use App\Modules\Platform\Models\Branch;
use App\Modules\Quoting\Models\Quotation;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** أمر العمل (SM-5): فحص أو إصلاح في الورشة، بفني رئيسي ومساعدين ونافذة زمنية */
class WorkOrder extends Model
{
    public const STATUSES = ['unassigned', 'scheduled', 'in_progress', 'on_hold', 'work_completed', 'verified', 'closed', 'cancelled'];

    /** نشط في جدول الفنيين: يدخل في كشف التعارض */
    public const ACTIVE = ['scheduled', 'in_progress', 'on_hold'];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
        'no_charge' => 'boolean',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkOrderEvent::class)->orderBy('occurred_at');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** الفنيون المسندون الآن */
    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_order_assignments', 'work_order_id', 'technician_user_id')
            ->withPivot(['role', 'assigned_at'])->wherePivotNull('unassigned_at');
    }
}
