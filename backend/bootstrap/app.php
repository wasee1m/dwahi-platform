<?php

use App\Modules\Identity\Console\CreateAdmin;
use App\Modules\Identity\Console\ResetTwoFactor;
use App\Modules\Identity\Http\Middleware\EnsureActiveSession;
use App\Modules\Platform\Http\ApiErrorRenderer;
use App\Modules\Platform\Http\Middleware\AssignTraceId;
use App\Modules\Platform\Http\Middleware\SetLocaleFromHeader;
use App\Modules\Quoting\Console\QuotationJobs;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateAdmin::class,
        ResetTwoFactor::class,
        QuotationJobs::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignTraceId::class, SetLocaleFromHeader::class]);
        // خادم Next.js (والموازن في الإنتاج) يمرر عنوان المستخدم الحقيقي. الثقة بعناوين محددة فقط، لا "*" في الإنتاج
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', ''))));
        $middleware->alias(['ensure.session' => EnsureActiveSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiErrorRenderer::render($e);
            }
        });
    })->create();
