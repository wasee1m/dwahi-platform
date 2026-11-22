import 'package:customer_app/core/api.dart';
import 'package:customer_app/core/format.dart';
import 'package:customer_app/features/catalog/catalog_screens.dart';
import 'package:customer_app/features/notifications/notifications_screen.dart';
import 'package:customer_app/features/quotes/messages_screen.dart';
import 'package:customer_app/features/quotes/quote_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

// M-07، M-18، M-24 مع API وهمي: الشاشات تعرض ما يعيده الخادم وترسل ما يتوقعه العقد

class FakeApi extends Api {
  FakeApi(this.responses) : super(TokenStore(), () => 'ar');
  final Map<String, Object> responses;
  final posts = <(String, Map<String, dynamic>)>[];

  @override
  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) async => (responses[path] ?? <String, dynamic>{}) as Map<String, dynamic>;

  @override
  Future<ApiPage> page(String path, {Map<String, dynamic>? query, bool auth = true}) async =>
      ApiPage(((responses[path] ?? const <Map<String, dynamic>>[]) as List).cast<Map<String, dynamic>>(), {'next_cursor': null});

  @override
  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body, {bool auth = true}) async {
    posts.add((path, body));
    return (responses['POST $path'] ?? <String, dynamic>{}) as Map<String, dynamic>;
  }
}

/// الشاشة داخل موجّه بسيط: البداية هي الشاشة، وأي انتقال يظهر مساره نصاً
Widget app(FakeApi api, Widget child) => ProviderScope(
      overrides: [apiProvider.overrideWithValue(api)],
      child: MaterialApp.router(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        routerConfig: GoRouter(routes: [
          GoRoute(path: '/', builder: (_, _) => child),
          GoRoute(path: '/:rest(.*)', builder: (_, state) => Scaffold(body: Text('route ${state.uri}'))),
        ]),
      ),
    );

Map<String, dynamic> money(String a) => {'amount': a, 'currency': 'OMR'};

Map<String, dynamic> rfq({bool canDecide = true, String status = 'sent', bool acceptable = true}) => {
      'id': 'r1', 'number': 'RFQ-2026-000001', 'type': 'parts', 'status': 'quoted', 'lost_reason': null, 'notes': null,
      'can_decide': canDecide, 'can_cancel': false, 'can_renew': false, 'unread_messages': 0, 'branch': null,
      'items': [
        {'id': 'i1', 'title': '1R-0750 · فلتر ديزل', 'quantity': '20.00', 'specs': null},
      ],
      'quotations': [
        {
          'id': 'q1', 'number': 'QT-2026-000001-v1', 'version': 1, 'status': status, 'acceptable': acceptable,
          'valid_until': '2099-01-01', 'subtotal': money('130.000'), 'discount_total': money('6.500'), 'vat_total': money('6.175'), 'total': money('129.675'),
          'delivery_terms': null, 'warranty_terms': null, 'payment_terms': 'نقداً عند الاستلام', 'estimated_duration': null,
          'approval_requested_at': null, 'pdf_url': null, 'response_note': null,
          'items': [
            {'description_ar': 'فلتر ديزل', 'description_en': 'Fuel filter', 'quantity': '20.00', 'unit_price': '6.500', 'discount_amount': '6.500', 'line_total': '129.675'},
          ],
        },
      ],
    };

void main() {
  test('formats money, days left and notification links', () {
    expect(formatMoney(money('1250.5'), 'ar'), '1,250.500 ر.ع.');
    expect(formatMoney(money('45000'), 'en'), 'OMR 45,000.000');
    expect(daysLeft('2026-12-05', now: DateTime.utc(2026, 11, 21, 12)), 14);
    expect(daysLeft('2026-12-05', now: DateTime.utc(2026, 12, 4, 21)), 0); // بعد منتصف الليل بتوقيت مسقط
    expect(appRoute('/account/quotes/01a1fe2d-9b95-7c83-a9b9-3aca9acfa4ab#messages'), '/requests/01a1fe2d-9b95-7c83-a9b9-3aca9acfa4ab/messages');
    expect(appRoute('/admin/rfqs/x'), isNull);
    expect(newMessageId(), matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')));
  });

  testWidgets('shows the quote with totals and accepts it with a PO number (M-18, US-13)', (tester) async {
    final api = FakeApi({'/me/rfqs/r1': rfq()});
    await tester.pumpWidget(app(api, const QuoteDetailScreen(id: 'r1')));
    await tester.pumpAndSettle();

    expect(find.text('QT-2026-000001-v1'), findsOneWidget);
    expect(find.text('129.675 ر.ع.'), findsWidgets);
    expect(find.text('− 6.500 ر.ع.'), findsOneWidget);

    await tester.tap(find.widgetWithText(FilledButton, 'قبول'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'رقم أمر الشراء (اختياري)'), 'LPO-77');
    await tester.tap(find.widgetWithText(FilledButton, 'قبول').last);
    await tester.pumpAndSettle();

    expect(api.posts.single.$1, '/me/quotations/q1/accept');
    expect(api.posts.single.$2['lpo_number'], 'LPO-77');
  });

  testWidgets('offers "send for approval" to a member without approval rights', (tester) async {
    final api = FakeApi({'/me/rfqs/r1': rfq(canDecide: false)});
    await tester.pumpWidget(app(api, const QuoteDetailScreen(id: 'r1')));
    await tester.pumpAndSettle();

    expect(find.text('إرسال للاعتماد'), findsOneWidget);
    expect(find.text('رفض'), findsNothing);
    await tester.tap(find.text('إرسال للاعتماد'));
    await tester.pumpAndSettle();
    expect(api.posts.single.$1, '/me/quotations/q1/request-approval');
  });

  testWidgets('asks for a note before requesting changes', (tester) async {
    final api = FakeApi({'/me/rfqs/r1': rfq()});
    await tester.pumpWidget(app(api, const QuoteDetailScreen(id: 'r1')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('طلب تعديل'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('إرسال')); // فارغ: لا يُرسل
    await tester.pumpAndSettle();
    expect(api.posts, isEmpty);
    await tester.enterText(find.byType(TextField), 'نحتاج 30 وحدة');
    await tester.tap(find.text('إرسال'));
    await tester.pumpAndSettle();
    expect(api.posts.single.$1, '/me/quotations/q1/revision');
    expect(api.posts.single.$2, {'note': 'نحتاج 30 وحدة'});
  });

  testWidgets('sends a parts request with an unlisted part from the app (M-24, US-12)', (tester) async {
    final api = FakeApi({'POST /me/rfqs': {'id': 'r9'}});
    await tester.pumpWidget(app(api, const NewRfqScreen(partId: 'p1', label: 'Caterpillar 1R-0750', qty: '20')));
    await tester.pumpAndSettle();

    expect(find.text('Caterpillar 1R-0750'), findsOneWidget);
    await tester.tap(find.text('قطعة غير مدرجة'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextFormField, 'رقم القطعة أو وصفها'), 'سلندر 207-63-02100');
    await tester.tap(find.text('سلندر هيدروليك: المواصفات'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextFormField, 'القطر الداخلي (مم)'), '90');
    // القائمة تُبنى عند الظهور، والحقول النصية لها تمريرها الخاص، فالمقصود قائمة الصفحة
    await tester.scrollUntilVisible(find.text('أرسل الطلب'), 300, scrollable: find.byType(Scrollable).first);
    await tester.ensureVisible(find.text('أرسل الطلب'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أرسل الطلب'));
    await tester.pump();

    final (path, body) = api.posts.single;
    expect(path, '/me/rfqs');
    expect(body['source'], 'app');
    expect(body['delivery_country'], 'OM');
    final items = (body['items'] as List).cast<Map<String, dynamic>>();
    expect(items[0], {'part_id': 'p1', 'quantity': '20', 'attachment_ids': <String>[]});
    expect(items[1]['free_text'], 'سلندر 207-63-02100');
    expect(items[1]['specs'], {'bore_mm': '90'});
    await tester.pumpAndSettle();
    expect(find.text('route /requests/r9'), findsOneWidget); // ينتقل لتفاصيل الطلب الجديد
  });

  testWidgets('lists equipment for a guest with the price before VAT (M-07)', (tester) async {
    final api = FakeApi({
      '/equipment': [
        {'id': 'e1', 'slug': 'komatsu-pc200', 'title': 'حفار كوماتسو PC200', 'condition': 'used', 'status': 'published', 'year': 2015, 'hour_meter': 9800,
          'price': money('18500'), 'cover_image_url': null},
      ],
    });
    await tester.pumpWidget(app(api, const EquipmentScreen()));
    await tester.pumpAndSettle();

    expect(find.text('حفار كوماتسو PC200'), findsOneWidget);
    expect(find.text('18,500.000 ر.ع. قبل الضريبة'), findsOneWidget);
    expect(find.text('مستعمل · 2015 · 9800 ساعة'), findsOneWidget);
  });
}
