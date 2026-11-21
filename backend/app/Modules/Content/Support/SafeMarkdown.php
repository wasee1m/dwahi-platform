<?php

namespace App\Modules\Content\Support;

/**
 * تنقية المحتوى المُدار قبل الحفظ (US-42، 04 §10): لا HTML خام ولا روابط javascript: أو data:.
 * العارض في الويب يبني عناصر React من Markdown محدود، وهذه طبقة الدفاع الأولى.
 */
final class SafeMarkdown
{
    public static function clean(?string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        // كتل السكربت والأنماط بمحتواها، ثم بقية الوسوم. "a < b" يبقى لأن strip_tags لا يعدّ "< " وسماً.
        $text = (string) preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is', '', $text);
        $text = strip_tags($text);
        $text = (string) preg_replace('/\b(?:javascript|vbscript|data)\s*:/i', '', $text);

        return trim($text);
    }
}
