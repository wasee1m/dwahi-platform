<?php

namespace App\Modules\Accounts\Services;

use App\Models\User;
use App\Modules\Accounts\Events\MemberInvited;
use App\Modules\Accounts\Models\Invitation;
use App\Modules\Accounts\Support\Memberships;
use App\Modules\Identity\Models\Organization;
use App\Modules\Platform\Support\Audit;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الشركة وأعضاؤها (FR-ACC-02، US-09). يدير الأعضاء المالك، ومدير النظام من A-12 (01 §3.2).
 * كل تغيير في العضوية يقفل صف الشركة، فلا يزيل مالكان بعضهما في اللحظة نفسها.
 */
final class OrganizationService
{
    private const MAX_PER_USER = 5;

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Organization
    {
        return DB::transaction(function () use ($user, $data) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (Memberships::organizationIds($user)->count() >= self::MAX_PER_USER) {
                throw new ApiProblem('COMPANY_LIMIT', 409);
            }
            $org = Organization::query()->create($data);
            $org->members()->attach($user->id, ['role' => 'owner', 'can_approve_quotes' => true, 'joined_at' => now()]);
            Audit::record('organization.created', 'organization', $org->id, null, ['name' => $org->name], $user);

            return $org;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $organizationId, User $by, array $data): void
    {
        $this->locked($organizationId, $by, function (Organization $org) use ($by, $data) {
            $before = $org->only(array_keys($data));
            $org->update($data);
            Audit::record('organization.updated', 'organization', $org->id, $before, $org->only(array_keys($data)), $by);
        });
    }

    public function invite(string $organizationId, User $by, string $phone, string $role, bool $canApprove): Invitation
    {
        $invitation = $this->locked($organizationId, $by, function (Organization $org) use ($by, $phone, $role, $canApprove) {
            $invitee = User::query()->where('phone_e164', $phone)->first();
            if ($invitee?->isStaff()) {
                throw ValidationException::withMessages(['phone' => [__('accounts.staff_phone')]]);
            }
            if ($invitee && Memberships::includes($invitee, $org->id)) {
                throw ValidationException::withMessages(['phone' => [__('accounts.already_member')]]);
            }
            // الدعوة الجديدة تحل محل المعلقة لنفس الرقم
            Invitation::query()->where(['organization_id' => $org->id, 'phone_e164' => $phone, 'status' => 'pending'])
                ->update(['status' => 'revoked']);
            $invitation = Invitation::query()->create([
                'organization_id' => $org->id, 'phone_e164' => $phone, 'role' => $role,
                'can_approve_quotes' => $role === 'owner' || $canApprove, 'status' => 'pending',
                'expires_at' => now()->addDays(Invitation::DAYS), 'invited_by' => $by->id,
            ]);
            // سجل التدقيق إلحاقي ولا يُجهَّل، فلا يُكتب فيه الجوال (BR-16)
            Audit::record('organization.member_invited', 'organization', $org->id, null, ['invitation' => $invitation->id, 'role' => $role], $by);

            return $invitation;
        });
        MemberInvited::dispatch($invitation, $by);

        return $invitation;
    }

    public function revokeInvitation(string $organizationId, User $by, string $invitationId): void
    {
        $this->locked($organizationId, $by, function (Organization $org) use ($by, $invitationId) {
            $invitation = Invitation::query()->where('organization_id', $org->id)->where('status', 'pending')->findOrFail($invitationId);
            $invitation->update(['status' => 'revoked']);
            Audit::record('organization.invitation_revoked', 'organization', $org->id, null, ['invitation' => $invitation->id], $by);
        });
    }

    /** القبول لصاحب الرقم المدعو وحده: رقمه مُتحقق منه بالرمز عند الدخول */
    public function accept(User $user, string $invitationId): Organization
    {
        $found = Invitation::query()->where('phone_e164', $user->phone_e164)->whereNotNull('phone_e164')->findOrFail($invitationId);

        return DB::transaction(function () use ($user, $found) {
            $org = Organization::query()->lockForUpdate()->findOrFail($found->organization_id);
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($found->id);
            if ($invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
                throw new ApiProblem('INVITATION_CLOSED', 409);
            }
            DB::table('organization_members')->updateOrInsert(
                ['organization_id' => $org->id, 'user_id' => $user->id],
                ['role' => $invitation->role, 'can_approve_quotes' => $invitation->can_approve_quotes, 'joined_at' => now(), 'removed_at' => null],
            );
            $invitation->update(['status' => 'accepted']);
            Audit::record('organization.member_joined', 'organization', $org->id, null, ['user_id' => $user->id, 'role' => $invitation->role], $user);

            return $org;
        });
    }

    public function decline(User $user, string $invitationId): void
    {
        $invitation = Invitation::query()->open()->where('phone_e164', $user->phone_e164)->findOrFail($invitationId);
        $invitation->update(['status' => 'revoked']);
        Audit::record('organization.invitation_declined', 'organization', $invitation->organization_id, null, ['invitation' => $invitation->id], $user);
    }

    public function updateMember(string $organizationId, User $by, string $userId, string $role, bool $canApprove): void
    {
        $this->locked($organizationId, $by, function (Organization $org) use ($by, $userId, $role, $canApprove) {
            $member = $this->member($org, $userId);
            if ($member->role === 'owner' && $role !== 'owner') {
                $this->assertAnotherOwner($org, $userId);
                $this->revokeInvitationsBy($org, $userId);
            }
            $after = ['role' => $role, 'can_approve_quotes' => $role === 'owner' || $canApprove];
            DB::table('organization_members')->where(['organization_id' => $org->id, 'user_id' => $userId])->update($after);
            Audit::record('organization.member_updated', 'organization', $org->id,
                ['user_id' => $userId, 'role' => $member->role, 'can_approve_quotes' => (bool) $member->can_approve_quotes],
                ['user_id' => $userId] + $after, $by);
        });
    }

    /** إزالة عضو، أو مغادرة العضو نفسه. طلباته تبقى للشركة ويفقد الوصول فوراً (02 §10). */
    public function removeMember(string $organizationId, User $by, string $userId): void
    {
        $leaving = $by->id === $userId;
        $this->locked($organizationId, $by, function (Organization $org) use ($by, $userId) {
            if ($this->member($org, $userId)->role === 'owner') {
                $this->assertAnotherOwner($org, $userId);
            }
            DB::table('organization_members')->where(['organization_id' => $org->id, 'user_id' => $userId])->update(['removed_at' => now()]);
            $this->revokeInvitationsBy($org, $userId);
            Audit::record('organization.member_removed', 'organization', $org->id, ['user_id' => $userId], null, $by);
        }, ownerOnly: ! $leaving);
    }

    /** @template T @param callable(Organization): T $fn @return T */
    private function locked(string $organizationId, User $by, callable $fn, bool $ownerOnly = true)
    {
        return DB::transaction(function () use ($organizationId, $by, $fn, $ownerOnly) {
            $org = Organization::query()->lockForUpdate()->findOrFail($organizationId);
            if ($by->isStaff()) {
                abort_unless($by->hasAbility('customers.members'), 403);
            } else {
                $role = Memberships::role($by, $org->id);
                abort_if($role === null, 404); // غير العضو لا يعرف بوجود الشركة
                abort_if($ownerOnly && $role !== 'owner', 403);
            }

            return $fn($org);
        });
    }

    private function member(Organization $org, string $userId): object
    {
        $row = DB::table('organization_members')->where(['organization_id' => $org->id, 'user_id' => $userId])->whereNull('removed_at')->first();
        abort_if($row === null, 404);

        return $row;
    }

    /** لا يُزال آخر مالك نشط (03 §3). المالك الموقوف لا يُحسب. */
    private function assertAnotherOwner(Organization $org, string $userId): void
    {
        $others = DB::table('organization_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $org->id)->whereNull('m.removed_at')->where('m.role', 'owner')
            ->where('u.status', 'active')->where('m.user_id', '!=', $userId)->exists();
        if (! $others) {
            throw new ApiProblem('LAST_OWNER', 409);
        }
    }

    /** دعوات من فقد الإدارة لا تبقى سارية */
    private function revokeInvitationsBy(Organization $org, string $userId): void
    {
        Invitation::query()->where(['organization_id' => $org->id, 'invited_by' => $userId, 'status' => 'pending'])->update(['status' => 'revoked']);
    }
}
