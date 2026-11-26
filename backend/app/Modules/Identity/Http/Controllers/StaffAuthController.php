<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Exceptions\AuthFlowException;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\DeviceActivationCode;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Identity\Services\TwoFactor;
use App\Modules\Identity\Support\Phone;
use App\Modules\Platform\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * دخول الموظفين (FR-AUTH-03، NFR-08، US-39):
 * - لوحة الإدارة: بريد + كلمة مرور ← رمز TOTP (يُلزم بإعداده عند أول دخول).
 * - تطبيق الفني: بريد أو جوال + كلمة مرور + جهاز مسجل. الجهاز الجديد يُربط برمز تفعيل لمرة واحدة.
 */
class StaffAuthController
{
    private const MFA_TTL = 300;

    public function __construct(private readonly TokenService $tokens, private readonly TwoFactor $twoFactor) {}

    /** POST /auth/staff/login */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = $this->checkCredentials(User::query()->where('email', $data['email']), $data['password']);
        $profile = $user->staffProfile;

        // التطوير المحلي فقط: دخول بكلمة المرور دون الخطوة الثانية، ويُسجَّل في التدقيق
        if (! config('auth.staff_2fa_required') && ! app()->isProduction()) {
            Audit::record('auth.two_factor_skipped', 'user', $user->id, null, ['reason' => 'STAFF_2FA_REQUIRED=false'], $user);

            return response()->json(['data' => [
                ...$this->tokens->issue($user, 'admin', false, $request),
                'user' => new MeResource($user),
                'two_factor_skipped' => true,
            ]]);
        }

        $mfaToken = Str::random(64);
        $setup = $profile->totp_enabled_at === null;
        $secret = $setup ? $this->twoFactor->newSecret() : null;
        Cache::put("mfa:{$mfaToken}", ['user_id' => $user->id, 'secret' => $secret, 'attempts' => 0], self::MFA_TTL);

        return response()->json(['data' => array_filter([
            'mfa_token' => $mfaToken,
            'setup_required' => $setup,
            'secret' => $secret,
            'otpauth_url' => $secret ? $this->twoFactor->otpauthUrl($user->email, $secret) : null,
            'expires_in' => self::MFA_TTL,
        ], fn ($v) => $v !== null)]);
    }

    /** POST /auth/staff/2fa */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['mfa_token' => ['required', 'string', 'size:64'], 'code' => ['required', 'digits:6']]);
        $key = "mfa:{$data['mfa_token']}";
        $pending = Cache::get($key);
        if (! $pending) {
            throw new AuthFlowException('MFA_EXPIRED', 401);
        }

        $user = User::query()->findOrFail($pending['user_id']);
        $profile = $user->staffProfile;
        $secret = $pending['secret'] ?? $profile->totp_secret_enc;

        if (! $secret || ! $this->twoFactor->verify($secret, $data['code'])) {
            $pending['attempts']++;
            $pending['attempts'] >= 5 ? Cache::forget($key) : Cache::put($key, $pending, self::MFA_TTL);
            throw new AuthFlowException('TWO_FACTOR_INVALID', 422);
        }
        Cache::forget($key);

        if ($pending['secret']) {
            $profile->forceFill(['totp_secret_enc' => $secret, 'totp_enabled_at' => now()])->save();
            Audit::record('auth.two_factor_enabled', 'user', $user->id, actor: $user);
        }

        return response()->json(['data' => [
            ...$this->tokens->issue($user, 'admin', false, $request),
            'user' => new MeResource($user),
        ]]);
    }

    /** POST /auth/technician/login */
    public function technicianLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'device_id' => ['nullable', 'uuid'],
            'activation_code' => ['nullable', 'string', 'max:20'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'app_version' => ['nullable', 'string', 'max:30'],
        ]);
        $phone = Phone::normalize($data['login']);
        $query = User::query()->where(fn ($q) => $q->where('email', $data['login'])->when($phone, fn ($q2) => $q2->orWhere('phone_e164', $phone)));
        $user = $this->checkCredentials($query, $data['password']);
        if (! in_array('technician', $user->roleNames(), true)) {
            throw new AuthFlowException('INVALID_CREDENTIALS', 401);
        }

        $device = ! empty($data['activation_code'])
            ? $this->activateDevice($user, $data)
            : Device::query()->where('id', $data['device_id'] ?? null)->where('user_id', $user->id)
                ->where('app', 'technician')->where('status', 'active')->first();
        if (! $device) {
            throw new AuthFlowException('DEVICE_NOT_REGISTERED', 403);
        }
        $device->forceFill(['last_seen_at' => now(), 'app_version' => $data['app_version'] ?? $device->app_version])->save();

        return response()->json(['data' => [
            ...$this->tokens->issue($user, 'technician_app', false, $request, $device),
            'device_id' => $device->id,
            'user' => new MeResource($user),
        ]]);
    }

    /** @param array<string, mixed> $data */
    private function activateDevice(User $user, array $data): Device
    {
        $hash = hash('sha256', strtoupper(trim($data['activation_code'])));

        $device = DB::transaction(function () use ($user, $data, $hash) {
            $code = DeviceActivationCode::query()->where('code_hash', $hash)->where('user_id', $user->id)
                ->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $code) {
                return null;
            }
            $device = Device::query()->create([
                'user_id' => $user->id, 'app' => 'technician', 'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? null, 'status' => 'active',
            ]);
            $code->update(['consumed_at' => now(), 'device_id' => $device->id]);

            return $device;
        });
        if (! $device) {
            throw new AuthFlowException('ACTIVATION_CODE_INVALID', 422);
        }
        Audit::record('device.activated', 'device', $device->id, null, ['user_id' => $user->id, 'platform' => $device->platform], $user);

        return $device;
    }

    private function checkCredentials($query, string $password): User
    {
        $user = $query->where('user_type', 'staff')->first();
        if (! $user || ! $user->password_hash || ! Hash::check($password, $user->password_hash)) {
            Hash::check($password, '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$Zm9vYmFyYmF6'); // زمن متقارب
            throw new AuthFlowException('INVALID_CREDENTIALS', 401);
        }
        if ($user->status !== 'active') {
            throw new AuthFlowException('ACCOUNT_SUSPENDED', 403);
        }

        return $user;
    }
}
