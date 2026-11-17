<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** تجديد الجلسة والخروج والأجهزة (FR-AUTH-04، US-07) */
class SessionController
{
    public function __construct(private readonly TokenService $tokens) {}

    /** POST /auth/token/refresh */
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'max:200']]);

        return response()->json(['data' => $this->tokens->refresh($data['refresh_token'], $request)]);
    }

    /** POST /auth/logout: الجلسة الحالية */
    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revoke($request->attributes->get('auth_session'), 'logout');

        return response()->json(null, 204);
    }

    /** POST /auth/logout-all: كل الأجهزة */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->tokens->revokeAll($request->user(), 'logout_all');

        return response()->json(null, 204);
    }

    /** GET /me/sessions */
    public function index(Request $request): JsonResponse
    {
        $current = $request->attributes->get('auth_session');
        $sessions = AuthSession::query()->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now())
            ->with('device')->orderByDesc('last_used_at')->get();

        return response()->json(['data' => $sessions->map(fn (AuthSession $s) => [
            'id' => $s->id,
            'client' => $s->client,
            'platform' => $s->device?->platform,
            'user_agent' => $s->user_agent,
            'ip' => $s->ip,
            'last_used_at' => $s->last_used_at?->toIso8601ZuluString(),
            'created_at' => $s->created_at?->toIso8601ZuluString(),
            'current' => $s->id === $current->id,
        ])->values()]);
    }

    /** DELETE /me/sessions/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $session = AuthSession::query()->where('user_id', $request->user()->id)->whereNull('revoked_at')->findOrFail($id);
        $this->tokens->revoke($session, 'user_revoked');

        return response()->json(null, 204);
    }
}
