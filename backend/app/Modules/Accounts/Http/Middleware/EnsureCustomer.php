<?php

namespace App\Modules\Accounts\Http\Middleware;

use App\Models\User;
use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * مسارات بوابة العميل: للعملاء فقط، والكتابة بعد إكمال الملف وقبول الشروط (US-06، NFR-13).
 * المعامل any لحقوق البيانات: لا تنتظر إكمال الملف.
 */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next, string $profile = 'complete'): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->isStaff(), 403); // الموظف يعمل من لوحة الإدارة
        if ($profile !== 'any' && ! $request->isMethodSafe() && $user->needsOnboarding()) {
            throw new ApiProblem('ONBOARDING_REQUIRED', 403);
        }

        return $next($request);
    }
}
