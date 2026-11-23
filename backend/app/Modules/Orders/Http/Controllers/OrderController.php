<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Models\User;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\ReturnService;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * طلبات القطع للعميل (C-13، C-14، M-19، FR-ORD-03، FR-ORD-05). كل استعلام يمر بـ visibleTo (03 §13.1)،
 * والطلب غير المرئي 404.
 */
class OrderController
{
    /** تبويبات C-13 */
    private const TABS = [
        'open' => ['confirmed', 'processing', 'ready_for_pickup', 'out_for_delivery'],
        'delivered' => ['delivered'],
        'cancelled' => ['cancelled'],
    ];

    public function __construct(private readonly OrderService $orders, private readonly ReturnService $returns) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $f = $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(self::TABS))],
            'account' => ['nullable', 'string', 'max:36'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        $offset = OffsetCursor::decode($f['cursor'] ?? null);
        $base = SalesOrder::query()->visibleTo($user)->forAccount($f['account'] ?? null);
        $counts = collect(self::TABS)->map(fn ($s) => (clone $base)->whereIn('status', $s)->count());
        $rows = (clone $base)->whereIn('status', self::TABS[$f['tab'] ?? 'open'])->with(['items', 'branch', 'organization'])
            ->orderByDesc('created_at')->offset($offset)->limit(21)->get();

        return response()->json([
            'data' => $rows->take(20)->map(fn (SalesOrder $o) => OrderPresenter::row($o))->values(),
            'counts' => $counts,
            'next_cursor' => $rows->count() > 20 ? OffsetCursor::encode($offset + 20) : null,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $o = $this->find($this->user($request), $id);

        return response()->json(['data' => OrderPresenter::detail($o, $this->returns->eligibility($o))]);
    }

    /** 3.6: إلغاء العميل قبل بدء التجهيز دون رسوم (BR-13) */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $o = $this->find($user, $id);
        $this->orders->cancel($o, $user, $data['reason'] ?? __('orders.cancelled_by_customer'), true);

        return $this->show($request, $id);
    }

    /** 3b.1: طلب إرجاع بالبنود والسبب والصور (BR-12) */
    public function requestReturn(Request $request, string $id): JsonResponse
    {
        $user = $this->user($request);
        $o = $this->find($user, $id);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'attachment_ids' => ['nullable', 'array', 'max:6'],
            'attachment_ids.*' => ['uuid', 'distinct', Rule::exists('attachments', 'id')->where('uploaded_by', $user->id)],
        ]);
        $items = array_map(fn ($i) => ['order_item_id' => $i['order_item_id'], 'quantity' => (string) $i['quantity']], $data['items']);
        $this->returns->request($o, $user, $items, $data['reason'], $data['attachment_ids'] ?? []);

        return response()->json(['data' => OrderPresenter::detail($o = $this->find($user, $id), $this->returns->eligibility($o))], 201);
    }

    private function find(User $user, string $id): SalesOrder
    {
        abort_unless(Str::isUuid($id), 404);

        return SalesOrder::query()->visibleTo($user)->with(OrderPresenter::WITH)->findOrFail($id);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
