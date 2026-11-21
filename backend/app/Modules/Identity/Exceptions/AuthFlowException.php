<?php

namespace App\Modules\Identity\Exceptions;

use App\Support\ApiProblem;

/** أخطاء المصادقة المتوقعة، مثل OTP_LOCKED أو REFRESH_REUSED (04 §4.2) */
class AuthFlowException extends ApiProblem {}
