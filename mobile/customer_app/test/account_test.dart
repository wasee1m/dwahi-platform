import 'dart:io';

import 'package:customer_app/core/api.dart';
import 'package:customer_app/features/account/account_screen.dart';
import 'package:customer_app/features/account/company_screen.dart';
import 'package:customer_app/features/account/fleet_screens.dart';
import 'package:customer_app/features/account/settings_screens.dart';
import 'package:customer_app/features/auth/auth_controller.dart';
import 'package:customer_app/features/catalog/by_machine.dart';
import 'package:customer_app/features/notifications/notifications_screen.dart';
import 'package:customer_app/features/quotes/quote_screens.dart';
import 'package:customer_app/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'quotes_test.dart' show FakeApi;

// S5: M-09 حسب المعدة، M-13، M-14، M-22

class SignedIn extends TokenStore {
  String? refresh = 's.r';

  @override
  Future<String?> readRefresh() async => refresh;

  @override
  Future<void> clear() async => refresh = null;
}

Map<String, dynamic> me({bool company = false}) => {
      'id': 'u1', 'full_name': 'سالم', 'phone': '+96891234567', 'email': null, 'email_verified': false, 'needs_onboarding': false,
      'organizations': [
        if (company) {'id': 'o1', 'name': 'شركة الخوض', 'role': 'owner', 'can_approve_quotes': true},
      ],
    };

Map<String, dynamic> machine(String id, {String? org, String? model}) => {
      'id': id, 'name': 'حفار $id', 'machine': 'حفار $id', 'nickname': null, 'model_id': model, 'brand': null, 'model': null,
      'serial_number': null, 'year': null, 'hour_meter': 9000, 'hour_meter_at': null, 'site': null, 'nameplate': null,
      'account': org == null ? null : {'id': org, 'name': 'شركة الخوض'},
    };

/// شاشة واحدة، والمستخدم مسجل الدخول ومحمّل قبلها
Widget screen(FakeApi api, Widget child) => ProviderScope(
      retry: (_, _) => null,
      overrides: [apiProvider.overrideWithValue(api), tokenStoreProvider.overrideWithValue(SignedIn())],
      child: MaterialApp.router(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        routerConfig: GoRouter(routes: [
          GoRoute(
            path: '/',
            builder: (_, _) => Consumer(builder: (_, ref, _) => ref.watch(authProvider).hasValue ? child : const SizedBox()),
          ),
          GoRoute(path: '/:rest(.*)', builder: (_, state) => Scaffold(body: Text('route ${state.uri}'))),
        ]),
      ),
    );

/// الورقة تُغلق نفسها عند النجاح، فتُفتح فوق شاشة كما في التطبيق
Future<void> openReading(WidgetTester tester, FakeApi api) async {
  await tester.pumpWidget(screen(api, Builder(
    builder: (context) => Scaffold(body: TextButton(
      onPressed: () => showModalBottomSheet<bool>(context: context, isScrollControlled: true, builder: (_) => const ReadingSheet(id: 'a')),
      child: const Text('open'),
    )),
  )));
  await tester.pumpAndSettle();
  await tester.tap(find.text('open'));
  await tester.pumpAndSettle();
}

/// التطبيق كاملاً بموجّهه
Future<ProviderContainer> launch(WidgetTester tester, FakeApi api, SignedIn store) async {
  await tester.pumpWidget(ProviderScope(
    retry: (_, _) => null,
    overrides: [apiProvider.overrideWithValue(api), tokenStoreProvider.overrideWithValue(store)],
    child: const CustomerApp(),
  ));
  await tester.pumpAndSettle();
  return ProviderScope.containerOf(tester.element(find.byType(CustomerApp)));
}

void main() {
  test('the shown app version matches pubspec', () {
    expect(File('pubspec.yaml').readAsStringSync(), contains('version: $appVersion+'));
  });

  test('account notifications open their screens', () {
    expect(appRoute('/account/company'), '/account/company');
    expect(appRoute('/account/settings'), '/account/privacy');
  });

  testWidgets('shows the fleet of the selected account only', (tester) async {
    final api = FakeApi({'/me': me(company: true), '/me/fleet': [machine('a', org: 'o1'), machine('b')]});
    await tester.pumpWidget(screen(api, const FleetScreen()));
    await tester.pumpAndSettle();

    expect(find.text('حفار a'), findsOneWidget);
    expect(find.text('حفار b'), findsNothing);

    await tester.tap(find.text('شركة الخوض'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('شخصي').last);
    await tester.pumpAndSettle();
    expect(find.text('حفار b'), findsOneWidget);
  });

  testWidgets('warns about a lower reading, then sends the confirmation with its note (US-10)', (tester) async {
    final api = FakeApi({
      '/me': me(),
      'POST /me/fleet/a/hour-readings': ApiException('HOUR_METER_LOWER', 409, details: {'previous': 9000}),
    });
    await openReading(tester, api);
    await tester.enterText(find.byType(TextField).first, '100');
    await tester.tap(find.text('حفظ'));
    await tester.pumpAndSettle();

    expect(find.textContaining('القراءة أقل من السابقة (9000)'), findsOneWidget);
    expect(api.posts.single.$2['confirm_lower'], false);

    api.responses.remove('POST /me/fleet/a/hour-readings');
    await tester.enterText(find.byType(TextField).last, 'تبديل العداد');
    await tester.tap(find.text('أؤكد القراءة الأقل'));
    await tester.pumpAndSettle();

    expect(api.posts.last.$2['confirm_lower'], true);
    expect(api.posts.last.$2['note'], 'تبديل العداد');
  });

  testWidgets('changing the number cancels a pending lower-reading confirmation', (tester) async {
    final api = FakeApi({
      '/me': me(),
      'POST /me/fleet/a/hour-readings': ApiException('HOUR_METER_LOWER', 409, details: {'previous': 9000}),
    });
    await openReading(tester, api);
    await tester.enterText(find.byType(TextField).first, '100');
    await tester.tap(find.text('حفظ'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '50');
    await tester.pumpAndSettle();

    expect(find.text('أؤكد القراءة الأقل'), findsNothing);
  });

  testWidgets('a request opened from a personal machine stays personal for a company owner', (tester) async {
    final api = FakeApi({
      '/me': me(company: true),
      '/me/fleet': [machine('b')],
      'POST /me/rfqs': ApiException('RATE_LIMITED', 429, message: 'wait'),
    });
    await tester.pumpWidget(screen(api, const NewRfqScreen(partId: 'p1', label: 'فلتر', equipmentId: 'b')));
    await tester.pumpAndSettle();
    final send = find.text('أرسل الطلب');
    await tester.scrollUntilVisible(send, 300, scrollable: find.byType(Scrollable).first);
    await tester.ensureVisible(send);
    await tester.pumpAndSettle();
    await tester.tap(send);
    await tester.pumpAndSettle();

    expect(api.posts.single.$2['organization_id'], isNull);
    expect(api.posts.single.$2['customer_equipment_id'], 'b');
  });

  testWidgets('a refused role change shows the role that is really saved', (tester) async {
    final company = {
      'id': 'o1', 'name': 'شركة الخوض', 'cr_number': null, 'governorate': 'muscat', 'preapproval_limit': {'amount': '0.000', 'currency': 'OMR'},
      'can_manage': true, 'my_role': 'owner', 'invitations': <Object>[],
      'members': [
        {'user_id': 'u1', 'name': 'سالم', 'phone': '+96891234567', 'status': 'active', 'role': 'owner', 'can_approve_quotes': true},
      ],
    };
    final api = FakeApi({
      '/me': me(company: true),
      '/me/organizations': [company],
      'PATCH /me/organizations/o1/members/u1': ApiException('LAST_OWNER', 409, message: 'آخر مالك'),
    });
    await tester.pumpWidget(screen(api, const CompanyScreen()));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(DropdownButtonFormField<String>, 'مالك'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('عضو').last);
    await tester.pumpAndSettle();

    expect(api.writes.single.$3['role'], 'member');
    expect(find.text('آخر مالك'), findsOneWidget);
    expect(find.widgetWithText(DropdownButtonFormField<String>, 'مالك'), findsOneWidget);
    expect(find.widgetWithText(DropdownButtonFormField<String>, 'عضو'), findsNothing);
  });

  testWidgets('mandatory channels are locked and optional ones save (US-23)', (tester) async {
    final api = FakeApi({
      '/me': me(),
      '/me/notification-preferences': {
        'marketing_consent': false,
        'preferences': [
          {'category': 'orders', 'channel': 'sms', 'state': 'locked', 'has_mandatory': true, 'enabled': true},
          {'category': 'messages', 'channel': 'push', 'state': 'editable', 'has_mandatory': false, 'enabled': true},
        ],
      },
    });
    await tester.pumpWidget(screen(api, const PreferencesScreen()));
    await tester.pumpAndSettle();

    expect(tester.widget<SwitchListTile>(find.widgetWithText(SwitchListTile, 'SMS')).onChanged, isNull);
    await tester.tap(find.widgetWithText(SwitchListTile, 'التطبيق'));
    await tester.pumpAndSettle();

    expect(api.writes.single.$2, '/me/notification-preferences');
    expect(((api.writes.single.$3['preferences'] as List).single as Map)['enabled'], false);
  });

  testWidgets('lists compatible parts by category (US-04)', (tester) async {
    final api = FakeApi({
      '/me': me(),
      '/parts/compatible': {
        'model': {'id': 'm1', 'model_name': '950GC', 'brand': 'Caterpillar'},
        'groups': [
          {
            'category': 'فلاتر',
            'parts': [
              {'id': 'p1', 'part_number': '1R-0750', 'name': 'فلتر ديزل', 'availability': 'available', 'brand': {'name': 'CAT', 'slug': 'cat'}, 'price': {'amount': '6.500', 'currency': 'OMR'}},
            ],
          },
        ],
      },
    });
    await tester.pumpWidget(screen(api, const ByMachineScreen(modelId: 'm1')));
    await tester.pumpAndSettle();

    expect(find.text('فلاتر'), findsOneWidget);
    expect(find.textContaining('فلتر ديزل'), findsOneWidget);
  });

  testWidgets('reloading the profile keeps the screen opened above the tabs', (tester) async {
    final api = FakeApi({'/me': me(), '/me/notifications/count': {'unread_count': 0}});
    final container = await launch(tester, api, SignedIn());
    container.read(routerProvider).push('/account/company');
    await tester.pumpAndSettle();
    expect(find.text('الشركة والأعضاء'), findsOneWidget);

    await container.read(authProvider.notifier).reload();
    await tester.pumpAndSettle();

    expect(find.text('الشركة والأعضاء'), findsOneWidget);
  });

  testWidgets('deleting the account ends on home as a guest (US-11)', (tester) async {
    final store = SignedIn();
    final api = FakeApi({
      '/me': me(),
      '/me/notifications/count': {'unread_count': 0},
      '/me/deletion': {'can_delete': true, 'companies': <Object>[], 'requests': <Object>[], 'grace_days': 30},
      'POST /me/deletion/request': {'status': 'sent', 'debug_code': null},
    });
    final container = await launch(tester, api, store);
    container.read(routerProvider).push('/account/privacy');
    await tester.pumpAndSettle();

    Future<void> press(String label) async {
      final button = find.text(label);
      await tester.ensureVisible(button);
      await tester.pumpAndSettle();
      await tester.tap(button);
      await tester.pumpAndSettle();
    }

    await press('أرسل رمز تأكيد الحذف');
    await tester.enterText(find.byType(TextField).last, '123456');
    await press('احذف حسابي');

    expect(api.posts.last.$1, '/me/deletion');
    expect(store.refresh, isNull);
    expect(find.text('تصفح المعدات'), findsOneWidget);
    expect(find.text('أهلاً سالم'), findsNothing);
  });
}
