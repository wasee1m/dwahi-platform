<?php

namespace App\Support;

/**
 * المال في الـ API نص عشري + عملة (04 §4.1): {"amount": "1250.500", "currency": "OMR"}. لا أرقام عائمة.
 */
final class Money
{
    /** @return array{amount: string, currency: string}|null */
    public static function of(?string $amount, string $currency = 'OMR'): ?array
    {
        return $amount === null ? null : ['amount' => $amount, 'currency' => $currency];
    }
}
