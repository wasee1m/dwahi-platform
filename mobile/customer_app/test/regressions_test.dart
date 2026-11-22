import 'package:customer_app/core/api.dart';
import 'package:customer_app/features/quotes/quote_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'quotes_test.dart' show FakeApi, app, rfq;

// أخطاء ظهرت عند تجربة التطبيق على المحاكي (2026-11-22)

class _RejectingApi extends FakeApi {
  _RejectingApi(this.error) : super({});
  final ApiException error;

  @override
  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body, {bool auth = true}) async => throw error;
}

void main() {
  testWidgets('shows the remaining line own values after deleting the one above it', (tester) async {
    await tester.pumpWidget(app(FakeApi({}), const NewRfqScreen()));
    await tester.pumpAndSettle();
    for (final text in ['first part', 'second part']) {
      final add = find.text('قطعة غير مدرجة');
      await tester.scrollUntilVisible(add, 200, scrollable: find.byType(Scrollable).first);
      await tester.ensureVisible(add);
      await tester.pumpAndSettle();
      await tester.tap(add);
      await tester.pumpAndSettle();
      await tester.enterText(find.widgetWithText(TextFormField, 'رقم القطعة أو وصفها').last, text);
    }
    await tester.ensureVisible(find.byIcon(Icons.delete_outline).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.delete_outline).first);
    await tester.pumpAndSettle();

    expect(find.text('second part'), findsOneWidget);
    expect(find.text('first part'), findsNothing);
  });

  testWidgets('shows fractional quantities as entered', (tester) async {
    final r = rfq();
    (r['items'] as List).first['quantity'] = '2.50';
    await tester.pumpWidget(app(FakeApi({'/me/rfqs/r1': r}), const QuoteDetailScreen(id: 'r1')));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.textContaining('فلتر ديزل').last, 200, scrollable: find.byType(Scrollable).first);

    expect(find.text('× 2.5'), findsOneWidget);
  });

  testWidgets('lets the customer describe equipment that is not in the catalog', (tester) async {
    await tester.pumpWidget(app(FakeApi({}), const NewRfqScreen(type: 'equipment')));
    await tester.pumpAndSettle();

    expect(find.text('معدة غير مدرجة'), findsOneWidget);
  });

  testWidgets('does not let the type change under a catalog part', (tester) async {
    await tester.pumpWidget(app(FakeApi({}), const NewRfqScreen(partId: 'p1', label: 'Caterpillar 1R-0750')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('معدات'));
    await tester.pumpAndSettle();

    expect(tester.widget<SegmentedButton<String>>(find.byType(SegmentedButton<String>)).selected, {'parts'});
  });

  testWidgets('recovers from an error whose details are not field messages', (tester) async {
    final api = _RejectingApi(ApiException('RATE_LIMITED', 429, details: {'retry_after': 60}, message: 'wait'));
    await tester.pumpWidget(app(api, const NewRfqScreen(partId: 'p1', label: 'Caterpillar 1R-0750')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أرسل الطلب'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('wait'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'أرسل الطلب')).onPressed, isNotNull);
  });
}
