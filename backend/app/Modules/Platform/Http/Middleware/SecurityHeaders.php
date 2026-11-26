<?php

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ترويسات الأمان لكل ردود الـ API (NFR-06، OWASP ASVS V14.4): لا تخمين لنوع المحتوى، ولا تضمين في إطار، ولا مُحيل،
 * ولا تخزين مؤقت للردود الخاصة ما لم يحدد الرد غير ذلك.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        // وجود رمز يكفي للحكم بأن الرد خاص، دون استدعاء الحارس (له أثر جانبي: يحفظ المستخدم بين الطلبات)
        if ($request->bearerToken() && ! $h->hasCacheControlDirective('public')) {
            $h->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
