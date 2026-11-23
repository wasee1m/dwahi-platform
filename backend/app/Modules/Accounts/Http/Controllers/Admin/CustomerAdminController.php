<?php

namespace App\Modules\Accounts\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Accounts\Http\AccountPresenter;
use App\Modules\Accounts\Http\Controllers\FleetController;
use App\Modules\Accounts\Http\Controllers\OrganizationController;
use App\Modules\Accounts\Models\Address;
use App\Modules\Accounts\Models\CustomerEquipment;
use App\Modules\Accounts\Models\CustomerNote;
use App\Modules\Accounts\Services\OrganizationService;
use App\Modules\Billing\Http\InvoicePresenter;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Identity\Models\Organization;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Platform\Support\Audit;
use App\Modules\Quoting\Http\RfqPresenter;
use App\Modules\Quoting\Models\Rfq;
use App\Modules\Service\Http\ServicePresenter;
use App\Modules\Service\Models\ServiceRequest;
use App\Support\ApiProblem;
use App\Support\OffsetCursor;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A-12 العملاء والشركات (FR-ADM-05، US-45، SM-7) */
class CustomerAdminController
{
    private const PAGE = 30;

    private const RECENT = 50;

    public function __construct(private readonly OrganizationService $organizations, private readonly TokenService $tokens) {}

    /** البحث بالجوال أو الاسم أو البريد أو اسم الشركة أو السجل التجاري */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'deleted'])],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $users = User::query()->where('user_type', 'customer')
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = Search::contains($v);
                $q->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('email', 'like', $like)
                    ->when(Search::phone($v), fn ($x, $p) => $x->orWhere('phone_e164', 'like', $p))
                    ->orWhereIn('id', DB::table('organization_members as m')->join('organizations as o', 'o.id', '=', 'm.organization_id')
                        ->whereNull('m.removed_at')
                        ->where(fn ($o) => $o->where('o.name', 'like', $like)->orWhere('o.cr_number', 'like', $like))
                        ->select('m.user_id')));
            })
            ->with('organizations')->orderByDesc('created_at')->orderBy('id')->offset($offset)->limit(self::PAGE + 1)->get();

        return response()->json([
            'data' => $users->take(self::PAGE)->map(fn (User $u) => [
                'id' => $u->id,
                'full_name' => $u->full_name,
                /** @var string|null */
                'phone' => $u->phone_e164,
                /** @var 'active'|'suspended'|'deleted' */
                'status' => $u->status,
                'flagged' => $u->internal_flag_at !== null,
                /** @var list<array{id: string, name: string}> */
                'organizations' => $u->organizations->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])->values()->all(),
                'created_at' => $u->created_at?->toIso8601ZuluString(),
            ])->values(),
            'next_cursor' => $users->count() > self::PAGE ? OffsetCursor::encode($offset + self::PAGE) : null,
        ]);
    }

    /** الشركات: بالاسم أو السجل التجاري أو جوال أحد الأعضاء */
    public function organizationIndex(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'cursor' => ['nullable', 'string', 'max:50']]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $rows = Organization::query()
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = Search::contains($v);
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('cr_number', 'like', $like)
                    ->when(Search::phone($v), fn ($x, $p) => $x->orWhereIn('id', DB::table('organization_members as m')
                        ->join('users as u', 'u.id', '=', 'm.user_id')->whereNull('m.removed_at')
                        ->where('u.phone_e164', 'like', $p)->select('m.organization_id'))));
            })
            ->withCount(['members' => fn ($m) => $m->whereNull('organization_members.removed_at')])
            ->orderBy('name')->orderBy('id')->offset($offset)->limit(self::PAGE + 1)->get();

        return response()->json([
            'data' => $rows->take(self::PAGE)->map(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                /** @var string|null */
                'cr_number' => $o->cr_number,
                'governorate' => $o->governorate,
                /** @var int */
                'members_count' => $o->members_count,
            ])->values(),
            'next_cursor' => $rows->count() > self::PAGE ? OffsetCursor::encode($offset + self::PAGE) : null,
        ]);
    }

    /** ملف 360° */
    public function show(string $id): JsonResponse
    {
        $u = $this->customer($id);
        $u->load('organizations');
        $rfqs = Rfq::query()->where('customer_user_id', $u->id);

        return response()->json(['data' => [
            'id' => $u->id,
            'full_name' => $u->full_name,
            /** @var string|null */
            'phone' => $u->phone_e164,
            /** @var string|null */
            'email' => $u->email,
            'email_verified' => $u->email_verified_at !== null,
            'preferred_locale' => $u->preferred_locale,
            /** @var 'active'|'suspended'|'deleted' */
            'status' => $u->status,
            /** @var string|null */
            'suspended_reason' => $u->suspended_reason,
            /** @var int */
            'pickup_no_show_count' => $u->pickup_no_show_count,
            'flagged' => $u->internal_flag_at !== null,
            'created_at' => $u->created_at?->toIso8601ZuluString(),
            /** @var string|null */
            'last_login_at' => $u->last_login_at?->toIso8601ZuluString(),
            /** @var string|null */
            'deleted_at' => $u->deleted_at?->toIso8601ZuluString(),
            /** @var string|null */
            'anonymized_at' => $u->anonymized_at?->toIso8601ZuluString(),
            'active_sessions' => DB::table('sessions')->where('user_id', $u->id)->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            /** @var list<array{id: string, name: string, role: 'owner'|'member', can_approve_quotes: bool}> */
            'organizations' => $u->organizations->map(fn (Organization $o) => [
                'id' => $o->id, 'name' => $o->name, 'role' => $o->pivot->role, 'can_approve_quotes' => (bool) $o->pivot->can_approve_quotes,
            ])->values()->all(),
            'addresses' => Address::query()->ownedBy($u, null)->whereNull('archived_at')->withCoordinates()->with('organization')->get()
                ->map(fn (Address $a) => AccountPresenter::address($a))->values(),
            'fleet' => CustomerEquipment::query()->ownedBy($u, null)->whereNull('archived_at')->with(FleetController::WITH)->get()
                ->map(fn (CustomerEquipment $e) => AccountPresenter::equipment($e))->values(),
            'requests' => $this->requests(clone $rfqs),
            'orders' => $this->orders(SalesOrder::query()->where('customer_user_id', $u->id)),
            'service_requests' => $this->services(ServiceRequest::query()->where('customer_user_id', $u->id)),
            'invoices' => $this->invoices(Invoice::query()->where('customer_user_id', $u->id)),
            'conversations' => $this->conversations(clone $rfqs),
            'notes' => $this->notes('user_id', $u->id),
        ]]);
    }

    public function organization(Request $request, string $id): JsonResponse
    {
        $o = $this->company($id);
        $manages = $request->user()->hasAbility('customers.members');
        $rfqs = Rfq::query()->where('organization_id', $o->id);

        return response()->json(['data' => [
            ...AccountPresenter::organization($o, $manages, null, true),
            'addresses' => Address::query()->where('organization_id', $o->id)->whereNull('archived_at')->withCoordinates()->with('organization')->get()
                ->map(fn (Address $a) => AccountPresenter::address($a))->values(),
            'fleet' => CustomerEquipment::query()->where('organization_id', $o->id)->whereNull('archived_at')->with(FleetController::WITH)->get()
                ->map(fn (CustomerEquipment $e) => AccountPresenter::equipment($e))->values(),
            'requests' => $this->requests(clone $rfqs),
            'orders' => $this->orders(SalesOrder::query()->where('organization_id', $o->id)),
            'service_requests' => $this->services(ServiceRequest::query()->where('organization_id', $o->id)),
            'invoices' => $this->invoices(Invoice::query()->where('organization_id', $o->id)),
            'conversations' => $this->conversations(clone $rfqs),
            'notes' => $this->notes('organization_id', $o->id),
        ]]);
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        $u = $this->customer($id);
        $body = $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];
        CustomerNote::query()->create(['user_id' => $u->id, 'author_id' => $request->user()->id, 'body' => $body]);

        return response()->json(['data' => $this->notes('user_id', $u->id)], 201);
    }

    public function addOrganizationNote(Request $request, string $id): JsonResponse
    {
        $o = $this->company($id);
        $body = $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];
        CustomerNote::query()->create(['organization_id' => $o->id, 'author_id' => $request->user()->id, 'body' => $body]);

        return response()->json(['data' => $this->notes('organization_id', $o->id)], 201);
    }

    /** SM-7: للمدير، بسبب إلزامي، وتُنهى الجلسات */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $u = $this->customer($id);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        DB::transaction(function () use ($u, $reason) {
            $locked = User::query()->lockForUpdate()->findOrFail($u->id);
            if ($locked->status !== 'active') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $locked->status, 'to' => 'suspended']);
            }
            $locked->forceFill(['status' => 'suspended', 'suspended_reason' => $reason])->save();
            $this->tokens->revokeAll($locked, 'account_suspended');
            Audit::record('customer.suspended', 'user', $locked->id, ['status' => 'active'], ['status' => 'suspended', 'reason' => $reason]);
        });

        return $this->show($id);
    }

    public function activate(string $id): JsonResponse
    {
        $u = $this->customer($id);
        DB::transaction(function () use ($u) {
            $locked = User::query()->lockForUpdate()->findOrFail($u->id);
            if ($locked->status !== 'suspended') {
                throw new ApiProblem('INVALID_TRANSITION', 409, null, ['from' => $locked->status, 'to' => 'active']);
            }
            $reason = $locked->suspended_reason;
            $locked->forceFill(['status' => 'active', 'suspended_reason' => null])->save();
            Audit::record('customer.activated', 'user', $locked->id, ['status' => 'suspended', 'reason' => $reason], ['status' => 'active']);
        });

        return $this->show($id);
    }

    // إدارة أعضاء الشركة لمدير النظام (01 §3.2)

    public function invite(Request $request, string $id): JsonResponse
    {
        $o = $this->company($id);
        $data = OrganizationController::invitationInput($request);
        $this->organizations->invite($o->id, $request->user(), $data['phone'], $data['role'], $data['can_approve_quotes']);

        return $this->organization($request, $id);
    }

    public function revokeInvitation(Request $request, string $id, string $invitationId): JsonResponse
    {
        abort_unless(Str::isUuid($invitationId), 404);
        $this->organizations->revokeInvitation($this->company($id)->id, $request->user(), $invitationId);

        return $this->organization($request, $id);
    }

    public function updateMember(Request $request, string $id, string $userId): JsonResponse
    {
        $o = $this->company($id);
        $data = $request->validate(['role' => ['required', Rule::in(['owner', 'member'])], 'can_approve_quotes' => ['required', 'boolean']]);
        $this->organizations->updateMember($o->id, $request->user(), $userId, $data['role'], (bool) $data['can_approve_quotes']);

        return $this->organization($request, $id);
    }

    public function removeMember(Request $request, string $id, string $userId): JsonResponse
    {
        $this->organizations->removeMember($this->company($id)->id, $request->user(), $userId);

        return $this->organization($request, $id);
    }

    private function requests(Builder $rfqs): array
    {
        return $rfqs->with(['items.listing', 'items.part', 'customer', 'organization', 'assignee', 'quotations'])
            ->orderByDesc('created_at')->limit(self::RECENT)->get()
            ->map(fn (Rfq $r) => RfqPresenter::row($r, true))->values()->all();
    }

    private function orders(Builder $orders): array
    {
        return $orders->with(['items', 'branch', 'organization', 'customer'])->orderByDesc('created_at')->limit(self::RECENT)->get()
            ->map(fn (SalesOrder $o) => OrderPresenter::row($o, true))->values()->all();
    }

    private function services(Builder $requests): array
    {
        return $requests->with(ServicePresenter::WITH)->orderByDesc('created_at')->limit(self::RECENT)->get()
            ->map(fn (ServiceRequest $s) => ServicePresenter::row($s, true))->values()->all();
    }

    /** الصادرة فقط، والمتأخرة معلّمة (BR-15) */
    private function invoices(Builder $invoices): array
    {
        return $invoices->where('status', '!=', 'draft')->with(InvoicePresenter::WITH)->orderByDesc('issue_date')->limit(self::RECENT)->get()
            ->map(fn (Invoice $i) => InvoicePresenter::row($i, true))->values()->all();
    }

    /** @return list<array{rfq_id: string, number: string, messages: int, last_message_at: string|null}> */
    private function conversations(Builder $rfqs): array
    {
        return DB::table('conversations as c')->join('rfqs as r', 'r.id', '=', 'c.rfq_id')
            ->whereIn('c.rfq_id', $rfqs->select('rfqs.id'))->whereNotNull('c.last_message_at')
            ->orderByDesc('c.last_message_at')->limit(self::RECENT)
            ->select(['c.rfq_id', 'r.number', 'c.last_message_at'])
            ->selectRaw('(SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS messages')
            ->get()->map(fn ($c) => [
                'rfq_id' => $c->rfq_id, 'number' => $c->number, 'messages' => (int) $c->messages,
                'last_message_at' => $c->last_message_at ? str_replace(' ', 'T', substr($c->last_message_at, 0, 19)).'Z' : null,
            ])->values()->all();
    }

    /** @return list<array{id: string, author: string|null, body: string, at: string}> */
    private function notes(string $column, string $id): array
    {
        return CustomerNote::query()->where($column, $id)->with('author')->orderByDesc('created_at')->get()
            ->map(fn (CustomerNote $n) => [
                'id' => $n->id,
                /** @var string|null */
                'author' => $n->author?->full_name, 'body' => $n->body, 'at' => $n->created_at->toIso8601ZuluString(),
            ])->values()->all();
    }

    private function customer(string $id): User
    {
        abort_unless(Str::isUuid($id), 404);

        return User::query()->where('user_type', 'customer')->findOrFail($id);
    }

    private function company(string $id): Organization
    {
        abort_unless(Str::isUuid($id), 404);

        return Organization::query()->findOrFail($id);
    }
}
