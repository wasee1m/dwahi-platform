<?php

namespace App\Support;

/** أنماط البحث الحر في لوحة الإدارة */
final class Search
{
    /** "يحتوي النص" مع تعطيل رموز LIKE داخله */
    public static function contains(string $text): string
    {
        return '%'.addcslashes($text, '%_\\').'%';
    }

    /** نمط الجوال فقط حين يكون البحث رقماً. بحث الاسم لا يحوي أرقاماً، و "%%" كان يطابق كل جوال. */
    public static function phone(string $text): ?string
    {
        if (! preg_match('/^[\d\s+().-]+$/', trim($text))) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $text);

        return strlen($digits) >= 3 ? "%{$digits}%" : null;
    }
}
