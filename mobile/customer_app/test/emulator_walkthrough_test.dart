import 'package:customer_app/core/api.dart';
import 'package:customer_app/features/notifications/notifications_screen.dart';
import 'package:customer_app/features/quotes/quote_screens.dart';
import 'package:customer_app/main.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'quotes_test.dart' show FakeApi, app, money, rfq;

// جولة العرض والرسائل على المحاكي: التواريخ (NFR-21) وتحديث الرئيسية عند الرجوع (07 M-03)

class _SignedIn extends TokenStore {
  @override
  Future<String?> readRefresh() async => 's.r';
}

void main() {
  testWidgets('shows times in Muscat with western digits', (tester) async {
    final api = FakeApi({
      '/me/notifications': [
        {'id': 'n1', 'title': 'عرض سعر', 'body': 'صالح حتى 2026-12-06.', 'created_at': '2026-11-21T21:30:00Z', 'read': true, 'link': '/x'},
      ],
    });
    await tester.pumpWidget(app(api, const NotificationsScreen()));
    await tester.pumpAndSettle();

    // 21:30 UTC هي 01:30 من اليوم التالي في مسقط
    expect(find.textContaining('22 نوفمبر 2026'), findsOneWidget);
    expect(find.textContaining('01:30'), findsOneWidget);
  });

  testWidgets('keeps dates in year-month-day order inside Arabic text', (tester) async {
    await tester.pumpWidget(app(FakeApi({'/me/rfqs/r1': rfq()}), const QuoteDetailScreen(id: 'r1')));
    await tester.pumpAndSettle();

    expect(find.textContaining('\u20662099-01-01\u2069'), findsOneWidget);
  });

  testWidgets('home shows a quote that arrived while another screen was on top', (tester) async {
    final api = FakeApi({
      '/me': {'full_name': 'سالم', 'needs_onboarding': false},
      '/me/rfqs': <Map<String, dynamic>>[],
      '/me/notifications/count': {'unread_count': 0},
    });
    await tester.pumpWidget(ProviderScope(
      overrides: [tokenStoreProvider.overrideWithValue(_SignedIn()), apiProvider.overrideWithValue(api)],
      child: const CustomerApp(),
    ));
    await tester.pumpAndSettle();
    expect(find.text('أهلاً سالم'), findsOneWidget);

    final router = ProviderScope.containerOf(tester.element(find.byType(CustomerApp))).read(routerProvider);
    router.push('/notifications');
    await tester.pumpAndSettle();
    api.responses['/me/rfqs'] = [
      {'id': 'r1', 'number': 'RFQ-1', 'summary': ['فلتر'], 'quotation': {'number': 'QT-1', 'valid_until': '2099-01-01', 'total': money('10.000')}},
    ];
    api.responses['/me/notifications/count'] = {'unread_count': 3};
    router.pop();
    await tester.pumpAndSettle();

    expect(find.text('QT-1'), findsOneWidget);
    expect(find.text('3'), findsOneWidget);
  });
}
