<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Exceptions\AuthFlowException;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * الجلسات والرموز (04 §5):
 * - رمز وصول Sanctum قصير (15 دقيقة) ينتمي لجلسة.
 * - رمز تحديث "<session_id>.<سر>" يُخزَّن مجزّأً ويُدوَّر عند كل استخدام.
 * - استخدام رمز قديم خارج مهلة 30 ثانية = سرقة محتملة، فتُلغى كل جلسات المستخدم.
 * - مدد الجلسات حسب القناة، والإدارة بمدة مطلقة 12 ساعة وخمول 30 دقيقة (NFR-08).
 */
final class TokenService
{
    public const ACCESS_TTL = 900;

    public const ADMIN_IDLE = 1800;

    private const GRACE = 30;

    /** @return int ثوانٍ */
    public static function ttl(string $client, bool $remember): int
    {
        return match ($client) {
            'web' => $remember ? 60 * 86400 : 86400,
            'customer_app' => 60 * 86400,
            'technician_app' => 14 * 86400,
            'admin' => 12 * 3600,
        };
    }

    /** @return array<string, mixed> */
    public function issue(User $user, string $client, bool $remember, Request $request, ?Device $device = null): array
    {
        $id = (string) Str::uuid7();
        $refresh = $id.'.'.Str::random(48);

        $session = AuthSession::query()->create([
            'id' => $id,
            'user_id' => $user->id,
            'device_id' => $device?->id,
            'client' => $client,
            'remember' => $client === 'web' && $remember,
            'refresh_token_hash' => hash('sha256', $refresh),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'last_used_at' => now(),
            'expires_at' => now()->addSeconds(self::ttl($client, $remember)),
        ]);
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->payload($user, $session, $refresh);
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken, Request $request): array
    {
        $sessionId = Str::before($refreshToken, '.');
        $hash = hash('sha256', $refreshToken);

        // النتيجة تُحسب داخل المعاملة، والإلغاء والخطأ بعدها (وإلا أُلغي الإلغاء مع الخطأ)
        [$outcome, $session, $newToken] = DB::transaction(function () use ($sessionId, $hash) {
            $session = Str::isUuid($sessionId) ? AuthSession::query()->lockForUpdate()->find($sessionId) : null;
            if (! $session || ! $session->isActive()) {
                return ['invalid', $session, null];
            }
            if ($session->client === 'admin' && $session->last_used_at?->lt(now()->subSeconds(self::ADMIN_IDLE))) {
                return ['idle', $session, null];
            }
            if (hash_equals($session->refresh_token_hash, $hash)) {
                $newToken = $session->id.'.'.Str::random(48);
                $session->update([
                    'previous_refresh_token_hash' => $session->refresh_token_hash,
                    'refresh_token_hash' => hash('sha256', $newToken),
                    'rotated_at' => now(),
                    'last_used_at' => now(),
                    // الإدارة مدة مطلقة، والباقي تمتد مع الاستخدام
                    'expires_at' => $session->client === 'admin'
                        ? $session->expires_at
                        : now()->addSeconds(self::ttl($session->client, $session->remember)),
                ]);

                return ['ok', $session, $newToken];
            }
            if ($session->previous_refresh_token_hash !== null && hash_equals($session->previous_refresh_token_hash, $hash)
                && $session->rotated_at?->gt(now()->subSeconds(self::GRACE))) {
                return ['in_progress', $session, null];
            }

            return ['reused', $session, null];
        });

        return match ($outcome) {
            'ok' => $this->afterRotate($session, $newToken),
            'invalid' => throw new AuthFlowException('REFRESH_INVALID', 401),
            'idle' => $this->fail($session, 'idle', 'SESSION_IDLE'),
            'in_progress' => throw new AuthFlowException('REFRESH_IN_PROGRESS', 409),
            'reused' => $this->reuse($session),
        };
    }

    public function revoke(AuthSession $session, string $reason): void
    {
        $session->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
        PersonalAccessToken::query()->where('session_id', $session->id)->delete();
    }

    public function revokeAll(User $user, string $reason, ?string $exceptSessionId = null): int
    {
        $sessions = AuthSession::query()->where('user_id', $user->id)->whereNull('revoked_at')
            ->when($exceptSessionId, fn ($q, $id) => $q->whereKeyNot($id))->get();
        foreach ($sessions as $s) {
            $this->revoke($s, $reason);
        }

        return $sessions->count();
    }

    /** @return array<string, mixed> */
    private function afterRotate(AuthSession $session, string $refresh): array
    {
        $user = $session->user;
        if ($user->status !== 'active') {
            $this->fail($session, 'account_'.$user->status, 'ACCOUNT_SUSPENDED', 403);
        }
        PersonalAccessToken::query()->where('session_id', $session->id)->delete();

        return $this->payload($user, $session, $refresh);
    }

    private function reuse(AuthSession $session): never
    {
        $this->revokeAll($session->user, 'reuse_detected');
        throw new AuthFlowException('REFRESH_REUSED', 401);
    }

    private function fail(AuthSession $session, string $reason, string $code, int $status = 401): never
    {
        $this->revoke($session, $reason);
        throw new AuthFlowException($code, $status);
    }

    /** @return array<string, mixed> */
    private function payload(User $user, AuthSession $session, string $refresh): array
    {
        $expires = now()->addSeconds(self::ACCESS_TTL);
        $token = $user->createToken($session->client, ['*'], $expires);
        $token->accessToken->forceFill(['session_id' => $session->id])->save();

        return [
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'access_expires_at' => $expires->toIso8601ZuluString(),
            'refresh_token' => $refresh,
            'refresh_expires_at' => $session->expires_at->toIso8601ZuluString(),
            'session_id' => $session->id,
        ];
    }
}
