import 'dart:convert';
import 'dart:typed_data';

import 'package:customer_app/core/api.dart';
import 'package:customer_app/features/auth/auth_controller.dart';
import 'package:customer_app/main.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

// الخادم يرفض التجديد أثناء الاستخدام: التطبيق ينتقل للدخول ثم يعيد العميل إلى مكانه (07 M-03)

class _Tokens extends TokenStore {
  String? refresh = 'session.secret';

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

class _Server implements HttpClientAdapter {
  bool revoked = false;
  int refreshStatus = 401;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    ResponseBody reply(int status, Object body) => ResponseBody.fromString(jsonEncode(body), status, headers: {
          Headers.contentTypeHeader: ['application/json'],
        });
    final path = options.path;
    const user = {'full_name': 'سالم', 'needs_onboarding': false};
    if (path == '/auth/otp/request') return reply(200, {'data': <String, dynamic>{}});
    if (path == '/auth/otp/verify') {
      revoked = false;
      return reply(200, {'data': {'access_token': 'a2', 'refresh_token': 's2.r', 'user': user}});
    }
    if (revoked && (path.startsWith('/me') || path.startsWith('/auth'))) {
      final status = path == '/auth/token/refresh' ? refreshStatus : 401;
      return reply(status, {'error': {'code': 'E', 'message': ''}});
    }
    if (path == '/me') return reply(200, {'data': user});
    if (path == '/me/notifications/count') return reply(200, {'data': {'unread_count': 0}});
    return reply(200, {'data': <Object>[]});
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  late _Server server;
  late _Tokens store;

  Future<ProviderContainer> launch(WidgetTester tester) async {
    server = _Server();
    store = _Tokens();
    await tester.pumpWidget(ProviderScope(
      retry: (_, _) => null,
      overrides: [
        tokenStoreProvider.overrideWithValue(store),
        apiProvider.overrideWithValue(Api(store, () => 'ar', adapter: server)),
      ],
      child: const CustomerApp(),
    ));
    await tester.pumpAndSettle();
    expect(find.text('أهلاً سالم'), findsOneWidget);
    return ProviderScope.containerOf(tester.element(find.byType(CustomerApp)));
  }

  Future<void> signIn(WidgetTester tester) async {
    await tester.enterText(find.byType(TextField), '91234567');
    await tester.tap(find.text('أرسل الرمز'));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1)); // رد dio ثم فتح شاشة الرمز
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    await tester.enterText(find.byType(TextField).last, '123456');
    await tester.pumpAndSettle();
  }

  testWidgets('goes to sign-in from a tab and returns to it afterwards', (tester) async {
    await launch(tester);
    server.revoked = true;
    await tester.tap(find.text('طلباتي'));
    await tester.pumpAndSettle();

    expect(find.text('رقم جوالك'), findsOneWidget);
    expect(find.text('انتهت جلستك. سجّل الدخول للمتابعة.'), findsOneWidget);

    await signIn(tester);
    expect(find.text('لا طلبات هنا بعد.'), findsOneWidget);
  });

  testWidgets('goes to sign-in from a screen opened above the tabs and returns to it', (tester) async {
    final container = await launch(tester);
    server.revoked = true;
    container.read(routerProvider).push('/notifications');
    await tester.pumpAndSettle();
    expect(find.text('رقم جوالك'), findsOneWidget);

    await signIn(tester);
    expect(find.text('الإشعارات'), findsOneWidget);
  });

  testWidgets('back from sign-in leaves the customer browsing as a guest', (tester) async {
    final container = await launch(tester);
    server.revoked = true;
    container.read(routerProvider).push('/notifications');
    await tester.pumpAndSettle();
    await tester.tap(find.byType(BackButton)); // pageBack يبحث بالتلميح الإنجليزي
    await tester.pumpAndSettle();

    expect(find.text('تصفح المعدات'), findsOneWidget);
    expect(find.text('أهلاً سالم'), findsNothing);
  });

  testWidgets('keeps the customer signed in when refresh fails for a passing reason', (tester) async {
    final container = await launch(tester);
    server
      ..revoked = true
      ..refreshStatus = 503;
    await tester.tap(find.text('طلباتي'));
    await tester.pumpAndSettle();

    expect(find.text('رقم جوالك'), findsNothing);
    expect(store.refresh, isNotNull);
    expect(container.read(authProvider).value, isNotNull);
  });

  testWidgets('signing out does not say that the session expired', (tester) async {
    final container = await launch(tester);
    server.revoked = true; // حتى لو كانت الجلسة ملغاة في الخادم
    final done = container.read(authProvider.notifier).logout();
    await tester.pump();
    await tester.pump(const Duration(seconds: 1)); // dio يحتاج دورة مؤقت، فلا يُنتظر قبل pump
    await tester.pumpAndSettle();
    await done;

    expect(container.read(authProvider).value, isNull);
    expect(find.text('انتهت جلستك. سجّل الدخول للمتابعة.'), findsNothing);
  });
}
