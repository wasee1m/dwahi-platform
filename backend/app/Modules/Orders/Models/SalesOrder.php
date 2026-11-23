<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Accounts\Support\BelongsToAccount;
use App\Modules\Accounts\Support\Memberships;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Identity\Models\Organization;
use App\Modules\Platform\Models\Branch;
use App\Modules\Quoting\Models\Rfq;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/** طلب القطع (SM-3a، FR-ORD-02) */
class SalesOrder extends Model
{
    use BelongsToAccount;

    public const STATUSES = ['pending_payment', 'confirmed', 'processing', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'cancelled'];

    /** مفتوح: لم يُسلَّم ولم يُلغَ (حد BR-05، وشرط حذف الحساب) */
    public const OPEN = ['pending_payment', 'confirmed', 'processing', 'ready_for_pickup', 'out_for_delivery'];

    protected $casts = [
        'delivery_address' => 'array',
        'needs_phone_confirmation' => 'boolean',
        'phone_confirmed_at' => 'datetime',
        'pickup_deadline_at' => 'datetime',
        'pickup_reminded_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'delivered_at' => 'datetime',
        'pickup_code_enc' => 'encrypted',
    ];

    protected $hidden = ['pickup_code_enc'];

    public function personalColumn(): string
    {
        return 'customer_user_id';
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'order_id')->orderBy('line_no');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SalesOrderEvent::class, 'order_id')->orderBy('created_at');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ReturnRequest::class, 'order_id')->orderByDesc('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'sales_order_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'sales_order_id');
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

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** المدفوع حتى الآن من الدفعات الناجحة (الدفع عند التسليم يسبق الفاتورة في نفس المعاملة) */
    public function amountPaid(): string
    {
        return (string) $this->payments()->whereIn('status', ['succeeded', 'partially_refunded', 'refunded'])->sum('amount');
    }

    /** @return Collection<int, User> */
    public function customerRecipients(): Collection
    {
        return Memberships::recipients($this->customer_user_id, $this->organization_id);
    }
}
