<?php

namespace App\Modules\Identity\Models;

use App\Modules\Platform\Models\Branch;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    // المفتاح UUID نصي (user_id)، لا رقم متزايد. بدونهما يحوّله Eloquent لرقم فيفشل التحميل المسبق (with)
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'restrict_to_branch' => 'boolean',
        'skills' => 'array',
        'totp_secret_enc' => 'encrypted',
        'totp_enabled_at' => 'datetime',
    ];

    protected $hidden = ['totp_secret_enc'];

    public function uniqueIds(): array
    {
        return []; // المفتاح هو user_id
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
