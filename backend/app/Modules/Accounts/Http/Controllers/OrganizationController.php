<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Models\User;
use App\Modules\Accounts\Http\AccountPresenter;
use App\Modules\Accounts\Models\Invitation;
use App\Modules\Accounts\Services\OrganizationService;
use App\Modules\Accounts\Support\Memberships;
use App\Modules\Identity\Models\Organization;
use App\Modules\Identity\Support\Phone;
use App\Support\Governorates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** C-03 الشركة والأعضاء (FR-ACC-02، US-09) */
class OrganizationController
{
    public function __construct(private readonly OrganizationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgs = Organization::query()->whereIn('id', Memberships::organizationIds($user))->orderBy('name')->get();

        return response()->json(['data' => $orgs->map(fn (Organization $o) => $this->present($user, $o))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'governorate' => ['required', Rule::in(Governorates::ALL)],
            'cr_number' => ['nullable', 'string', 'max:50', 'unique:organizations,cr_number'],
            'vat_number' => ['nullable', 'string', 'max:50'],
        ]);
        $org = $this->service->create($request->user(), $data);

        return response()->json(['data' => $this->present($request->user(), $org->refresh())], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user(), $this->find($request->user(), $id))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->find($request->user(), $id);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'governorate' => ['sometimes', 'required', Rule::in(Governorates::ALL)],
            'cr_number' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('organizations', 'cr_number')->ignore($id)],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'preapproval_limit' => ['sometimes', 'required', 'decimal:0,3', 'min:0', 'max:999999'], // BR-06
        ]);
        $this->service->update($id, $request->user(), $data);

        return $this->show($request, $id);
    }

    public function invite(Request $request, string $id): JsonResponse
    {
        $this->find($request->user(), $id);
        $data = self::invitationInput($request);
        $this->service->invite($id, $request->user(), $data['phone'], $data['role'], $data['can_approve_quotes']);

        return response()->json(['data' => $this->present($request->user(), $this->find($request->user(), $id))], 201);
    }

    /** @return array{phone: string, role: string, can_approve_quotes: bool} */
    public static function invitationInput(Request $request): array
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'role' => ['required', Rule::in(['owner', 'member'])],
            'can_approve_quotes' => ['sometimes', 'boolean'],
        ]);
        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => [__('validation.custom.phone.regex')]]);
        }

        return ['phone' => $phone, 'role' => $data['role'], 'can_approve_quotes' => (bool) ($data['can_approve_quotes'] ?? false)];
    }

    public function revokeInvitation(Request $request, string $id, string $invitationId): JsonResponse
    {
        $this->find($request->user(), $id);
        abort_unless(Str::isUuid($invitationId), 404);
        $this->service->revokeInvitation($id, $request->user(), $invitationId);

        return $this->show($request, $id);
    }

    public function updateMember(Request $request, string $id, string $userId): JsonResponse
    {
        $this->find($request->user(), $id);
        $data = $request->validate(['role' => ['required', Rule::in(['owner', 'member'])], 'can_approve_quotes' => ['required', 'boolean']]);
        $this->service->updateMember($id, $request->user(), $userId, $data['role'], (bool) $data['can_approve_quotes']);

        return $this->show($request, $id);
    }

    /** إزالة عضو، أو مغادرة الشركة بمعرّف المستخدم نفسه */
    public function removeMember(Request $request, string $id, string $userId): JsonResponse
    {
        $this->find($request->user(), $id);
        $this->service->removeMember($id, $request->user(), $userId);

        return response()->json(null, 204);
    }

    /** الدعوات الموجهة إلى رقمي */
    public function myInvitations(Request $request): JsonResponse
    {
        $phone = $request->user()->phone_e164;
        $rows = $phone ? Invitation::query()->open()->where('phone_e164', $phone)->with(['organization', 'inviter'])->get() : collect();

        return response()->json(['data' => $rows->map(fn (Invitation $i) => [
            'id' => $i->id,
            'company' => $i->organization->name,
            'invited_by' => $i->inviter->full_name,
            /** @var 'owner'|'member' */
            'role' => $i->role,
            'can_approve_quotes' => $i->can_approve_quotes,
            'expires_at' => $i->expires_at->toIso8601ZuluString(),
        ])->values()]);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $org = $this->service->accept($request->user(), $id);

        return response()->json(['data' => $this->present($request->user(), $org)]);
    }

    public function decline(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $this->service->decline($request->user(), $id);

        return response()->json(null, 204);
    }

    private function find(User $user, string $id): Organization
    {
        abort_unless(Str::isUuid($id), 404);

        return Organization::query()->whereIn('id', Memberships::organizationIds($user))->findOrFail($id);
    }

    private function present(User $user, Organization $org): array
    {
        $role = Memberships::role($user, $org->id);

        return AccountPresenter::organization($org, $role === 'owner', $role, $role === 'owner');
    }
}
