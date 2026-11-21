<?php

namespace App\Modules\Messaging\Models;

use App\Models\User;
use App\Modules\Platform\Models\Attachment;
use App\Support\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** رسالة. المعرّف يولده المرسل، فإعادة الإرسال بعد انقطاع لا تكررها. لا تعديل ولا حذف. */
class Message extends Model
{
    public const UPDATED_AT = null;

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(Attachment::class, 'message_attachments');
    }
}
