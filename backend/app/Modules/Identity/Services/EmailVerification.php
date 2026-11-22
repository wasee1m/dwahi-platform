<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Exceptions\AuthFlowException;
use App\Modules\Identity\Notifications\EmailCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * توثيق بريد العميل برمز (C-02). استعادة كلمة المرور (FR-AUTH-04) لا تُرسل إلا لبريد موثق.
 * الرمز مرتبط بالبريد الذي أُرسل له، فتغييره يبطله.
 */
final class EmailVerification
{
    private const TTL = 900;

    private const MAX = 5;

    /** @return string الرمز (لا يُعاد للعميل إلا في التطوير) */
    public function request(User $user): string
    {
        if (! $user->email) {
            throw ValidationException::withMessages(['email' => [__('accounts.email_required')]]);
        }
        $limit = "email-code:send:{$user->id}";
        if (RateLimiter::tooManyAttempts($limit, self::MAX)) {
            throw new AuthFlowException('OTP_THROTTLED', 429, RateLimiter::availableIn($limit));
        }
        RateLimiter::hit($limit, self::TTL);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->key($user), $this->hash($user, $code), self::TTL);
        $user->notify(new EmailCode($code));

        return $code;
    }

    public function verify(User $user, string $code): void
    {
        $limit = "email-code:try:{$user->id}";
        if (RateLimiter::tooManyAttempts($limit, self::MAX)) {
            throw new AuthFlowException('OTP_LOCKED', 429, RateLimiter::availableIn($limit));
        }
        $expected = Cache::get($this->key($user));
        if (! $user->email || ! $expected || ! hash_equals($expected, $this->hash($user, $code))) {
            RateLimiter::hit($limit, self::TTL);
            throw ValidationException::withMessages(['code' => [__('accounts.email_code')]]);
        }
        Cache::forget($this->key($user));
        RateLimiter::clear($limit);
        $user->forceFill(['email_verified_at' => now()])->save();
    }

    private function key(User $user): string
    {
        return "email-code:{$user->id}";
    }

    private function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', strtolower((string) $user->email).'|'.$code, (string) config('app.key'));
    }
}
