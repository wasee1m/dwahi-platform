<?php

namespace App\Modules\Quoting\Models;

use App\Models\User;
use App\Modules\Accounts\Models\CustomerEquipment;
use App\Modules\Accounts\Support\BelongsToAccount;
use App\Modules\Identity\Models\Organization;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Models\Branch;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** طلب عرض السعر (SM-1، FR-RFQ-01) */
class Rfq extends Model
{
    use BelongsToAccount;

    public const STATUSES = ['submitted', 'in_review', 'quoted', 'won', 'lost', 'cancelled'];

    protected $casts = ['is_additional' => 'boolean', 'closed_at' => 'datetime'];

    public function personalColumn(): string
    {
        return 'customer_user_id';
    }

    public function customerEquipment(): BelongsTo
    {
        return $this->belongsTo(CustomerEquipment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class)->orderBy('line_no');
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'rfq_attachments')->withPivot('rfq_item_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->orderByDesc('version');
    }

    /** آخر نسخة غير مسودة: ما يراه العميل */
    public function currentQuotation(): HasOne
    {
        return $this->hasOne(Quotation::class)->where('status', '!=', 'draft')->orderByDesc('version');
    }

    /**
     * جهة العميل للإشعارات (01 §8 "المالك والطالب"): الطالب، ومالكو الشركة إن كان الطلب لها.
     * الطالب الذي أُزيل من الشركة لم يعد يرى الطلب، فلا يُبلَّغ بشيء عنه (02 §10).
     *
     * @return Collection<int, User>
     */
    public function customerRecipients(): Collection
    {
        if (! $this->organization_id) {
            return User::query()->whereKey($this->customer_user_id)->get();
        }
        $ids = DB::table('organization_members')->where('organization_id', $this->organization_id)->whereNull('removed_at')
            ->where(fn ($w) => $w->where('role', 'owner')->orWhere('user_id', $this->customer_user_id))->select('user_id');

        return User::query()->whereIn('id', $ids)->get();
    }
}
