import 'package:customer_app/features/notifications/notifications_screen.dart';
import 'package:customer_app/features/service/service_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'account_test.dart' show me, screen;
import 'quotes_test.dart' show FakeApi, money;

// S7: M-15 طلب صيانة جديد، وتبويب الصيانة وتفاصيله (M-16)

Map<String, dynamic> meta() => {
      'inspection_fee': money('15.000'), 'sla': {'normal_working_hours': 4, 'urgent_hours': 1},
      'expected': {'normal': '2026-11-24T10:00:00Z', 'urgent': '2026-11-23T07:00:00Z'},
      'components': ['diesel_engine', 'hydraulic_pump', 'hydraulic_cylinder', 'transmission', 'other'],
      'workshop': {'name': 'ورشة صحار', 'address': null, 'phone': null, 'whatsapp': null, 'working_hours': <String, dynamic>{}},
    };

Map<String, dynamic> request({String status = 'submitted', String key = 'submitted', bool canCancel = true, Map<String, dynamic>? estimate}) => {
      'id': 's1', 'number': 'SR-2026-000001', 'status': status, 'customer_status': {'key': key, 'date': null, 'reason': null},
      'urgency': 'normal', 'source': 'customer', 'equipment': null, 'component_type': 'hydraulic_cylinder', 'component_details': 'سلندر البوم',
      'preferred_date': null, 'received': false, 'account': null, 'customer': null, 'branch': null, 'sla_due_at': null, 'created_at': '2026-11-23T05:00:00Z',
      'problem_description': 'تسريب زيت', 'inspection_fee': money('15.000'), 'route': null, 'triaged_at': null, 'decline_reason': null, 'cancel_reason': null,
      'branch_detail': null, 'attachments': <Map<String, dynamic>>[], 'intake': null, 'estimate': estimate, 'work_orders': <Map<String, dynamic>>[], 'can_cancel': canCancel,
    };

void main() {
  test('service notifications open the request in the app', () {
    expect(appRoute('/account/service/01a205b2-6f69-72c4-b4e0-ba6ceea9778f'), '/service/01a205b2-6f69-72c4-b4e0-ba6ceea9778f');
  });

  testWidgets('sends a component request only after the inspection fee is acknowledged (US-17, BR-11)', (tester) async {
    final api = FakeApi({'/me': me(), '/me/fleet': const <Map<String, dynamic>>[], '/me/service-requests/meta': meta(),
      'POST /me/service-requests': {'id': 's1', 'number': 'SR-2026-000001'}});
    // المعالج طويل: شاشة هاتف كبيرة فتظهر الأزرار دون تمرير
    tester.view.physicalSize = const Size(1080, 2600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(screen(api, const NewServiceScreen()));
    await tester.pumpAndSettle();

    await tester.tap(find.text('مكوّن منفصل'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('التالي').hitTestable());
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'وصف العطل'), 'تسريب زيت من السلندر');
    await tester.pumpAndSettle();
    await tester.tap(find.text('التالي').hitTestable());
    await tester.pumpAndSettle();
    await tester.tap(find.text('عاجل'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('التالي').hitTestable());
    await tester.pumpAndSettle();

    expect(find.text('رسوم الفحص: 15.000 ر.ع.'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'أرسل الطلب').hitTestable()).onPressed, isNull);
    await tester.tap(find.byType(Checkbox));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أرسل الطلب').hitTestable());
    await tester.pumpAndSettle();

    final (path, body) = api.posts.single;
    expect(path, '/me/service-requests');
    expect(body['component_type'], 'hydraulic_cylinder');
    expect(body['customer_equipment_id'], isNull);
    expect(body['urgency'], 'urgent');
    expect(body['fees_acknowledged'], true);
    expect(find.text('route /service/s1'), findsOneWidget);
  });

  testWidgets('lists requests with the customer status wording (SM-4)', (tester) async {
    final api = FakeApi({'/me': me(), '/me/service-requests': [request(status: 'in_service', key: 'awaiting_dropoff')]});
    await tester.pumpWidget(screen(api, const Scaffold(body: ServiceLists())));
    await tester.pumpAndSettle();
    expect(find.text('بانتظار إحضار المعدة إلى الورشة'), findsOneWidget);
  });

  testWidgets('opens the estimate when it awaits approval, and cancels after a confirmation (US-18, 4.4)', (tester) async {
    final api = FakeApi({'/me': me(), '/me/service-requests/s1': request(status: 'awaiting_approval', key: 'awaiting_approval',
        estimate: {'rfq_id': 'r9', 'rfq_status': 'quoted', 'quotation': {'id': 'q1', 'number': 'QT-2026-000009-v1', 'status': 'sent', 'total': money('126.000')}})});
    await tester.pumpWidget(screen(api, const ServiceDetailScreen(id: 's1')));
    await tester.pumpAndSettle();
    expect(find.textContaining('126.000 ر.ع.'), findsOneWidget);

    await tester.tap(find.text('إلغاء الطلب'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تأكيد'));
    await tester.pumpAndSettle();
    expect(api.posts.single.$1, '/me/service-requests/s1/cancel');

    await tester.tap(find.text('راجع التقدير'));
    await tester.pumpAndSettle();
    expect(find.text('route /requests/r9'), findsOneWidget);
  });
}
