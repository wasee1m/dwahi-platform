<?php

namespace App\Support;

use RuntimeException;

/**
 * خطأ متوقع برمز ثابت لكل الوحدات (04 §4.2)، مثل INVALID_TRANSITION. ApiErrorRenderer يحوّله للصيغة الموحدة.
 * retryAfter بالثواني يظهر في details ليعرض العميل عداداً.
 */
class ApiProblem extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        public readonly array $details = [],
    ) {
        parent::__construct($errorCode);
    }
}
