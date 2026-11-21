<?php

namespace App\Modules\Content\Models;

use App\Models\User;
use App\Modules\Platform\Models\Branch;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** استفسار زائر (FR-CMS-05، US-05). يتابعه الموظف من A-15. */
class Lead extends Model
{
    public const STATUSES = ['new', 'contacted', 'qualified', 'closed'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->orderByDesc('created_at');
    }
}
