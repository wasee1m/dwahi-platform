<?php

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Identity\Authorization\Permissions;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\DeviceActivationCode;
use App\Modules\Identity\Models\StaffProfile;
use App\Modules\Identity\Notifications\SetPasswordLink;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Identity\Support\Phone;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** الموظفون والأدوار والأجهزة (FR-ADM-01، US-39، A-17، A-11) */
class StaffController
{
    public function __construct(private readonly TokenService $tokens) {}

    /** GET /admin/staff */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $staff = User::query()->where('user_type', 'staff')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('full_name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->with('staffProfile.branch')->orderBy('full_name')->limit(200)->get();

        return response()->json(['data' => $staff->map(fn (User $u) => $this->present($u))->values()]);
    }

    /** POST /admin/staff: إنشاء موظف وإرسال بريد التفعيل */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'user_type' => 'staff', 'full_name' => $data['full_name'], 'email' => $data['email'],
                'phone_e164' => $data['phone'] ?? null, 'preferred_locale' => $data['preferred_locale'] ?? 'ar', 'status' => 'active',
            ]);
            StaffProfile::query()->create([
                'user_id' => $user->id, 'employee_no' => $data['employee_no'],
                'branch_id' => Branch::query()->where('code', $data['branch_code'])->value('id'),
                'restrict_to_branch' => (bool) ($data['restrict_to_branch'] ?? false),
            ]);
            $user->syncRoles($data['roles']);

            return $user;
        });

        Audit::record('staff.created', 'user', $user->id, null, ['email' => $user->email, 'roles' => $data['roles'], 'branch' => $data['branch_code']]);
        $user->notify(new SetPasswordLink(Password::broker()->createToken($user), true, true));

        return response()->json(['data' => $this->present($user->fresh('staffProfile.branch'))], 201);
    }

    /** PATCH /admin/staff/{id}: الأدوار والفرع والبيانات */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->staff($id);
        $data = $this->validated($request, $user);
        $before = $this->present($user);

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(array_filter([
                'full_name' => $data['full_name'] ?? null, 'email' => $data['email'] ?? null,
                'phone_e164' => array_key_exists('phone', $data) ? $data['phone'] : $user->phone_e164,
            ], fn ($v) => $v !== null))->save();
            $profile = $user->staffProfile;
            if (isset($data['branch_code'])) {
                $profile->branch_id = Branch::query()->where('code', $data['branch_code'])->value('id');
            }
            foreach (['employee_no', 'restrict_to_branch'] as $k) {
                if (array_key_exists($k, $data)) {
                    $profile->{$k} = $data[$k];
                }
            }
            $profile->save();
            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }
        });

        $after = $this->present($user->fresh('staffProfile.branch'));
        Audit::record('staff.updated', 'user', $user->id, $before, $after);

        return response()->json(['data' => $after]);
    }

    /** POST /admin/staff/{id}/suspend: سبب إلزامي، وتنتهي كل جلساته فوراً (SM-7، US-39) */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $user = $this->staff($id);
        abort_if($user->id === $request->user()->id, 422, 'cannot suspend yourself');

        $user->forceFill(['status' => 'suspended', 'suspended_reason' => $data['reason']])->save();
        $ended = $this->tokens->revokeAll($user, 'account_suspended');
        Audit::record('staff.suspended', 'user', $user->id, ['status' => 'active'], ['status' => 'suspended', 'reason' => $data['reason'], 'sessions_ended' => $ended]);

        return response()->json(['data' => $this->present($user)]);
    }

    /** POST /admin/staff/{id}/activate */
    public function activate(string $id): JsonResponse
    {
        $user = $this->staff($id);
        $user->forceFill(['status' => 'active', 'suspended_reason' => null])->save();
        Audit::record('staff.activated', 'user', $user->id, ['status' => 'suspended'], ['status' => 'active']);

        return response()->json(['data' => $this->present($user)]);
    }

    /** POST /admin/staff/{id}/reset-2fa: يُعيد إعداد TOTP عند الدخول التالي */
    public function resetTwoFactor(string $id): JsonResponse
    {
        $user = $this->staff($id);
        $user->staffProfile->forceFill(['totp_secret_enc' => null, 'totp_enabled_at' => null])->save();
        $this->tokens->revokeAll($user, 'two_factor_reset');
        Audit::record('staff.two_factor_reset', 'user', $user->id);

        return response()->json(['data' => $this->present($user)]);
    }

    /** POST /admin/technicians/{id}/activation-code: رمز لمرة واحدة صالح 24 ساعة لجهاز واحد (FR-AUTH-03) */
    public function issueActivationCode(Request $request, string $id): JsonResponse
    {
        $user = $this->staff($id);
        abort_unless(in_array('technician', $user->roleNames(), true), 422, 'not a technician');

        // 8 أحرف بلا أحرف ملتبسة (0/O، 1/I) لسهولة الكتابة على الجوال
        $code = collect(range(1, 8))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])->implode('');
        DeviceActivationCode::query()->where('user_id', $user->id)->whereNull('consumed_at')->update(['expires_at' => now()]);
        $activation = DeviceActivationCode::query()->create([
            'user_id' => $user->id, 'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addDay(), 'issued_by' => $request->user()->id,
        ]);
        Audit::record('device.activation_code_issued', 'user', $user->id, null, ['activation_id' => $activation->id]);

        return response()->json(['data' => ['code' => $code, 'expires_at' => $activation->expires_at->toIso8601ZuluString()]], 201);
    }

    /** DELETE /admin/devices/{id}: إلغاء جهاز فني، فلا يزامن وتُمسح بياناته عند أول اتصال (SM-7) */
    public function revokeDevice(string $id): JsonResponse
    {
        $device = Device::query()->where('app', 'technician')->findOrFail($id);
        $device->forceFill(['status' => 'revoked'])->save();
        foreach (AuthSession::query()->where('device_id', $device->id)->whereNull('revoked_at')->get() as $s) {
            $this->tokens->revoke($s, 'device_revoked');
        }
        Audit::record('device.revoked', 'device', $device->id, ['status' => 'active'], ['status' => 'revoked']);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user): array
    {
        $req = $user ? 'sometimes' : 'required';
        if ($request->filled('phone')) {
            $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? 'invalid']);
        }

        return $request->validate([
            'full_name' => [$req, 'string', 'max:120'],
            'email' => [$req, 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{7,14}$/', Rule::unique('users', 'phone_e164')->ignore($user?->id)],
            'employee_no' => [$req, 'string', 'max:30', Rule::unique('staff_profiles', 'employee_no')->ignore($user?->id, 'user_id')],
            'branch_code' => [$req, 'exists:branches,code'],
            'roles' => [$req, 'array', 'min:1'],
            'roles.*' => [Rule::in(array_keys(Permissions::ROLE_ABILITIES))],
            'restrict_to_branch' => ['sometimes', 'boolean'],
            'preferred_locale' => ['sometimes', Rule::in(['ar', 'en'])],
        ]);
    }

    private function staff(string $id): User
    {
        abort_unless(Str::isUuid($id), 404);

        return User::query()->where('user_type', 'staff')->with('staffProfile.branch')->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(User $u): array
    {
        $p = $u->staffProfile;

        return [
            'id' => $u->id,
            'full_name' => $u->full_name,
            'email' => $u->email,
            'phone' => $u->phone_e164,
            'status' => $u->status,
            'suspended_reason' => $u->suspended_reason,
            'employee_no' => $p?->employee_no,
            'branch' => $p?->branch ? ['code' => $p->branch->code, 'name' => $p->branch->localized('name')] : null,
            'restrict_to_branch' => (bool) $p?->restrict_to_branch,
            'roles' => $u->roleNames(),
            'two_factor_enabled' => $p?->totp_enabled_at !== null,
            'activated' => $u->password_hash !== null,
            'last_login_at' => $u->last_login_at?->toIso8601ZuluString(),
        ];
    }
}
