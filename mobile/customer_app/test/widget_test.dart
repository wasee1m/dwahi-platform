import 'package:customer_app/core/api.dart';
import 'package:customer_app/core/strings.dart';
import 'package:customer_app/features/auth/error_text.dart';
import 'package:customer_app/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// مخزن رموز في الذاكرة بدل Keychain/Keystore في الاختبار
class _MemoryTokens extends TokenStore {
  String? _refresh;
  @override
  Future<String?> readRefresh() async => _refresh;
  @override
  Future<void> save(Map<String, dynamic> tokens) async {
    accessToken = tokens['access_token'] as String;
    _refresh = tokens['refresh_token'] as String;
  }

  @override
  Future<void> clear() async {
    accessToken = null;
    _refresh = null;
  }
}

void main() {
  testWidgets('starts signed out on the Arabic RTL welcome screen', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [tokenStoreProvider.overrideWithValue(_MemoryTokens())],
      child: const CustomerApp(),
    ));
    await tester.pumpAndSettle();

    expect(find.text('معدات وقطع وصيانة في تطبيق واحد'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('ابدأ'))), TextDirection.rtl);

    await tester.tap(find.text('ابدأ'));
    await tester.pumpAndSettle();
    expect(find.text('رقم جوالك'), findsOneWidget);
  });

  test('maps API error codes to clear Arabic messages (US-06)', () {
    final s = S(const Locale('ar'));
    expect(errorText(ApiException('OTP_INVALID', 422, details: {'attempts_left': 2}), s), 'الرمز غير صحيح. محاولات متبقية: 2');
    expect(errorText(ApiException('OTP_LOCKED', 429, details: {'retry_after': 840}), s), 'محاولات خاطئة كثيرة. حاول بعد 14 دقيقة.');
    expect(errorText(ApiException('OFFLINE', 0), s), 'لا يوجد اتصال بالإنترنت.');
  });
}
