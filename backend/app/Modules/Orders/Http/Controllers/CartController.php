<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Models\User;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Services\CartService;
use App\Modules\Orders\Services\CheckoutService;
use App\Modules\Orders\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** السلة وإتمام الطلب (C-10، C-11، M-11، FR-ORD-01، FR-ORD-02) */
class CartController
{
    public function __construct(private readonly CartService $cart, private readonly CheckoutService $checkout, private readonly ReturnService $returns) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->cart->view($this->user($request))]);
    }

    /** الكمية 0 تحذف البند */
    public function setItem(Request $request, string $partId): JsonResponse
    {
        abort_unless(Str::isUuid($partId), 404);
        $data = $request->validate(['quantity' => ['required', 'decimal:0,2', 'min:0', 'max:'.CartService::MAX_QTY]]);
        $user = $this->user($request);
        $this->cart->set($user, $partId, (string) $data['quantity']);

        return response()->json(['data' => $this->cart->view($user)]);
    }

    public function removeItem(Request $request, string $partId): JsonResponse
    {
        abort_unless(Str::isUuid($partId), 404);
        $user = $this->user($request);
        $this->cart->set($user, $partId, '0');

        return response()->json(['data' => $this->cart->view($user)]);
    }

    /** العميل رأى الأسعار الجديدة ووافق عليها (US-14) */
    public function acknowledge(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->cart->acknowledge($user);

        return response()->json(['data' => $this->cart->view($user)]);
    }

    /** ملخص إتمام الطلب للاختيار الحالي (C-11 خطوة 2) */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate(self::choiceRules());

        return response()->json(['data' => $this->checkout->preview($this->user($request), $data)]);
    }

    /** 3.0: تأكيد الطلب بالدفع عند الاستلام أو التسليم */
    public function place(Request $request): JsonResponse
    {
        $data = $request->validate(self::choiceRules() + [
            'fulfillment' => ['required', Rule::in(['delivery', 'pickup'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'accept_returns_policy' => ['accepted'],
        ], ['accept_returns_policy.accepted' => __('orders.policy_required')]);
        $user = $this->user($request);
        $order = $this->checkout->place($user, $data);
        $order->load(OrderPresenter::WITH);

        return response()->json(['data' => OrderPresenter::detail($order, $this->returns->eligibility($order))], 201);
    }

    private static function choiceRules(): array
    {
        return [
            'fulfillment' => ['nullable', Rule::in(['delivery', 'pickup'])],
            'branch_id' => ['nullable', 'uuid'],
            'address_id' => ['nullable', 'uuid'],
            'organization_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
