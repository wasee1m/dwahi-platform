import 'package:customer_app/core/api.dart';
import 'package:customer_app/features/notifications/notifications_screen.dart';
import 'package:customer_app/features/orders/order_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'account_test.dart' show me, screen;
import 'quotes_test.dart' show FakeApi, money;

// S6: M-11 السلة وإتمام الطلب، M-16 تبويب القطع، M-19 تفاصيل الطلب، M-23 الفواتير

Map<String, dynamic> cartItem({Map<String, dynamic>? previous, String? problem}) => {
      'part': {'id': 'p1', 'part_number': '1R-0750', 'name': 'فلتر ديزل', 'brand': 'Caterpillar', 'unit': 'piece', 'is_returnable': true,
        'availability': 'available', 'cover_image_url': null},
      'quantity': '2.00', 'unit_price': money('7.000'), 'previous_price': previous, 'problem': problem, 'line_subtotal': money('14.000'),
    };

Map<String, dynamic> cart({bool changed = false}) => {
      'items': [cartItem(previous: changed ? money('6.500') : null)],
      'totals': {'subtotal': money('14.000'), 'vat_total': money('0.700'), 'total': money('14.700')},
      'has_changes': changed, 'can_checkout': !changed,
    };

Map<String, dynamic> preview({bool blocked = false, String? problem}) => {
      'cart': cart(),
      'fulfillment': 'pickup', 'branch_id': 'b1',
      'totals': {'subtotal': money('14.000'), 'vat_total': money('0.700'), 'delivery_fee': money('0.000'), 'total': money('14.700')},
      'needs_phone_confirmation': false, 'problem': problem,
      'open_orders': {'count': blocked ? 3 : 0, 'limit': 3, 'blocked': blocked},
      'branches': [{'id': 'b1', 'name': 'ورشة صحار', 'address': null, 'can_fulfill': true}],
      'delivery_fees': {'north_batinah': money('5.000'), 'muscat': null},
    };

Map<String, dynamic> order({String status = 'ready_for_pickup', bool canCancel = false, bool canReturn = false}) => {
      'id': 'o1', 'number': 'SO-2026-000010', 'status': status, 'fulfillment': 'pickup', 'account': null, 'from_quote': false,
      'pickup_code': status == 'ready_for_pickup' ? '482913' : null, 'pickup_deadline_at': '2026-11-26T13:00:00Z',
      'branch_detail': {'id': 'b1', 'name': 'ورشة صحار', 'address': null, 'phone': null, 'whatsapp': null},
      'items': [
        {'id': 'l1', 'line_no': 1, 'part_id': 'p1', 'part_number': '1R-0750', 'name': 'فلتر ديزل', 'unit': 'piece', 'quantity': '2.00',
          'unit_price': money('6.500'), 'line_subtotal': money('13.000'), 'line_total': money('13.650'), 'is_returnable': true,
          'warranty_months': null, 'returnable_quantity': '2.00'},
      ],
      'totals': {'subtotal': money('13.000'), 'delivery_fee': money('0.000'), 'vat_total': money('0.650'), 'total': money('13.650'),
        'paid': money('0.000'), 'balance': money('13.650')},
      'timeline': [{'status': 'confirmed', 'note': null, 'at': '2026-11-23T05:00:00Z', 'by': null}],
      'returns': <Map<String, dynamic>>[], 'invoice': null, 'cancel_reason': null,
      'can_cancel': canCancel, 'can_return': canReturn, 'return_until': null,
    };

void main() {
  test('order and invoice notifications open their app screens', () {
    expect(appRoute('/account/orders/01a205b2-6f69-72c4-b4e0-ba6ceea9778f'), '/orders/01a205b2-6f69-72c4-b4e0-ba6ceea9778f');
    expect(appRoute('/account/invoices'), '/account/invoices');
  });

  testWidgets('a changed price blocks checkout until the customer accepts it (US-14)', (tester) async {
    final api = FakeApi({'/me': me(), '/me/cart': cart(changed: true)});
    await tester.pumpWidget(screen(api, const CartScreen()));
    await tester.pumpAndSettle();

    expect(find.textContaining('تغيّر السعر من 6.500 ر.ع. إلى 7.000 ر.ع.'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'إتمام الطلب')).onPressed, isNull);

    api.responses['/me/cart'] = cart();
    await tester.tap(find.text('موافق على الأسعار الجديدة'));
    await tester.pumpAndSettle();
    expect(api.posts.single.$1, '/me/cart/acknowledge');
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'إتمام الطلب')).onPressed, isNotNull);
  });

  testWidgets('changing a quantity sends the new quantity, and minus on one removes the line', (tester) async {
    final api = FakeApi({'/me': me(), '/me/cart': {
      ...cart(),
      'items': [{...cartItem(), 'quantity': '1.00'}],
    }});
    await tester.pumpWidget(screen(api, const CartScreen()));
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.add));
    await tester.pumpAndSettle();
    expect(api.writes.last.$2, '/me/cart/items/p1');
    expect(api.writes.last.$3['quantity'], 2);
    await tester.tap(find.byIcon(Icons.delete_outline));
    await tester.pumpAndSettle();
    expect(api.writes.last.$1, 'DELETE');
  });

  testWidgets('places a pickup order for the selected company only after the returns policy is accepted (3.0)', (tester) async {
    final api = FakeApi({'/me': me(company: true), '/me/addresses': const <Map<String, dynamic>>[], '/me/checkout': preview(),
      'POST /me/orders': {'id': 'o1', 'number': 'SO-2026-000010'}});
    await tester.pumpWidget(screen(api, const CheckoutScreen()));
    await tester.pumpAndSettle();

    expect(find.text('14.700 ر.ع.'), findsOneWidget);
    final place = find.widgetWithText(FilledButton, 'تأكيد الطلب');
    await tester.scrollUntilVisible(place, 200, scrollable: find.byType(Scrollable).first);
    expect(tester.widget<FilledButton>(place).onPressed, isNull);

    await tester.tap(find.byType(Checkbox));
    await tester.pumpAndSettle();
    await tester.tap(place);
    await tester.pumpAndSettle();

    final (path, body) = api.posts.single;
    expect(path, '/me/orders');
    expect(body['fulfillment'], 'pickup');
    expect(body['branch_id'], 'b1');
    expect(body['organization_id'], 'o1');
    expect(body['accept_returns_policy'], true);
    expect(find.text('route /orders/o1'), findsOneWidget);
  });

  testWidgets('the open-order limit stops a new order with the reason (BR-05)', (tester) async {
    final api = FakeApi({'/me': me(), '/me/addresses': const <Map<String, dynamic>>[], '/me/checkout': preview(blocked: true)});
    await tester.pumpWidget(screen(api, const CheckoutScreen()));
    await tester.pumpAndSettle();
    expect(find.textContaining('لديك 3 طلبات مفتوحة غير مدفوعة'), findsOneWidget);
  });

  testWidgets('shows the pickup code large with the deadline and the amount due (US-15, M-19)', (tester) async {
    final api = FakeApi({'/me': me(), '/me/orders/o1': order()});
    await tester.pumpWidget(screen(api, const OrderDetailScreen(id: 'o1')));
    await tester.pumpAndSettle();

    expect(find.text('482913'), findsOneWidget);
    expect(find.textContaining('استلمه قبل'), findsOneWidget);
    expect(find.textContaining('المستحق عند الاستلام: 13.650 ر.ع.'), findsOneWidget);
    expect(find.text('إلغاء الطلب'), findsNothing);
  });

  testWidgets('cancels a confirmed order after a confirmation (BR-13)', (tester) async {
    final api = FakeApi({'/me': me(), '/me/orders/o1': order(status: 'confirmed', canCancel: true)});
    await tester.pumpWidget(screen(api, const OrderDetailScreen(id: 'o1')));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('إلغاء الطلب'), 200, scrollable: find.byType(Scrollable).first);
    await tester.tap(find.text('إلغاء الطلب'));
    await tester.pumpAndSettle();
    expect(api.posts, isEmpty);
    await tester.tap(find.text('تأكيد'));
    await tester.pumpAndSettle();
    expect(api.posts.single.$1, '/me/orders/o1/cancel');
  });

  testWidgets('sends a return for the chosen returnable item with its reason (US-16)', (tester) async {
    final api = FakeApi({'/me': me(), '/me/orders/o1': order(status: 'delivered', canReturn: true)});
    await tester.pumpWidget(screen(api, const ReturnRequestScreen(id: 'o1')));
    await tester.pumpAndSettle();
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'أرسل طلب الإرجاع')).onPressed, isNull);

    await tester.tap(find.byType(Checkbox));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'طلبت رقماً خاطئاً');
    await tester.tap(find.widgetWithText(FilledButton, 'أرسل طلب الإرجاع'));
    await tester.pumpAndSettle();

    final (path, body) = api.posts.single;
    expect(path, '/me/orders/o1/returns');
    expect(body['items'], [{'order_item_id': 'l1', 'quantity': 1}]);
    expect(body['reason'], 'طلبت رقماً خاطئاً');
  });

  testWidgets('a refused return shows the server reason', (tester) async {
    final api = FakeApi({'/me': me(), '/me/orders/o1': order(status: 'delivered', canReturn: true),
      'POST /me/orders/o1/returns': ApiException('RETURN_WINDOW_CLOSED', 422, message: 'انتهت مدة الإرجاع.')});
    await tester.pumpWidget(screen(api, const ReturnRequestScreen(id: 'o1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byType(Checkbox));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'سبب');
    await tester.tap(find.widgetWithText(FilledButton, 'أرسل طلب الإرجاع'));
    await tester.pumpAndSettle();
    expect(find.text('انتهت مدة الإرجاع.'), findsOneWidget);
  });

  testWidgets('lists invoices with the overdue mark and their credit notes (US-22, M-23)', (tester) async {
    final api = FakeApi({'/me': me(), '/me/invoices': [
      {'id': 'i1', 'number': 'INV-2026-000001', 'status': 'issued', 'overdue': true, 'source': {'type': 'quotation', 'id': null, 'number': 'QT-1'},
        'issue_date': '2026-11-01', 'due_date': '2026-11-10', 'total': money('997.500'), 'paid': money('0.000'), 'balance': money('997.500'),
        'pdf_url': null, 'account': null, 'customer': null,
        'credit_notes': [{'id': 'c1', 'number': 'CN-2026-000001', 'reason': 'x', 'total': money('1.000'), 'issued_at': null, 'pdf_url': null, 'invoice': null}]},
    ]});
    await tester.pumpWidget(screen(api, const InvoicesScreen()));
    await tester.pumpAndSettle();
    expect(find.text('متأخرة'), findsOneWidget);
    expect(find.textContaining('CN-2026-000001'), findsOneWidget);
    expect(find.text('997.500 ر.ع.'), findsOneWidget);
  });
}
