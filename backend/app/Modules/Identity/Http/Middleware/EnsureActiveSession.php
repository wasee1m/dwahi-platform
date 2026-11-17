<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Exceptions\AuthFlowException;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بعد auth:sanctum: الجلسة التي ينتمي لها الرمز يجب أن تكون نشطة، والحساب نشطاً،
 * وجلسة الإدارة تنتهي بعد 30 دقيقة خمول (NFR-08). الاستخدام يحدّث last_used_at مرة في الدقيقة.
 * المعامل الاختياري يحدد القنوات المسموحة، مثل ensure.session:admin
 */
class EnsureActiveSession
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(Request $request, Closure $next, string ...$clients): Response
    {
        $user = $request->user();
        $sessionId = $user?->currentAccessToken()?->session_id;
        $session = $sessionId ? AuthSession::query()->find($sessionId) : null;

        if (! $session || ! $session->isActive()) {
            throw new AuthFlowException('SESSION_REVOKED', 401);
        }
        if ($user->status !== 'active') {
            $this->tokens->revoke($session, 'account_'.$user->status);
            throw new AuthFlowException('ACCOUNT_SUSPENDED', 403);
        }
        if ($session->client === 'admin' && $session->last_used_at?->lt(now()->subSeconds(TokenService::ADMIN_IDLE))) {
            $this->tokens->revoke($session, 'idle');
            throw new AuthFlowException('SESSION_IDLE', 401);
        }
        if ($clients !== [] && ! in_array($session->client, $clients, true)) {
            throw new AuthFlowException('WRONG_CLIENT', 403);
        }
        if (! $session->last_used_at || $session->last_used_at->lt(now()->subMinute())) {
            $session->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('auth_session', $session);

        return $next($request);
    }
}
