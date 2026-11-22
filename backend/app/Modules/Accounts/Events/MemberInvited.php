<?php

namespace App\Modules\Accounts\Events;

use App\Models\User;
use App\Modules\Accounts\Models\Invitation;
use Illuminate\Foundation\Events\Dispatchable;

/** دعوة للانضمام لحساب شركة (N-19) */
class MemberInvited
{
    use Dispatchable;

    public function __construct(public Invitation $invitation, public User $inviter) {}
}
