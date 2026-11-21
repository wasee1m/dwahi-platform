<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Exceptions\AuthFlowException;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\Organization;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Sms\SmsSender;
use App\Modules\Identity\Support\Phone;
use App\Modules\Platform\Support\Audit;
use App\Support\Governorates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** الملف الشخصي (FR-ACC-01، US-06، US-08) */
class MeController
{
    public const GOVERNORATES = Governorates::ALL;

    public function show(Request $request): MeResource
    {
        return new MeResource($request->user());
    }

    /** PATCH /me */
    public function update(Request $request): MeResource
    {
        $user = $request->user();
        $data = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'preferred_locale' => ['sometimes', Rule::in(['ar', 'en'])],
        ]);
        if ($user->isStaff()) {
            unset($data['email']); // بريد الموظف يغيره المدير
        }
        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            $data['email_verified_at'] = null; // التوثيق برابط يأتي مع خدمة البريد
        }
        $user->forceFill($data)->save();

        return $this->show($request);
    }

    /** POST /me/onboarding: إكمال الملف بعد أول OTP (US-06، NFR-13) */
    public function onboarding(Request $request): MeResource
    {
        $user = $request->user();
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'account_type' => ['required', Rule::in(['individual', 'company'])],
            'company_name' => ['required_if:account_type,company', 'nullable', 'string', 'max:190'],
            'governorate' => ['required_if:account_type,company', 'nullable', Rule::in(self::GOVERNORATES)],
            'cr_number' => ['nullable', 'string', 'max:50', 'unique:organizations,cr_number'],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'marketing' => ['sometimes', 'boolean'],
        ]);
        abort_if($user->isStaff(), 403);

        DB::transaction(function () use ($user, $data, $request) {
            $user->forceFill(['full_name' => $data['full_name'], 'email' => $data['email'] ?? $user->email])->save();

            $versions = DB::table('pages')->whereIn('slug', ['terms', 'privacy'])->pluck('updated_at', 'slug');
            foreach (['terms' => true, 'privacy' => true, 'marketing' => (bool) ($data['marketing'] ?? false)] as $kind => $granted) {
                Consent::query()->create([
                    'user_id' => $user->id, 'kind' => $kind, 'granted' => $granted, 'ip' => $request->ip(),
                    'version' => isset($versions[$kind]) ? substr((string) $versions[$kind], 0, 19) : 'v1',
                ]);
            }

            if ($data['account_type'] === 'company' && $user->organizations()->doesntExist()) {
                $org = Organization::query()->create([
                    'name' => $data['company_name'], 'governorate' => $data['governorate'],
                    'cr_number' => $data['cr_number'] ?? null, 'vat_number' => $data['vat_number'] ?? null,
                ]);
                $org->members()->attach($user->id, ['role' => 'owner', 'can_approve_quotes' => true, 'joined_at' => now()]);
            }
        });

        return $this->show($request);
    }

    /** POST /me/password: كلمة مرور اختيارية للعميل (FR-AUTH-02) */
    public function setPassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'current_password' => ['nullable', 'string', 'current_password:sanctum'],
        ]);
        // تغيير كلمة مرور موجودة يتطلب الحالية
        abort_if($user->password_hash && ! $request->filled('current_password'), 422, 'current_password required');
        abort_if(! $user->email, 422, 'email required');
        $user->forceFill(['password_hash' => $request->input('password')])->save();
        Audit::record('auth.password_set', 'user', $user->id);

        return response()->json(null, 204);
    }

    /** POST /me/phone/request: رمز على الرقم الجديد (US-08) */
    public function requestPhoneChange(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $phone = $this->newPhone($request);
        $result = $otp->request($phone, 'change_phone', $request->ip(), app()->getLocale());

        return response()->json(['data' => array_filter([
            'status' => 'sent', 'expires_in' => OtpService::TTL_SECONDS,
            'debug_code' => app()->environment('local') && config('services.sms.driver') === 'log' ? $result['code'] : null,
        ])], 202);
    }

    /** POST /me/phone/verify: الحفظ بعد التحقق، وتنبيه للرقم القديم (N-30) */
    public function verifyPhoneChange(Request $request, OtpService $otp, SmsSender $sms): MeResource
    {
        $request->validate(['phone' => ['required', 'string', 'max:30'], 'code' => ['required', 'digits:6']]);
        $user = $request->user();
        $phone = $this->newPhone($request);
        $otp->verify($phone, 'change_phone', $request->input('code'));

        $old = $user->phone_e164;
        $user->forceFill(['phone_e164' => $phone, 'phone_verified_at' => now()])->save();
        Audit::record('user.phone_changed', 'user', $user->id, ['phone' => $old], ['phone' => $phone]);
        if ($old) {
            $sms->send($old, $user->preferred_locale === 'en'
                ? 'The mobile number on your DWAHI-ALBADIA account was changed. If this was not you, contact us.'
                : 'تغيّر رقم الجوال في حسابك لدى ضواحي البادية. إن لم تكن أنت، تواصل معنا.');
        }

        return $this->show($request);
    }

    private function newPhone(Request $request): string
    {
        $phone = Phone::normalize($request->input('phone'));
        if (! $phone) {
            throw new AuthFlowException('INVALID_PHONE', 422);
        }
        if (User::query()->where('phone_e164', $phone)->whereKeyNot($request->user()->id)->exists()) {
            throw new AuthFlowException('PHONE_TAKEN', 409);
        }

        return $phone;
    }
}
