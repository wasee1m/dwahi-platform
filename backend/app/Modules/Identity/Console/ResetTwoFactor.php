<?php

namespace App\Modules\Identity\Console;

use App\Models\User;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Platform\Support\Audit;
use Illuminate\Console\Command;

/**
 * مخرج طوارئ من الخادم: مدير وحيد فقد جواله لا يستطيع إعادة ضبط TOTP لنفسه من A-17.
 * الرموز الاحتياطية (A-21) مؤجلة، وهذا الأمر يغطي الحالة حتى تُبنى.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'staff:reset-2fa {email}';

    protected $description = 'Reset a staff member\'s two-factor authentication (break-glass)';

    public function handle(TokenService $tokens): int
    {
        $user = User::query()->where('email', $this->argument('email'))->where('user_type', 'staff')->first();
        if (! $user) {
            $this->error('No staff member with this email.');

            return self::FAILURE;
        }
        $user->staffProfile->forceFill(['totp_secret_enc' => null, 'totp_enabled_at' => null])->save();
        $tokens->revokeAll($user, 'two_factor_reset');
        Audit::record('staff.two_factor_reset', 'user', $user->id, null, ['via' => 'console']);
        $this->info('Two-factor authentication reset. It will be set up again at the next sign-in.');

        return self::SUCCESS;
    }
}
