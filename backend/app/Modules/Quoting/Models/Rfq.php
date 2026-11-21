<?php

namespace App\Modules\Quoting\Models;

use App\Models\User;
use App\Modules\Identity\Models\Organization;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Models\Branch;
use App\Support\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/** طلب عرض السعر (SM-1، FR-RFQ-01) */
class Rfq extends Model
{
    public const STATUSES = ['submitted', 'in_review', 'quoted', 'won', 'lost', 'cancelled'];

    protected $casts = ['is_additional' => 'boolean', 'closed_at' => 'datetime'];

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
     * رؤية العميل (03 §13.1): طلباته الفردية، أو طلبات شركة هو عضو نشط فيها.
     * كل استعلام للعميل على هذا الجدول يمر من هنا (والاختبارات تتحقق أن عميلاً لا يرى طلب غيره).
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $orgs = DB::table('organization_members')->where('user_id', $user->id)->whereNull('removed_at')->pluck('organization_id');

        return $q->where(fn ($w) => $w->where(fn ($x) => $x->whereNull('organization_id')->where('customer_user_id', $user->id))
            ->orWhereIn('organization_id', $orgs));
    }
}
