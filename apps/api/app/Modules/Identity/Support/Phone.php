<?php

namespace App\Modules\Identity\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * أرقام الجوال بصيغة E.164 (03 §3). الرقم المحلي العماني (8 أرقام يبدأ بـ 7 أو 9) يأخذ +968،
 * والأرقام الدولية مقبولة لكل الحسابات (قرار 2026-11-16).
 */
final class Phone
{
    public static function normalize(?string $input): ?string
    {
        $raw = trim((string) $input);
        if ($raw === '') {
            return null;
        }
        $digits = (string) preg_replace('/[^\d+]/', '', $raw);
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($digits, 'OM');
        } catch (NumberParseException) {
            return null;
        }
        if (! $util->isValidNumber($number)) {
            return null;
        }
        $type = $util->getNumberType($number);
        if (! in_array($type, [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
            return null;
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }

    /** رمز الدولة للحد من الرسائل لكل دولة (NFR-09)، مثل "968" */
    public static function countryCode(string $e164): string
    {
        return (string) PhoneNumberUtil::getInstance()->parse($e164)->getCountryCode();
    }
}
