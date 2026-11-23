<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Accounts\Support\BelongsToAccount;
use App\Modules\Accounts\Support\Memberships;
use App\Modules\Identity\Models\Organization;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Platform\Models\Attachment;
use App\Modules\Platform\Models\Branch;
use App\Modules\Quoting\Models\Quotation;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** الفاتورة الضريبية (SM-6a، FR-PAY-03). لا تعديل بعد الإصدار، والتصحيح بإشعار دائن. */
class Invoice extends Model
{
    use BelongsToAccount;

    public const STATUSES = ['draft', 'issued', 'partially_paid', 'paid', 'void'];

    /** مستحقة: صدرت ولم تُسدد كاملة (BR-15) */
    public const UNPAID = ['issued', 'partially_paid'];

    protected $casts = [
        'seller_snapshot' => 'array',
        'buyer_snapshot' => 'array',
        'issue_date' => 'date',
        'due_date' => 'date',
        'issued_at' => 'datetime',
    ];

    public function personalColumn(): string
    {
        return 'customer_user_id';
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('line_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_at');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('issued_at');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function pdf(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'pdf_id');
    }

    /** "متأخرة" ليست حالة: الاستحقاق مضى والفاتورة غير مسددة (BR-15) */
    public function isOverdue(): bool
    {
        return in_array($this->status, self::UNPAID, true) && (bool) $this->due_date?->lt(now('Asia/Muscat')->startOfDay());
    }

    public function balance(): string
    {
        return bcsub((string) $this->total, (string) $this->amount_paid, 3);
    }

    /** @return Collection<int, User> */
    public function customerRecipients(): Collection
    {
        return Memberships::recipients($this->customer_user_id, $this->organization_id);
    }
}
