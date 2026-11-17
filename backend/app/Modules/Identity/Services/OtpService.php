<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Exceptions\AuthFlowException;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Sms\SmsSender;
use App\Modules\Identity\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * رموز التحقق (FR-AUTH-05، US-06): 6 أرقام صالحة 5 دقائق، و 5 محاولات خاطئة تقفل الرقم 15 دقيقة،
 * و 3 رسائل لكل رقم كل 15 دقيقة، وحد لكل عنوان IP ولكل دولة (NFR-09).
 * الرد واحد سواء كان الرقم مسجلاً أم لا، فلا يُكشف التسجيل (US-07).
 */
final class OtpService
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 900;

    public const MAX_SENDS = 3;

    public const SEND_WINDOW_SECONDS = 900;

    public function __construct(private readonly SmsSender $sms) {}

    /** @return array{challenge: OtpChallenge, code: string} */
    public function request(string $phone, string $purpose, ?string $ip, string $locale): array
    {
        $this->assertNotLocked($phone, $purpose);

        $recent = OtpChallenge::query()->where('phone_e164', $phone)
            ->where('created_at', '>', now()->subSeconds(self::SEND_WINDOW_SECONDS))
            ->orderBy('created_at')->get(['created_at']);
        if ($recent->count() >= self::MAX_SENDS) {
            $retry = (int) ceil(self::SEND_WINDOW_SECONDS - $recent->first()->created_at->diffInSeconds(now()));
            throw new AuthFlowException('OTP_THROTTLED', 429, max(1, $retry));
        }

        // حدود عامة لكل IP ولكل دولة غير عُمان (حماية رصيد الرسائل)
        $country = Phone::countryCode($phone);
        $limits = ['otp:ip:'.$ip => 20];
        if ($country !== '968') {
            $limits['otp:country:'.$country] = 50;
        }
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new AuthFlowException('OTP_THROTTLED', 429, RateLimiter::availableIn($key));
            }
        }
        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 3600);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $challenge = OtpChallenge::query()->create([
            'phone_e164' => $phone,
            'purpose' => $purpose,
            'code_hash' => $this->hash($phone, $code),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
            'ip' => $ip,
        ]);

        $text = $locale === 'en'
            ? "Your DWAHI-ALBADIA code: {$code}. Valid for 5 minutes. Do not share it."
            : "رمز التحقق من ضواحي البادية: {$code}. صالح 5 دقائق. لا تشاركه مع أحد.";
        $this->sms->send($phone, $text); // N-21

        return ['challenge' => $challenge, 'code' => $code];
    }

    /** يستهلك الرمز الصحيح، أو يرفع خطأ برمز ثابت */
    public function verify(string $phone, string $purpose, string $code): void
    {
        $this->assertNotLocked($phone, $purpose);

        // النتيجة تُحسب وتُلتزم داخل المعاملة، والخطأ يُرفع بعدها، وإلا أُلغيت زيادة عدد المحاولات مع الخطأ
        $outcome = DB::transaction(function () use ($phone, $purpose, $code) {
            $challenge = OtpChallenge::query()->where('phone_e164', $phone)->where('purpose', $purpose)
                ->whereNull('consumed_at')->orderByDesc('created_at')->lockForUpdate()->first();

            if (! $challenge || $challenge->expires_at->isPast()) {
                return ['expired'];
            }
            if (! hash_equals($challenge->code_hash, $this->hash($phone, $code))) {
                $challenge->increment('attempts');

                return ['invalid', self::MAX_ATTEMPTS - $challenge->attempts];
            }
            $challenge->update(['consumed_at' => now()]);

            return ['ok'];
        });

        match ($outcome[0]) {
            'ok' => null,
            'expired' => throw new AuthFlowException('OTP_EXPIRED', 422),
            'invalid' => throw ($outcome[1] > 0
                ? new AuthFlowException('OTP_INVALID', 422, null, ['attempts_left' => $outcome[1]])
                : new AuthFlowException('OTP_LOCKED', 429, self::LOCK_SECONDS)),
        };
    }

    private function assertNotLocked(string $phone, string $purpose): void
    {
        $locked = OtpChallenge::query()->where('phone_e164', $phone)->where('purpose', $purpose)
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->where('updated_at', '>', now()->subSeconds(self::LOCK_SECONDS))
            ->orderByDesc('updated_at')->first(['updated_at']);
        if ($locked) {
            $retry = (int) ceil(self::LOCK_SECONDS - $locked->updated_at->diffInSeconds(now()));
            throw new AuthFlowException('OTP_LOCKED', 429, max(1, $retry));
        }
    }

    private function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, (string) config('app.key'));
    }
}
