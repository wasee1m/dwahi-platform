<?php

namespace App\Support;

/**
 * مؤشر ترقيم معتم (04 §4.1: ?cursor=... ← next_cursor). داخلياً إزاحة، لأن الكتالوج يُرتب بالسعر أيضاً
 * وفيه قيم فارغة لا يدعمها ترقيم Laravel بالمؤشر. العميل لا يبني المؤشر بنفسه، فيمكن تغيير التنفيذ لاحقاً.
 */
final class OffsetCursor
{
    public static function encode(int $offset): string
    {
        return rtrim(strtr(base64_encode("o:{$offset}"), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 0;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        return ($raw !== false && preg_match('/^o:(\d{1,7})$/', $raw, $m)) ? (int) $m[1] : 0;
    }
}
