<?php

namespace App\Modules\Service\Models;

use App\Models\User;
use App\Modules\Accounts\Models\CustomerEquipment;
use App\Modules\Accounts\Support\BelongsToAccount;
use App\Modules\Accounts\Support\Memberships;
use App\Modules\Identity\Models\Organization;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Models\Branch;
use App\Modules\Quoting\Models\Rfq;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/** طلب الصيانة (SM-4، FR-SRV-01): ما يراه العميل، والتنفيذ في أوامر العمل */
class ServiceRequest extends Model
{
    use BelongsToAccount;

    public const STATUSES = ['submitted', 'triaged', 'awaiting_approval', 'approved', 'in_service', 'completed', 'closed', 'cancelled', 'declined'];

    /** مفتوح: لم يُغلق ولم يُلغَ ولم يُرفض */
    public const OPEN = ['submitted', 'triaged', 'awaiting_approval', 'approved', 'in_service', 'completed'];

    public const COMPONENTS = ['diesel_engine', 'hydraulic_pump', 'hydraulic_cylinder', 'transmission', 'other'];

    protected $casts = [
        'fees_acknowledged' => 'boolean',
        'preferred_date' => 'date',
        'sla_due_at' => 'datetime',
        'sla_alerted_at' => 'datetime',
        'triaged_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function personalColumn(): string
    {
        return 'customer_user_id';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(CustomerEquipment::class, 'customer_equipment_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'service_request_attachments')->withPivot('kind');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class)->orderBy('created_at');
    }

    public function intake(): HasOne
    {
        return $this->hasOne(WorkshopIntake::class);
    }

    /** طلبات التقدير (عرض سعر نوع "صيانة")، والأساسي غير الإضافي */
    public function estimates(): HasMany
    {
        return $this->hasMany(Rfq::class)->orderByDesc('created_at');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** @return Collection<int, User> */
    public function customerRecipients(): Collection
    {
        return Memberships::recipients($this->customer_user_id, $this->organization_id);
    }
}
