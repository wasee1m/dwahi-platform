<?php

namespace App\Modules\Orders\Http\Controllers\Admin;

use App\Models\User;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Models\ReturnRequest;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Orders\Services\OrderFromQuote;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\ReturnService;
use App\Modules\Quoting\Models\Quotation;
use App\Modules\Quoting\Models\Rfq;
use App\Support\OffsetCursor;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A-07 طلبات القطع والإرجاع (FR-ORD-04، FR-ORD-05، US-35). الموظف المقيّد بفرعه يرى طلبات فرعه فقط
 * (staff_profiles.restrict_to_branch)، والمحاسب يقرأ للاسترداد والمطابقة.
 */
class OrderAdminController
{
    /** أعمدة اللوحة */
    private const BOARD = ['confirmed', 'processing', 'ready_for_pickup', 'out_for_delivery'];

    public function __construct(private readonly OrderService $orders, private readonly ReturnService $returns, private readonly OrderFromQuote $fromQuote) {}

    public function index(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...SalesOrder::STATUSES, 'active'])],
            'branch_id' => ['nullable', 'uuid'],
            'fulfillment' => ['nullable', Rule::in(['delivery', 'pickup'])],
            'q' => ['nullable', 'string', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $base = $this->scoped($staff, SalesOrder::query())
            ->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->when($f['fulfillment'] ?? null, fn ($q, $v) => $q->where('fulfillment', $v))
            ->when(trim((string) ($f['q'] ?? '')), function ($q, $text) {
                $phone = Search::phone($text);
                $q->where(fn ($w) => $w->where('number', 'like', Search::contains($text))
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', Search::contains($text))
                        ->when($phone, fn ($c2) => $c2->orWhere('phone_e164', 'like', $phone)))
                    ->orWhereHas('organization', fn ($o) => $o->where('name', 'like', Search::contains($text))));
            });
        $counts = collect(self::BOARD)->mapWithKeys(fn ($s) => [$s => (clone $base)->where('status', $s)->count()]);
        $status = $f['status'] ?? 'active';
        $rows = (clone $base)->whereIn('status', $status === 'active' ? self::BOARD : [$status])
            ->with(['items', 'branch', 'organization', 'customer'])
            // الأقدم أولاً في التجهيز، ومهلة الاستلام الأقرب أولاً
            ->orderByRaw('pickup_deadline_at IS NULL')->orderBy('pickup_deadline_at')->orderBy('created_at')
            ->offset($offset)->limit(51)->get();

        return response()->json([
            'data' => $rows->take(50)->map(fn (SalesOrder $o) => OrderPresenter::row($o, true) + ['status_label' => $o->status])->values(),
            'counts' => $counts,
            'next_cursor' => $rows->count() > 50 ? OffsetCursor::encode($offset + 50) : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $o = $this->find($this->staff($request), $id);

        return response()->json(['data' => OrderPresenter::detail($o, $this->returns->eligibility($o), true)]);
    }

    /** انتقالات التجهيز (SM-3a 3.5 إلى 3.10)، والإلغاء بسبب إلزامي (3.7)، وتسجيل الاتصال (BR-18) */
    public function act(Request $request, string $id, string $action): JsonResponse
    {
        $staff = $this->staff($request);
        $o = $this->find($staff, $id);
        $needsReason = in_array($action, ['cancel', 'delivery-failed', 'phone'], true);
        $data = $request->validate([
            'reason' => [$needsReason ? 'required' : 'nullable', 'string', 'min:3', 'max:500'],
            'confirmed' => [$action === 'phone' ? 'required' : 'nullable', 'boolean'],
        ]);
        match ($action) {
            'process' => $this->orders->startProcessing($o, $staff),
            'ready' => $this->orders->markReady($o, $staff),
            'dispatch' => $this->orders->dispatchOut($o, $staff),
            'delivery-failed' => $this->orders->deliveryFailed($o, $staff, $data['reason']),
            'cancel' => $this->orders->cancel($o, $staff, $data['reason']),
            'phone' => $this->orders->recordPhoneConfirmation($o, $staff, (bool) $data['confirmed'], $data['reason']),
            default => abort(404),
        };

        return $this->show($request, $id);
    }

    /** 3.11 و 3.12: التسليم برمز الاستلام أو إثبات التوصيل، مع الدفعة في نفس الخطوة (BR-18) */
    public function deliver(Request $request, string $id): JsonResponse
    {
        $staff = $this->staff($request);
        $o = $this->find($staff, $id);
        $private = Rule::exists('attachments', 'id')->where('uploaded_by', $staff->id);
        $data = $request->validate([
            'code' => ['nullable', 'string', 'digits:6'],
            'proof_id' => ['nullable', 'uuid', $private],
            'payment' => ['nullable', 'array'],
            'payment.method' => ['required_with:payment', Rule::in(['cash', 'pos_card', 'bank_transfer'])],
            'payment.amount' => ['required_with:payment', 'decimal:0,3', 'gt:0'],
            'payment.reference_text' => [Rule::requiredIf(fn () => in_array($request->input('payment.method'), ['pos_card', 'bank_transfer'], true)), 'nullable', 'string', 'max:255'],
            'payment.proof_id' => ['nullable', 'uuid', $private],
        ]);
        if (isset($data['payment'])) {
            $data['payment']['amount'] = (string) $data['payment']['amount'];
        }
        $this->orders->deliver($o, $staff, $data);

        return $this->show($request, $id);
    }

    /** 3.14 يدوياً: طلب القطع من عرض قطع مقبول، مع ربط البنود غير المدرجة بالكتالوج */
    public function fromQuote(Request $request, string $rfqId): JsonResponse
    {
        abort_unless(Str::isUuid($rfqId), 404);
        $staff = $this->staff($request);
        $data = $request->validate(['map' => ['nullable', 'array', 'max:100'], 'map.*' => ['uuid', Rule::exists('parts', 'id')]]);
        $rfq = Rfq::query()->findOrFail($rfqId);
        $q = Quotation::query()->where('rfq_id', $rfq->id)->where('status', 'accepted')->firstOrFail();
        $map = collect($data['map'] ?? [])->mapWithKeys(fn ($part, $line) => [(int) $line => $part])->all();
        $o = $this->fromQuote->create($q, $staff, $map);

        return response()->json(['data' => ['id' => $o->id, 'number' => $o->number]], 201);
    }

    public function returnsIndex(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        $f = $request->validate(['status' => ['nullable', Rule::in(['requested', 'approved', 'rejected', 'received', 'refunded', 'open'])]]);
        $status = $f['status'] ?? 'open';
        $rows = ReturnRequest::query()->whereIn('status', $status === 'open' ? ReturnRequest::OPEN : [$status])
            ->whereHas('order', fn ($q) => $this->scoped($staff, $q))
            ->with(['items.orderItem', 'order', 'attachments'])->orderBy('created_at')->limit(100)->get();
        $counts = collect(['requested', 'approved', 'received'])->mapWithKeys(fn ($s) => [$s => ReturnRequest::query()->where('status', $s)
            ->whereHas('order', fn ($q) => $this->scoped($staff, $q))->count()]);

        return response()->json([
            'data' => $rows->map(fn (ReturnRequest $r) => OrderPresenter::returnRow($r, true) + ['amount' => $this->returns->amounts($r)])->values(),
            'counts' => $counts,
        ]);
    }

    /** SM-3b: قرار الإرجاع واستلامه (قطع)، والاسترداد (محاسب) */
    public function returnAct(Request $request, string $id, string $action): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $staff = $this->staff($request);
        $rr = ReturnRequest::query()->whereHas('order', fn ($q) => $this->scoped($staff, $q))->findOrFail($id);
        abort_unless($action === 'refund' ? $staff->hasAbility('refunds.manage') : $staff->hasAbility('orders.deliver'), 403);
        $data = $request->validate([
            'note' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:1000'],
            'restock' => ['nullable', 'array'],
            'restock.*' => ['boolean'],
            'method' => [$action === 'refund' ? 'required' : 'nullable', Rule::in(['cash', 'bank_transfer', 'pos_card'])],
            'reference_text' => [Rule::requiredIf(fn () => $request->input('method') === 'bank_transfer'), 'nullable', 'string', 'max:255'],
        ]);
        match ($action) {
            'approve' => $this->returns->decide($rr, $staff, true, $data['note'] ?? null),
            'reject' => $this->returns->decide($rr, $staff, false, $data['note']),
            'receive' => $this->returns->receive($rr, $staff, array_map('boolval', $data['restock'] ?? [])),
            'refund' => $this->returns->refund($rr, $staff, $data['method'], $data['reference_text'] ?? null),
            default => abort(404),
        };
        $rr = ReturnRequest::query()->with(['items.orderItem', 'order', 'attachments'])->findOrFail($id);

        return response()->json(['data' => OrderPresenter::returnRow($rr, true) + ['amount' => $this->returns->amounts($rr)]]);
    }

    /** @param Builder<SalesOrder> $q */
    private function scoped(User $staff, Builder $q): Builder
    {
        $p = $staff->staffProfile;

        return $p && $p->restrict_to_branch && ! $staff->hasAbility('*') ? $q->where('branch_id', $p->branch_id) : $q;
    }

    private function find(User $staff, string $id): SalesOrder
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->scoped($staff, SalesOrder::query())->with(OrderPresenter::WITH)->findOrFail($id);
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
