<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Notifications\SetPasswordLink;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Platform\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * استعادة كلمة المرور بالبريد (FR-AUTH-04)، وتفعيل حساب الموظف الجديد بنفس الآلية (US-39).
 */
class PasswordController
{
    public function __construct(private readonly TokenService $tokens) {}

    /** POST /auth/password/forgot: الرد نفسه دائماً، فلا يُكشف وجود البريد */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::query()->where('email', $data['email'])->where('status', 'active')->first();
        if ($user) {
            $user->notify(new SetPasswordLink(Password::broker()->createToken($user), $user->isStaff(), false));
        }

        return response()->json(['data' => ['status' => 'sent']], 202);
    }

    /** POST /auth/password/reset */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Password::broker()->tokenExists($user, $data['token'])) {
            throw ValidationException::withMessages(['token' => __('passwords.token')]);
        }

        $user->forceFill(['password_hash' => $data['password']])->save();
        Password::broker()->deleteToken($user);
        $this->tokens->revokeAll($user, 'password_reset');
        Audit::record('auth.password_set', 'user', $user->id, actor: $user);

        return response()->json(['data' => ['status' => 'reset']]);
    }
}
