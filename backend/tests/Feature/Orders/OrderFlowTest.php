<?php

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Orders\Models\SalesOrder;
use App\Modules\Parts\Models\Part;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// SM-3a، SM-3b، SM-6، FR-ORD-01..05، FR-PAY-02، FR-PAY-03، FR-PAY-06، BR-01، BR-05، BR-12، BR-13، BR-18، J-01

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

function partNo(string $number): Part
{
    return Part::query()->where('part_number', $number)->firstOrFail();
}

function reserved(string $number): string
{
    return (string) DB::table('stock_levels')->where('part_id', partNo($number)->id)->value('qty_reserved');
}

function onHand(string $number): string
{
    return (string) DB::table('stock_levels')->where('part_id', partNo($number)->id)->value('qty_on_hand');
}

function putSetting(string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
}

function sohBranch(): string
{
    return (string) DB::table('branches')->where('code', 'SOH')->value('id');
}

/** سلة فيها بنود ثم طلب استلام من فرع صحار */
function pickupOrder($test, string $token, array $lines = ['1R-0750' => 2]): array
{
    foreach ($lines as $number => $qty) {
        asUser($test, $token)->putJson(api().'/me/cart/items/'.partNo($number)->id, ['quantity' => $qty])->assertOk();
    }

    return asUser($test, $token)->postJson(api().'/me/orders', [
        'fulfillment' => 'pickup', 'branch_id' => sohBranch(), 'organization_id' => null, 'accept_returns_policy' => true,
    ])->assertCreated()->json('data');
}

/** يجهّز موظف القطع الطلب ويسلمه برمز الاستلام ودفعة نقدية */
function handOver($test, string $staff, string $customer, array $order): array
{
    asUser($test, $staff)->postJson(api()."/admin/orders/{$order['id']}/process")->assertOk();
    asUser($test, $staff)->postJson(api()."/admin/orders/{$order['id']}/ready")->assertOk();
    $code = asUser($test, $customer)->getJson(api()."/me/orders/{$order['id']}")->json('data.pickup_code');

    return asUser($test, $staff)->postJson(api()."/admin/orders/{$order['id']}/deliver", [
        'code' => $code, 'payment' => ['method' => 'cash', 'amount' => $order['totals']['total']['amount']],
    ])->assertOk()->json('data');
}

it('checks the cart on every open, asks to confirm a new price, and reserves stock on confirmation (US-14, 3.0)', function () {
    staffUser(['parts_staff'], 'parts@dwahi.test');
    $c = customerToken($this);
    $fuel = partNo('1R-0750');

    $cart = asUser($this, $c)->putJson(api()."/me/cart/items/{$fuel->id}", ['quantity' => 2])->assertOk()->json('data');
    // BR-01: 2 × 6.500 = 13.000، والضريبة 5% = 0.650
    expect($cart['totals']['subtotal']['amount'])->toBe('13.000')->and($cart['totals']['vat_total']['amount'])->toBe('0.650')
        ->and($cart['can_checkout'])->toBeTrue();

    // السعر تغير بعد الإضافة: تنبيه وتأكيد قبل الإتمام
    $fuel->update(['price' => '7.000']);
    $cart = asUser($this, $c)->getJson(api().'/me/cart')->json('data');
    expect($cart['items'][0]['previous_price']['amount'])->toBe('6.500')->and($cart['can_checkout'])->toBeFalse();
    asUser($this, $c)->postJson(api().'/me/orders', ['fulfillment' => 'pickup', 'branch_id' => sohBranch(), 'accept_returns_policy' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'CART_CHANGED');
    asUser($this, $c)->postJson(api().'/me/cart/acknowledge')->assertOk()->assertJsonPath('data.can_checkout', true);

    // كمية أكبر من المتاح لا تُطلب
    asUser($this, $c)->putJson(api()."/me/cart/items/{$fuel->id}", ['quantity' => 50])->assertOk()->assertJsonPath('data.items.0.problem', 'insufficient');
    asUser($this, $c)->putJson(api()."/me/cart/items/{$fuel->id}", ['quantity' => 2])->assertOk();

    $preview = asUser($this, $c)->getJson(api().'/me/checkout?fulfillment=pickup&branch_id='.sohBranch())->assertOk()->json('data');
    expect($preview['totals']['total']['amount'])->toBe('14.700')->and($preview['branches'][0]['can_fulfill'])->toBeTrue()
        ->and($preview['problem'])->toBeNull();

    // سياسة الإرجاع شرط
    asUser($this, $c)->postJson(api().'/me/orders', ['fulfillment' => 'pickup', 'branch_id' => sohBranch()])->assertStatus(422);
    $order = asUser($this, $c)->postJson(api().'/me/orders', ['fulfillment' => 'pickup', 'branch_id' => sohBranch(), 'accept_returns_policy' => true])
        ->assertCreated()->json('data');

    expect($order['status'])->toBe('confirmed')->and($order['number'])->toMatch('/^SO-\d{4}-\d{6}$/')
        ->and($order['totals']['total']['amount'])->toBe('14.700')->and($order['can_cancel'])->toBeTrue()
        ->and(reserved('1R-0750'))->toBe('2.00')
        ->and(notified('N-05'))->toBe(1)->and(notified('N-23'))->toBe(1)
        ->and(asUser($this, $c)->getJson(api().'/me/cart')->json('data.items'))->toBe([])
        // لا فاتورة قبل التسليم
        ->and(Invoice::query()->count())->toBe(0);
});

it('stops a fourth open unpaid order from the cart (BR-05)', function () {
    putSetting('order.max_open_unpaid_per_customer', 2);
    $c = customerToken($this);
    pickupOrder($this, $c);
    pickupOrder($this, $c);
    asUser($this, $c)->putJson(api().'/me/cart/items/'.partNo('1R-0750')->id, ['quantity' => 1])->assertOk();
    expect(asUser($this, $c)->getJson(api().'/me/checkout')->json('data.open_orders'))->toBe(['count' => 2, 'limit' => 2, 'blocked' => true]);
    asUser($this, $c)->postJson(api().'/me/orders', ['fulfillment' => 'pickup', 'branch_id' => sohBranch(), 'accept_returns_policy' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'ORDER_LIMIT');
});

it('hands over at the branch only with the right code and the payment, then issues a paid invoice (US-15, US-35, BR-18)', function () {
    $c = customerToken($this);
    $order = pickupOrder($this, $c, ['1R-0750' => 2, '2XL-KIT' => 1]);
    $s = staffToken($this, 'parts_staff');

    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/ready")->assertStatus(409); // يلزم التجهيز أولاً
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/process")->assertOk()->assertJsonPath('data.status', 'processing');
    // العميل لا يلغي بعد بدء التجهيز (BR-13)
    asUser($this, $c)->postJson(api()."/me/orders/{$order['id']}/cancel")->assertStatus(409);

    $ready = asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/ready")->assertOk()->json('data');
    expect($ready['status'])->toBe('ready_for_pickup')->and($ready['pickup_code'])->toBeNull() // الموظف لا يرى الرمز
        ->and($ready['pickup_deadline_at'])->not->toBeNull()->and(notified('N-06'))->toBe(1);
    $code = asUser($this, $c)->getJson(api()."/me/orders/{$order['id']}")->json('data.pickup_code');
    expect($code)->toMatch('/^\d{6}$/');

    $total = $order['totals']['total']['amount']; // 13.000 + 1450.000 + ضريبة 5%
    expect($total)->toBe('1536.150');
    $wrong = $code === '000000' ? '111111' : '000000';
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/deliver", ['code' => $wrong, 'payment' => ['method' => 'cash', 'amount' => $total]])
        ->assertStatus(422)->assertJsonPath('error.code', 'PICKUP_CODE_INVALID');
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/deliver", ['code' => $code])
        ->assertStatus(422)->assertJsonPath('error.code', 'PAYMENT_REQUIRED');
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/deliver", ['code' => $code, 'payment' => ['method' => 'cash', 'amount' => '100']])
        ->assertStatus(422);
    // البطاقة تحتاج رقم إيصال نقاط البيع
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/deliver", ['code' => $code, 'payment' => ['method' => 'pos_card', 'amount' => $total]])
        ->assertStatus(422);

    $done = asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/deliver", [
        'code' => $code, 'payment' => ['method' => 'pos_card', 'amount' => $total, 'reference_text' => 'POS-7781'],
    ])->assertOk()->json('data');

    expect($done['status'])->toBe('delivered')->and($done['totals']['balance']['amount'])->toBe('0.000')
        ->and(reserved('1R-0750'))->toBe('0.00')->and(onHand('1R-0750'))->toBe('10.00')->and(onHand('2XL-KIT'))->toBe('1.00')
        ->and($done['invoice']['number'])->toMatch('/^INV-\d{4}-\d{6}$/')->and($done['invoice']['status'])->toBe('paid');

    $inv = Invoice::query()->with('items')->firstOrFail();
    expect($inv->total)->toBe('1536.150')->and($inv->amount_paid)->toBe('1536.150')
        // FR-CAT-07: تاريخ انتهاء الضمان من تاريخ الفاتورة
        ->and($inv->items->firstWhere('warranty_months', 12)->warranty_until->toDateString())->toBe(now('Asia/Muscat')->addMonths(12)->toDateString())
        ->and(notified('N-16'))->toBe(1);

    // C-18: العميل يرى فاتورته وينزّلها
    $list = asUser($this, $c)->getJson(api().'/me/invoices')->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['source']['type'])->toBe('sales_order')->and($list[0]['pdf_url'])->toContain('signature=');
    asUser($this, $c)->get(api()."/me/invoices/{$inv->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // عميل آخر لا يرى الطلب ولا الفاتورة
    $other = customerToken($this, '91234568');
    asUser($this, $other)->getJson(api()."/me/orders/{$order['id']}")->assertNotFound();
    asUser($this, $other)->get(api()."/me/invoices/{$inv->id}/pdf")->assertNotFound();
    expect(asUser($this, $other)->getJson(api().'/me/invoices')->json('data'))->toBe([]);
});

it('locks the pickup code after five wrong tries', function () {
    $c = customerToken($this);
    $order = pickupOrder($this, $c);
    $s = staffToken($this, 'parts_staff');
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/process")->assertOk();
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/ready")->assertOk();
    $code = asUser($this, $c)->getJson(api()."/me/orders/{$order['id']}")->json('data.pickup_code');
    $wrong = $code === '000000' ? '111111' : '000000';
    foreach (range(1, 5) as $i) {
        asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/deliver", ['code' => $wrong])->assertStatus(422);
    }
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/deliver", ['code' => $code])
        ->assertStatus(429)->assertJsonPath('error.code', 'PICKUP_CODE_LOCKED');
});

it('delivers to a saved address with the governorate fee and a phone confirmation above the limit (FR-ORD-02, BR-18)', function () {
    putSetting('delivery.fee_by_governorate', ['muscat' => null, 'north_batinah' => '5.000']);
    putSetting('order.phone_confirm_above_amount', '10.000');
    $c = customerToken($this);
    $address = asUser($this, $c)->postJson(api().'/me/addresses', [
        'label' => 'موقع صحار', 'kind' => 'delivery', 'governorate' => 'north_batinah', 'wilayat' => 'صحار', 'organization_id' => null,
        'contact_name' => 'سالم', 'contact_phone' => '99112233',
    ])->assertCreated()->json('data.id');
    $muscat = asUser($this, $c)->postJson(api().'/me/addresses', [
        'label' => 'مسقط', 'kind' => 'delivery', 'governorate' => 'muscat', 'wilayat' => 'بوشر', 'organization_id' => null,
    ])->assertCreated()->json('data.id');
    asUser($this, $c)->putJson(api().'/me/cart/items/'.partNo('1R-0750')->id, ['quantity' => 2])->assertOk();

    // لا توصيل لمحافظة بلا رسوم
    expect(asUser($this, $c)->getJson(api()."/me/checkout?fulfillment=delivery&address_id={$muscat}")->json('data.problem'))->toBe('no_delivery_to_governorate');
    $p = asUser($this, $c)->getJson(api()."/me/checkout?fulfillment=delivery&address_id={$address}")->json('data');
    // 13.000 + 5.000 رسوم + ضريبة (0.650 + 0.250)
    expect($p['totals']['total']['amount'])->toBe('18.900')->and($p['needs_phone_confirmation'])->toBeTrue();

    $order = asUser($this, $c)->postJson(api().'/me/orders', ['fulfillment' => 'delivery', 'address_id' => $address, 'organization_id' => null, 'accept_returns_policy' => true])
        ->assertCreated()->json('data');
    expect($order['delivery_address']['contact_name'])->toBe('سالم')->and($order['totals']['delivery_fee']['amount'])->toBe('5.000');

    $s = staffToken($this, 'parts_staff');
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/process")->assertStatus(409)->assertJsonPath('error.code', 'PHONE_CONFIRMATION_REQUIRED');
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/phone", ['confirmed' => true, 'reason' => 'أكد العميل الموعد'])->assertOk();
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/process")->assertOk();
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/ready")->assertStatus(409); // ليس استلاماً
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/dispatch")->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
    // 3.10: تعذر التسليم يعيده للتجهيز (N-26)
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/delivery-failed", ['reason' => 'العميل غير موجود'])->assertOk()->assertJsonPath('data.status', 'processing');
    expect(notified('N-26'))->toBe(1);
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/dispatch")->assertOk();

    // إثبات التسليم إلزامي
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/deliver", ['payment' => ['method' => 'cash', 'amount' => '18.900']])->assertStatus(422);
    $proof = asUser($this, $s)->post(api().'/admin/staff-uploads', ['file' => UploadedFile::fake()->image('handover.jpg')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    $done = asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/deliver", ['proof_id' => $proof, 'payment' => ['method' => 'cash', 'amount' => '18.900']])
        ->assertOk()->json('data');
    expect($done['status'])->toBe('delivered');
    $inv = Invoice::query()->with('items')->firstOrFail();
    // رسوم التوصيل سطر مستقل خاضع للضريبة (BR-01)
    expect($inv->items->last()->item_type)->toBe('delivery')->and($inv->items->last()->line_vat)->toBe('0.250')->and($inv->total)->toBe('18.900');

    // الخط الزمني يحفظ المحاولة الفاشلة
    $timeline = collect($done['timeline'])->pluck('status')->all();
    expect($timeline)->toBe(['confirmed', 'phone_confirmed', 'processing', 'out_for_delivery', 'delivery_failed', 'out_for_delivery', 'delivered']);
});

it('lets the customer cancel before preparing and the staff after with a reason, releasing stock (BR-13, 3.6, 3.7)', function () {
    $c = customerToken($this);
    $a = pickupOrder($this, $c);
    asUser($this, $c)->postJson(api()."/me/orders/{$a['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(reserved('1R-0750'))->toBe('0.00');

    $b = pickupOrder($this, $c);
    $s = staffToken($this, 'parts_staff');
    asUser($this, $s)->postJson(api()."/admin/orders/{$b['id']}/process")->assertOk();
    asUser($this, $s)->postJson(api()."/admin/orders/{$b['id']}/cancel")->assertStatus(422); // السبب إلزامي
    asUser($this, $s)->postJson(api()."/admin/orders/{$b['id']}/cancel", ['reason' => 'القطعة تالفة في المخزن'])->assertOk();
    expect(reserved('1R-0750'))->toBe('0.00')->and(notified('N-07'))->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'order.cancelled')->count())->toBe(2);
});

it('reminds a day before the pickup deadline, then cancels and counts the no-show (J-01, 3.13)', function () {
    $c = customerToken($this);
    $order = pickupOrder($this, $c);
    $s = staffToken($this, 'parts_staff');
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/process")->assertOk();
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/ready")->assertOk();
    $deadline = SalesOrder::query()->findOrFail($order['id'])->pickup_deadline_at;

    $this->travelTo($deadline->copy()->subHours(30));
    $this->artisan('orders:run expire')->assertSuccessful();
    expect(notified('N-07'))->toBe(0);

    $this->travelTo($deadline->copy()->subHours(20));
    $this->artisan('orders:run expire')->assertSuccessful();
    $this->artisan('orders:run expire')->assertSuccessful(); // متكرر الأمان
    expect(notified('N-07'))->toBe(1);

    $this->travelTo($deadline->copy()->addMinute());
    $this->artisan('orders:run expire')->assertSuccessful();
    $o = SalesOrder::query()->findOrFail($order['id']);
    expect($o->status)->toBe('cancelled')->and(reserved('1R-0750'))->toBe('0.00')->and(notified('N-07'))->toBe(2)
        ->and(User::query()->find($o->customer_user_id)->pickup_no_show_count)->toBe(1);
});

it('returns only returnable items within the window, restocks good ones, and refunds with a credit note (US-16, BR-12, SM-3b, FR-PAY-06)', function () {
    $c = customerToken($this);
    $s = staffToken($this, 'parts_staff');
    $order = pickupOrder($this, $c, ['1R-0750' => 2, '2XL-KIT' => 1]);
    $done = handOver($this, $s, $c, $order);
    [$fuelLine, $kitLine] = [$done['items'][0], $done['items'][1]];
    expect($done['can_return'])->toBeTrue()->and($fuelLine['returnable_quantity'])->toBe('2.00')->and($kitLine['returnable_quantity'])->toBe('0.00');

    // نظام القلاب لا يُرجع، ولا أكثر من المسلَّم
    asUser($this, $c)->postJson(api()."/me/orders/{$order['id']}/returns", ['reason' => 'لا يناسب', 'items' => [['order_item_id' => $kitLine['id'], 'quantity' => 1]]])
        ->assertStatus(422);
    asUser($this, $c)->postJson(api()."/me/orders/{$order['id']}/returns", ['reason' => 'لا يناسب', 'items' => [['order_item_id' => $fuelLine['id'], 'quantity' => 3]]])
        ->assertStatus(422);
    $photo = asUser($this, $c)->post(api().'/me/uploads', ['file' => UploadedFile::fake()->image('box.jpg')], ['Accept' => 'application/json'])->json('data.id');
    $after = asUser($this, $c)->postJson(api()."/me/orders/{$order['id']}/returns", [
        'reason' => 'طلبت رقماً خاطئاً', 'items' => [['order_item_id' => $fuelLine['id'], 'quantity' => 1]], 'attachment_ids' => [$photo],
    ])->assertCreated()->json('data');
    expect($after['returns'][0]['status'])->toBe('requested')->and($after['items'][0]['returnable_quantity'])->toBe('1.00')
        ->and(notified('N-27'))->toBe(1);

    $rr = $after['returns'][0]['id'];
    asUser($this, $s)->postJson(api()."/admin/returns/{$rr}/reject")->assertStatus(422); // السبب إلزامي
    asUser($this, $s)->postJson(api()."/admin/returns/{$rr}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    asUser($this, $s)->postJson(api()."/admin/returns/{$rr}/refund", ['method' => 'cash'])->assertForbidden(); // الاسترداد للمحاسب
    asUser($this, $s)->postJson(api()."/admin/returns/{$rr}/receive", ['restock' => [$fuelLine['id'] => true]])->assertOk();
    expect(onHand('1R-0750'))->toBe('11.00');

    $acc = staffToken($this, 'accountant');
    $pending = asUser($this, $acc)->getJson(api().'/admin/refunds')->assertOk()->json('data.pending_returns');
    // 6.500 + ضريبة 0.325
    expect($pending[0]['amount']['amount'])->toBe('6.825');
    asUser($this, $acc)->postJson(api()."/admin/returns/{$rr}/refund", ['method' => 'cash'])->assertOk()->assertJsonPath('data.status', 'refunded');

    $payment = Payment::query()->firstOrFail();
    expect($payment->status)->toBe('partially_refunded')->and($payment->refunded_amount)->toBe('6.825');
    $note = DB::table('credit_notes')->first();
    expect($note->number)->toMatch('/^CN-\d{4}-\d{6}$/')->and($note->total)->toBe('6.825')->and($note->vat_total)->toBe('0.325');

    // لا استرداد أكبر من المتبقي من الدفعة
    asUser($this, $acc)->postJson(api()."/admin/payments/{$payment->id}/refunds", ['amount' => '9999', 'method' => 'cash', 'reason' => 'خطأ'])->assertStatus(422);

    // بعد المدة لا إرجاع
    SalesOrder::query()->whereKey($order['id'])->update(['delivered_at' => now()->subDays(15)]);
    expect(asUser($this, $c)->getJson(api()."/me/orders/{$order['id']}")->json('data.can_return'))->toBeFalse();
});

it('turns an accepted parts quote into an order at quoted prices, outside the open-order limit (3.14, BR-17)', function () {
    putSetting('order.max_open_unpaid_per_customer', 1);
    $c = customerToken($this);
    pickupOrder($this, $c); // بلغ الحد
    $sales = staffToken($this, 'sales');
    $rfq = asUser($this, $c)->postJson(api().'/me/rfqs', ['type' => 'parts', 'items' => [['part_id' => partNo('1R-0750')->id, 'quantity' => 5]]])
        ->assertCreated()->json('data');
    $draft = asUser($this, $sales)->postJson(api()."/admin/rfqs/{$rfq['id']}/quotations")->assertCreated()->json('data');
    $lines = $draft['items'];
    $lines[0]['unit_price'] = '6.000';
    asUser($this, $sales)->putJson(api()."/admin/quotations/{$draft['id']}", ['items' => $lines])->assertOk();
    asUser($this, $sales)->postJson(api()."/admin/quotations/{$draft['id']}/send")->assertOk();
    asUser($this, $c)->postJson(api()."/me/quotations/{$draft['id']}/accept")->assertOk();

    $o = SalesOrder::query()->where('rfq_id', $rfq['id'])->with('items')->firstOrFail();
    expect($o->status)->toBe('confirmed')->and($o->items[0]->unit_price)->toBe('6.000')->and($o->total)->toBe('31.500')
        ->and(reserved('1R-0750'))->toBe('7.00');
    // مرة واحدة فقط
    asUser($this, staffToken($this, 'parts_staff'))->postJson(api()."/admin/rfqs/{$rfq['id']}/sales-order")->assertStatus(409);
});

it('drafts an invoice from an accepted machine quote for the accountant to issue and collect in parts (6.1, US-38)', function () {
    $c = customerToken($this, '91234567', 'شركة الاختبار');
    $listing = DB::table('equipment_listings')->where('slug', 'volvo-bl71-2012')->first();
    $rfq = asUser($this, $c)->postJson(api().'/me/rfqs', ['type' => 'equipment', 'items' => [['listing_id' => $listing->id, 'quantity' => 1]]])
        ->assertCreated()->json('data');
    $sales = staffToken($this, 'sales');
    $draft = asUser($this, $sales)->postJson(api()."/admin/rfqs/{$rfq['id']}/quotations")->assertCreated()->json('data');
    asUser($this, $sales)->postJson(api()."/admin/quotations/{$draft['id']}/send")->assertOk();
    asUser($this, $c)->postJson(api()."/me/quotations/{$draft['id']}/accept")->assertOk();

    $inv = Invoice::query()->firstOrFail();
    expect($inv->status)->toBe('draft')->and($inv->number)->toBeNull()->and($inv->buyer_snapshot['name'])->toBe('شركة الاختبار');
    // المسودة لا تظهر للعميل
    expect(asUser($this, $c)->getJson(api().'/me/invoices')->json('data'))->toBe([]);

    $acc = staffToken($this, 'accountant');
    asUser($this, $acc)->getJson(api().'/admin/invoices?status=draft')->assertOk()->assertJsonPath('counts.draft', 1);
    asUser($this, $acc)->postJson(api()."/admin/invoices/{$inv->id}/issue", ['due_date' => now('Asia/Muscat')->subDay()->toDateString()])->assertStatus(422);
    $due = now('Asia/Muscat')->addDays(10)->toDateString();
    $issued = asUser($this, $acc)->postJson(api()."/admin/invoices/{$inv->id}/issue", ['due_date' => $due])->assertOk()->json('data');
    expect($issued['status'])->toBe('issued')->and($issued['number'])->toMatch('/^INV-/')->and($issued['due_date'])->toBe($due);

    // تحويل جزئي برقم التحويل
    asUser($this, $acc)->postJson(api()."/admin/invoices/{$inv->id}/payments", ['method' => 'bank_transfer', 'amount' => '5000'])->assertStatus(422);
    $partial = asUser($this, $acc)->postJson(api()."/admin/invoices/{$inv->id}/payments", ['method' => 'bank_transfer', 'amount' => '5000', 'reference_text' => 'TRX-1'])
        ->assertOk()->json('data');
    expect($partial['status'])->toBe('partially_paid')->and($partial['balance']['amount'])->toBe(bcsub($issued['total']['amount'], '5000', 3));
    // لا إلغاء بعد دفعة
    asUser($this, $acc)->postJson(api()."/admin/invoices/{$inv->id}/void", ['reason' => 'خطأ'])->assertStatus(409);
    asUser($this, $acc)->postJson(api()."/admin/invoices/{$inv->id}/payments", ['method' => 'bank_transfer', 'amount' => '999999', 'reference_text' => 'TRX-2'])
        ->assertStatus(422);
    $paid = asUser($this, $acc)->postJson(api()."/admin/invoices/{$inv->id}/payments", [
        'method' => 'bank_transfer', 'amount' => $partial['balance']['amount'], 'reference_text' => 'TRX-2',
    ])->assertOk()->json('data');
    expect($paid['status'])->toBe('paid')->and(DB::table('equipment_listings')->where('id', $listing->id)->value('status'))->toBe('sold');

    // الفاتورة للشركة: عضوها يراها (US-22)، والموظف بلا صلاحية المالية لا
    expect(asUser($this, $c)->getJson(api().'/me/invoices')->json('data.0.status'))->toBe('paid');
    asUser($this, staffToken($this, 'parts_staff'))->getJson(api().'/admin/invoices')->assertForbidden();
});

it('voids an unpaid invoice with a full credit note, and reports the day collection by branch, method and staff (6.5, US-38)', function () {
    $c = customerToken($this);
    $s = staffToken($this, 'parts_staff');
    handOver($this, $s, $c, pickupOrder($this, $c));
    handOver($this, $s, $c, pickupOrder($this, $c, ['1R-1808' => 1]));

    $acc = staffToken($this, 'accountant');
    $report = asUser($this, $acc)->getJson(api().'/admin/finance/collection')->assertOk()->json('data');
    // 13.650 + 8.190
    expect($report['total']['amount'])->toBe('21.840')->and($report['branches'][0]['by_method'][0])->toBe(['method' => 'cash', 'count' => 2, 'total' => ['amount' => '21.840', 'currency' => 'OMR']])
        ->and($report['branches'][0]['by_staff'][0]['count'])->toBe(2);

    // الفاتورة المدفوعة لا تُلغى، والصادرة بلا دفعة تُلغى بإشعار دائن بقيمتها الكاملة
    $paidInvoice = Invoice::query()->where('status', 'paid')->firstOrFail();
    asUser($this, $acc)->postJson(api()."/admin/invoices/{$paidInvoice->id}/void", ['reason' => 'خطأ'])->assertStatus(409);
    $open = SalesOrder::query()->findOrFail(pickupOrder($this, $c)['id']);
    $draft = app(InvoiceService::class)->draftFromOrder($open);
    asUser($this, $acc)->postJson(api()."/admin/invoices/{$draft->id}/issue")->assertOk()->assertJsonPath('data.status', 'issued');
    asUser($this, $acc)->postJson(api()."/admin/invoices/{$draft->id}/void")->assertStatus(422);
    $void = asUser($this, $acc)->postJson(api()."/admin/invoices/{$draft->id}/void", ['reason' => 'صدرت قبل التسليم خطأً'])->assertOk()->json('data');
    expect($void['status'])->toBe('void')->and($void['credit_notes'][0]['total']['amount'])->toBe($void['total']['amount']);
    // تسلسل الفواتير متصل بلا فجوات (BR-14)
    $numbers = Invoice::query()->whereNotNull('number')->orderBy('number')->pluck('number')->map(fn ($n) => (int) substr($n, -6))->all();
    expect($numbers)->toBe(range(1, count($numbers)));
});

it('keeps branch-restricted parts staff to their branch orders', function () {
    $c = customerToken($this);
    $order = pickupOrder($this, $c);
    $s = staffToken($this, 'parts_staff');
    $nizwa = Branch::query()->create([
        'code' => 'NZW', 'name_ar' => 'نزوى', 'name_en' => 'Nizwa', 'governorate' => 'dakhiliyah', 'wilayat' => 'نزوى',
        'location' => Branch::point(22.93, 57.53), 'working_hours' => [], 'covered_governorates' => ['dakhiliyah'],
    ]);
    DB::table('staff_profiles')->where('user_id', User::query()->where('email', 'parts_staff@dwahi.test')->value('id'))
        ->update(['branch_id' => $nizwa->id, 'restrict_to_branch' => true]);

    asUser($this, $s)->getJson(api()."/admin/orders/{$order['id']}")->assertNotFound();
    asUser($this, $s)->postJson(api()."/admin/orders/{$order['id']}/process")->assertNotFound();
    expect(asUser($this, $s)->getJson(api().'/admin/orders')->json('data'))->toBe([]);
    // المدير يرى كل الفروع
    $admin = staffToken($this, 'admin');
    expect(asUser($this, $admin)->getJson(api().'/admin/orders')->json('data'))->toHaveCount(1);
});

it('blocks account deletion while a parts order is open, and shows orders and invoices in the customer profile (BR-16, A-12)', function () {
    $c = customerToken($this);
    $order = pickupOrder($this, $c);
    $check = asUser($this, $c)->getJson(api().'/me/deletion')->assertOk()->json('data');
    expect($check['can_delete'])->toBeFalse()->and(collect($check['requests'])->pluck('number'))->toContain($order['number']);

    $customerId = SalesOrder::query()->findOrFail($order['id'])->customer_user_id;
    $profile = asUser($this, staffToken($this, 'sales'))->getJson(api()."/admin/customers/{$customerId}")->assertOk()->json('data');
    expect($profile['orders'][0]['number'])->toBe($order['number'])->and($profile['invoices'])->toBe([]);
});
