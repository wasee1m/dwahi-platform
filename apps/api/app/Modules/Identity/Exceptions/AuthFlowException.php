<?php

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/**
 * أخطاء المصادقة المتوقعة برموز ثابتة (04 §4.2)، مثل OTP_LOCKED أو REFRESH_REUSED.
 * retryAfter بالثواني يظهر في details ليعرض العميل عداداً (US-06).
 */
class AuthFlowException extends RuntimeException
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
