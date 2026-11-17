<?php

namespace App\Modules\Identity\Console;

use App\Models\User;
use App\Modules\Identity\Models\StaffProfile;
use App\Modules\Identity\Notifications\SetPasswordLink;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * أول مدير للنظام عند التشغيل لأول مرة. لا كلمة مرور في الأمر: يصل المدير بريد تفعيل،
 * فيعيّن كلمة المرور ثم يُعدّ التحقق الثنائي (US-39). باقي الموظفين يُنشؤون من A-17.
 */
class CreateAdmin extends Command
{
    protected $signature = 'staff:create-admin {email} {name} {--branch=SOH} {--employee-no=ADM-001}';

    protected $description = 'Create the first system admin and email them an activation link';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (User::query()->where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }
        $branchId = Branch::query()->where('code', $this->option('branch'))->value('id');
        if (! $branchId) {
            $this->error('Unknown branch code.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($email, $branchId) {
            $user = User::query()->create([
                'user_type' => 'staff', 'full_name' => (string) $this->argument('name'),
                'email' => $email, 'preferred_locale' => 'ar', 'status' => 'active',
            ]);
            StaffProfile::query()->create(['user_id' => $user->id, 'employee_no' => $this->option('employee-no'), 'branch_id' => $branchId]);
            $user->syncRoles(['admin']);

            return $user;
        });
        Audit::record('staff.created', 'user', $user->id, null, ['email' => $email, 'roles' => ['admin'], 'via' => 'console']);
        $user->notify(new SetPasswordLink(Password::broker()->createToken($user), true, true));

        $this->info("Admin created. An activation email was sent to {$email}.");

        return self::SUCCESS;
    }
}
