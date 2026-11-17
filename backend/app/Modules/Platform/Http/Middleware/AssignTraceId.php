<?php

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * كل استجابة تحمل X-Trace-Id (04 §4.1)، ويظهر في السجلات وفي جسم الخطأ (NFR-20).
 */
class AssignTraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Trace-Id', '');
        $traceId = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid7();
        Context::add('trace_id', $traceId);

        $response = $next($request);
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}
