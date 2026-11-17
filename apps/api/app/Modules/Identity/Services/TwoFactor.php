<?php

namespace App\Modules\Identity\Services;

use PragmaRX\Google2FA\Google2FA;

/** التحقق الثنائي TOTP للوحة الإدارة (FR-AUTH-03، NFR-08) */
final class TwoFactor
{
    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(string $email, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl('DWAHI-ALBADIA', $email, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        // نافذة ±30 ثانية لفروق ساعة الجوال
        return (bool) $this->google2fa->verifyKey($secret, $code, 1);
    }
}
