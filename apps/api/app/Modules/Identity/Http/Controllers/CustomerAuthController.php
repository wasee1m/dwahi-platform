<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Exceptions\AuthFlowException;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Identity\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * دخول العميل وتسجيله (FR-AUTH-01، FR-AUTH-02، US-06، US-07).
 * مسار واحد بالجوال: الرقم الجديد يُنشأ له حساب بعد التحقق، ثم يكمل ملفه (needs_onboarding).
 */
class CustomerAuthController
{
    public function __construct(private readonly OtpService $otp, private readonly TokenService $tokens) {}

    /** POST /auth/otp/request */
    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $phone = $this->phone($request);
        $result = $this->otp->request($phone, 'login', $request->ip(), app()->getLocale());

        $body = ['status' => 'sent', 'expires_in' => OtpService::TTL_SECONDS];
        // للتطوير فقط مع مزود السجل: الرمز في الاستجابة بدل SMS حقيقي
        if (app()->environment('local') && config('services.sms.driver') === 'log') {
            $body['debug_code'] = $result['code'];
        }

        return response()->json(['data' => $body], 202);
    }

    /** POST /auth/otp/verify */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'digits:6'],
            'client' => ['required', Rule::in(['web', 'customer_app'])],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $phone = $this->phone($request);
        $this->otp->verify($phone, 'login', $data['code']);

        $user = User::query()->where('phone_e164', $phone)->first();
        if ($user && $user->isStaff()) {
            throw new AuthFlowException('INVALID_CREDENTIALS', 401); // الموظفون يدخلون من لوحة الإدارة
        }
        if ($user && $user->status !== 'active') {
            throw new AuthFlowException('ACCOUNT_SUSPENDED', 403);
        }
        $user ??= User::query()->create([
            'user_type' => 'customer', 'full_name' => '', 'phone_e164' => $phone,
            'preferred_locale' => app()->getLocale(), 'status' => 'active',
        ]);
        $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? now()])->save();

        return response()->json(['data' => [
            ...$this->tokens->issue($user, $data['client'], (bool) ($data['remember'] ?? false), $request),
            'user' => new MeResource($user->fresh()),
        ]]);
    }

    /** POST /auth/password/login: بريد + كلمة مرور إن أُنشئت (FR-AUTH-02) */
    public function passwordLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'client' => ['required', Rule::in(['web', 'customer_app'])],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $user = User::query()->where('email', $data['email'])->where('user_type', 'customer')->first();
        // نفس الخطأ والزمن تقريباً سواء وُجد الحساب أم لا
        if (! $user || ! $user->password_hash || ! Hash::check($data['password'], $user->password_hash)) {
            Hash::check($data['password'], '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$Zm9vYmFyYmF6');
            throw new AuthFlowException('INVALID_CREDENTIALS', 401);
        }
        if ($user->status !== 'active') {
            throw new AuthFlowException('ACCOUNT_SUSPENDED', 403);
        }

        return response()->json(['data' => [
            ...$this->tokens->issue($user, $data['client'], (bool) ($data['remember'] ?? false), $request),
            'user' => new MeResource($user),
        ]]);
    }

    private function phone(Request $request): string
    {
        $phone = Phone::normalize($request->input('phone'));
        if (! $phone) {
            throw new AuthFlowException('INVALID_PHONE', 422);
        }

        return $phone;
    }
}
