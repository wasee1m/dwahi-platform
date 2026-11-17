<?php

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept-Language: ar أو en (04 §4.1). أي قيمة أخرى تعني العربية.
 */
class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $lang = strtolower(substr((string) $request->header('Accept-Language', 'ar'), 0, 2));
        app()->setLocale($lang === 'en' ? 'en' : 'ar');

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
