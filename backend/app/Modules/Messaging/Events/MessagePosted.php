<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Models\Message;
use App\Modules\Quoting\Models\Rfq;
use Illuminate\Foundation\Events\Dispatchable;

/** رسالة جديدة في محادثة طلب (04 §3) ← N-17 للطرف الآخر */
final class MessagePosted
{
    use Dispatchable;

    public function __construct(public Message $message, public Rfq $rfq) {}
}
