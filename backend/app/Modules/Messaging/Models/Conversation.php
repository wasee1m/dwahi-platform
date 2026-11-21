<?php

namespace App\Modules\Messaging\Models;

use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** محادثة واحدة لكل طلب (عرض سعر الآن، والصيانة والقطع مع وحدتيهما) (FR-MSG-01) */
class Conversation extends Model
{
    protected $casts = ['last_message_at' => 'datetime'];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }
}
