<?php

namespace App\Modules\Accounts\Services;

use App\Models\User;
use App\Modules\Accounts\Models\Invitation;
use App\Modules\Identity\Models\Organization;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Models\Rfq;
use App\Support\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * حذف الحساب (BR-16، US-11): يمنعه ملكية وحيدة لشركة فيها أعضاء، وطلب مفتوح يخص العميل وحده.
 * التعطيل فوري، والتجهيل في J-08 بعد مهلة account.deletion_grace_days.
 */
final class AccountDeletion
{
    private const OPEN = ['submitted', 'in_review', 'quoted'];

    public function __construct(private readonly OtpService $otp, private readonly TokenService $tokens) {}

    /** @return array{can_delete: bool, companies: list<array{id: string, name: string}>, requests: list<array{id: string, number: string, status: string}>} */
    public function check(User $user): array
    {
        $companies = [];
        $alone = []; // شركات لا يبقى فيها أحد بعده
        $memberships = DB::table('organization_members as m')->join('organizations as o', 'o.id', '=', 'm.organization_id')
            ->where('m.user_id', $user->id)->whereNull('m.removed_at')->get(['o.id', 'o.name', 'm.role']);

        foreach ($memberships as $m) {
            $others = DB::table('organization_members as x')->join('users as u', 'u.id', '=', 'x.user_id')
                ->where('x.organization_id', $m->id)->whereNull('x.removed_at')
                ->where('x.user_id', '!=', $user->id)->where('u.status', '!=', 'deleted')
                ->get(['x.role', 'u.status']);
            if ($others->isEmpty()) {
                $alone[] = $m->id;
            } elseif ($m->role === 'owner' && ! $others->contains(fn ($o) => $o->role === 'owner' && $o->status === 'active')) {
                $companies[] = ['id' => $m->id, 'name' => $m->name];
            }
        }

        // طلبات القطع (S6) والصيانة (S7) تنضم لهذه القائمة مع وحدتيهما
        $requests = Rfq::query()->whereIn('status', self::OPEN)
            ->where(fn ($w) => $w->where(fn ($x) => $x->whereNull('organization_id')->where('customer_user_id', $user->id))
                ->orWhereIn('organization_id', $alone))
            ->orderBy('created_at')->get()
            ->map(fn (Rfq $r) => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status])->values()->all();

        return ['can_delete' => $companies === [] && $requests === [], 'companies' => $companies, 'requests' => $requests];
    }

    /** @return string رمز التأكيد (لا يُعاد للعميل إلا في التطوير) */
    public function requestCode(User $user, ?string $ip, string $locale): string
    {
        $this->assertAllowed($user);

        return $this->otp->request((string) $user->phone_e164, 'delete_account', $ip, $locale)['code'];
    }

    public function delete(User $user, string $code): void
    {
        $this->assertAllowed($user);
        $this->otp->verify((string) $user->phone_e164, 'delete_account', $code);

        DB::transaction(function () use ($user) {
            $orgIds = DB::table('organization_members')->where('user_id', $user->id)->whereNull('removed_at')->pluck('organization_id');
            Organization::query()->whereIn('id', $orgIds)->orderBy('id')->lockForUpdate()->get();
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->assertAllowed($locked); // قد يتغير المالكون بين الطلب والتأكيد

            DB::table('organization_members')->where('user_id', $user->id)->whereNull('removed_at')->update(['removed_at' => now()]);
            $empty = $orgIds->reject(fn ($id) => DB::table('organization_members')->where('organization_id', $id)->whereNull('removed_at')->exists());
            Invitation::query()->where('status', 'pending')
                ->where(fn ($w) => $w->where('invited_by', $user->id)->orWhere('phone_e164', $user->phone_e164)->orWhereIn('organization_id', $empty))
                ->update(['status' => 'revoked']);

            $locked->forceFill(['status' => 'deleted', 'deleted_at' => now()])->save();
            DB::table('devices')->where('user_id', $user->id)->update(['status' => 'revoked', 'push_token' => null, 'updated_at' => now()]);
            $this->tokens->revokeAll($locked, 'account_deleted');
            Audit::record('account.deleted', 'user', $user->id, ['status' => 'active'], ['status' => 'deleted'], $user);
        });
    }

    private function assertAllowed(User $user): void
    {
        $check = $this->check($user);
        if (! $check['can_delete']) {
            throw new ApiProblem('DELETION_BLOCKED', 409, null, ['companies' => $check['companies'], 'requests' => $check['requests']]);
        }
    }
}
