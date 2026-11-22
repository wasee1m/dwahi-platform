<?php

namespace App\Modules\Quoting\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Messaging\Services\Messenger;
use App\Modules\Quoting\Http\Controllers\RfqController;
use App\Modules\Quoting\Http\RfqPresenter;
use App\Modules\Quoting\Models\Rfq;
use App\Modules\Quoting\Services\QuotationService;
use App\Modules\Quoting\Services\RfqService;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** A-05 طلبات العروض (FR-RFQ-01، FR-RFQ-02، FR-RFQ-05، US-32): وارد، وإسناد، وخسارة، ونيابة عن العميل */
class RfqAdminController
{
    public function __construct(private readonly RfqService $rfqs, private readonly QuotationService $quotes, private readonly Messenger $messenger) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(Rfq::STATUSES)],
            'type' => ['nullable', Rule::in(['equipment', 'parts', 'service'])],
            'assigned' => ['nullable', 'in:me,none'],
            'q' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $base = Rfq::query()
            ->when($f['type'] ?? null, fn ($w, $v) => $w->where('type', $v))
            ->when(($f['assigned'] ?? null) === 'me', fn ($w) => $w->where('assigned_to', $request->user()->id))
            ->when(($f['assigned'] ?? null) === 'none', fn ($w) => $w->whereNull('assigned_to'))
            ->when($f['q'] ?? null, fn ($w, $v) => $w->where(fn ($x) => $x->where('number', 'like', "%{$v}%")
                ->orWhereIn('customer_user_id', User::query()->where('full_name', 'like', "%{$v}%")->orWhere('phone_e164', 'like', '%'.preg_replace('/\D/', '', $v).'%')->select('id'))));
        $counts = (clone $base)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status');
        $rows = (clone $base)->when($f['status'] ?? null, fn ($w, $v) => $w->where('status', $v), fn ($w) => $w->whereIn('status', ['submitted', 'in_review', 'quoted']))
            ->with(['items.listing', 'items.part', 'customer', 'organization', 'assignee', 'quotations'])
            ->orderByRaw("FIELD(status, 'submitted', 'in_review', 'quoted')")->orderBy('created_at')->orderBy('id')
            ->offset($offset)->limit(51)->get();

        return response()->json([
            'data' => $rows->take(50)->map(fn (Rfq $r) => RfqPresenter::row($r, true))->values(),
            'counts' => $counts,
            'next_cursor' => $rows->count() > 50 ? OffsetCursor::encode($offset + 50) : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $rfq = $this->find($id);

        return response()->json(['data' => [
            ...RfqPresenter::detail($rfq, true),
            'unread_messages' => $this->messenger->unreadCount($rfq, $request->user()),
            'can_reopen' => $rfq->status === 'lost' && $rfq->closed_at?->gt(now()->subDays(90)),
        ]]);
    }

    /** إنشاء طلب نيابة عن عميل (01 §3.2، SM-1 1.1) بمصدر الهاتف أو واتساب أو الحضور */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'uuid', Rule::exists('users', 'id')->where('user_type', 'customer')],
            'source' => ['required', Rule::in(['phone', 'whatsapp', 'walk_in'])],
            ...collect(RfqController::rules($request->user()))->except(['source'])->all(),
        ]);
        $customer = User::query()->findOrFail($data['customer_id']);
        $rfq = $this->rfqs->create($customer, $request->user(), $data['source'], $data);
        $this->rfqs->assign($rfq, $request->user(), $request->user());

        return response()->json(['data' => RfqPresenter::detail($this->find($rfq->id), true)], 201);
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        // exists لا in: قيم in تُنسخ إلى عقد OpenAPI، فلا تؤخذ من قاعدة البيانات
        $staff = Rule::exists('user_roles', 'user_id')->whereIn('role', ['sales', 'service_coordinator', 'admin']);
        $userId = $request->validate(['user_id' => ['nullable', 'uuid', $staff]])['user_id'] ?? $request->user()->id;
        $this->rfqs->assign($this->find($id), User::query()->findOrFail($userId), $request->user());

        return $this->show($request, $id);
    }

    public function lose(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->rfqs->lose($this->find($id), $request->user(), $reason);

        return $this->show($request, $id);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->rfqs->reopen($this->find($id), $request->user(), $reason);

        return $this->show($request, $id);
    }

    /** "إعداد عرض": مسودة جديدة أو الموجودة (US-32) */
    public function draft(Request $request, string $id): JsonResponse
    {
        $q = $this->quotes->draft($this->find($id), $request->user());

        return response()->json(['data' => RfqPresenter::quotation($q->load('items'))], 201);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->messenger->thread($this->find($id), $request->user())]);
    }

    public function postMessage(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'], 'body' => ['nullable', 'string', 'max:5000'],
            'attachment_ids' => ['sometimes', 'array', 'max:5'], 'attachment_ids.*' => ['uuid', 'distinct'],
        ]);
        $rfq = $this->find($id);
        $this->messenger->post($rfq, $request->user(), $data['id'], $data['body'] ?? null, $data['attachment_ids'] ?? []);

        return response()->json(['data' => $this->messenger->thread($rfq, $request->user())], 201);
    }

    /** موظفو المبيعات والمنسقون للإسناد */
    public function assignees(): JsonResponse
    {
        return response()->json(['data' => User::query()->where('status', 'active')
            ->whereIn('id', DB::table('user_roles')->whereIn('role', ['sales', 'service_coordinator', 'admin'])->select('user_id'))
            ->orderBy('full_name')->get(['id', 'full_name'])]);
    }

    private function find(string $id): Rfq
    {
        abort_unless(Str::isUuid($id), 404);

        return Rfq::query()->with([...RfqPresenter::WITH, 'quotations.items'])->findOrFail($id);
    }
}
