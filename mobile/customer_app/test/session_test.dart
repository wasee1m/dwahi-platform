import 'dart:typed_data';

import 'package:customer_app/core/api.dart';
import 'package:customer_app/features/auth/auth_controller.dart';
import 'package:customer_app/features/quotes/quote_screens.dart';
import 'package:customer_app/main.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'quotes_test.dart' show FakeApi;

// الجلسة تبقى دون شبكة (04 §5)، والزائر يعود لما طلبه بعد الدخول (07 M-02)

class _Tokens extends TokenStore {
  _Tokens([this.refresh]);
  String? refresh;

  @override
  Future<String?> readRefresh() async => refresh;

  @override
  Future<void> save(Map<String, dynamic> tokens) async {
    accessToken = tokens['access_token'] as String;
    refresh = tokens['refresh_token'] as String;
  }

  @override
  Future<void> clear() async {
    accessToken = null;
    refresh = null;
  }
}

/// /me يرد 401، والتجديد يرد بالحالة المطلوبة. null = لا شبكة.
class _Server implements HttpClientAdapter {
  _Server({required this.refreshStatus, this.offline = false});
  final int? refreshStatus;
  final bool offline;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    final status = options.path.endsWith('/auth/token/refresh') ? refreshStatus : (offline ? null : 401);
    if (status == null) throw DioException(requestOptions: options, type: DioExceptionType.connectionError);
    return ResponseBody.fromString('{"error":{"code":"E","message":""}}', status, headers: {
      Headers.contentTypeHeader: ['application/json'],
    });
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  for (final (name, refreshStatus, offline, kept) in [
    ('no network at launch', null, true, true),
    ('network lost during refresh', null, false, true),
    ('server error during refresh', 500, false, true),
    ('rate limit during refresh', 429, false, true),
    ('refresh refused', 401, false, false),
  ]) {
    test('session on $name: ${kept ? 'kept' : 'ended'}', () async {
      final store = _Tokens('session.secret');
      final api = Api(store, () => 'ar', adapter: _Server(refreshStatus: refreshStatus, offline: offline));
      final container = ProviderContainer(
        retry: (_, _) => null,
        overrides: [tokenStoreProvider.overrideWithValue(store), apiProvider.overrideWithValue(api)],
      );
      addTearDown(container.dispose);
      container.listen(authProvider, (_, _) {}, onError: (_, _) {});
      try {
        await container.read(authProvider.future);
      } catch (_) {
        // دون اتصال: الحالة خطأ، لا خروج
      }

      expect(store.refresh, kept ? 'session.secret' : isNull);
      expect(container.read(authProvider).hasError, kept);
    });
  }

  testWidgets('offers retry instead of the welcome screen when offline', (tester) async {
    final store = _Tokens('session.secret');
    await tester.pumpWidget(ProviderScope(
      retry: (_, _) => null,
      overrides: [
        tokenStoreProvider.overrideWithValue(store),
        apiProvider.overrideWithValue(Api(store, () => 'ar', adapter: _Server(refreshStatus: null, offline: true))),
      ],
      child: const CustomerApp(),
    ));
    await tester.pumpAndSettle();

    expect(find.text('إعادة المحاولة'), findsOneWidget);
    expect(find.text('ابدأ'), findsNothing);
  });

  testWidgets('returns a guest to the request they started, after signing in', (tester) async {
    final api = FakeApi({
      'POST /auth/otp/verify': {
        'access_token': 'a', 'refresh_token': 's.r',
        'user': {'full_name': 'سالم', 'needs_onboarding': false},
      },
    });
    await tester.pumpWidget(ProviderScope(
      overrides: [tokenStoreProvider.overrideWithValue(_Tokens()), apiProvider.overrideWithValue(api)],
      child: const CustomerApp(),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تصفح كزائر'));
    await tester.pumpAndSettle();

    final router = ProviderScope.containerOf(tester.element(find.byType(CustomerApp))).read(routerProvider);
    router.push(Uri(path: '/requests/new', queryParameters: {'part': 'p1', 'label': 'Caterpillar 1R-0750'}).toString());
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), '91234567');
    await tester.tap(find.text('أرسل الرمز'));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    await tester.enterText(find.byType(TextField).last, '123456');
    await tester.pumpAndSettle();

    expect(find.byType(NewRfqScreen), findsOneWidget);
    expect(find.text('Caterpillar 1R-0750'), findsOneWidget);
    router.pop();
    await tester.pumpAndSettle();
    expect(find.text('أهلاً سالم'), findsOneWidget); // الرجوع للرئيسية، لا خروج من التطبيق
  });
}
