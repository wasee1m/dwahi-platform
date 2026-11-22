<?php

namespace App\Modules\Accounts\Models;

use App\Models\User;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ملاحظة داخلية على عميل أو شركة (FR-ADM-05). لا تظهر للعميل ولا في نسخة بياناته. */
class CustomerNote extends Model
{
    public const UPDATED_AT = null;

    protected $casts = ['created_at' => 'datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
