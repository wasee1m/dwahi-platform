import 'package:dwahi_ui/dwahi_ui.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:technician_app/core/api.dart';
import 'package:technician_app/core/outbox.dart';
import 'package:technician_app/core/tasks.dart';
import 'package:technician_app/features/auth.dart';
import 'package:technician_app/features/finish_screens.dart';
import 'package:technician_app/features/sync_account.dart';
import 'package:technician_app/features/task_screens.dart';

// T-01، T-02، T-04، T-06، T-07، T-11، T-12 مع API وهمي وتخزين في الذاكرة: الصندوق الصادر يحفظ قبل الإرسال،
// ويبقى بعد إغلاق التطبيق وانقطاع الاتصال (NFR-17)، والخادم يقرر نتيجة كل حدث.

class FakeTokens extends TokenStore {
  String? refresh;
  String? device;

  @override
  Future<String?> readRefresh() async => refresh;

  @override
  Future<void> save(Map<String, dynamic> tokens) async {
    accessToken = tokens['access_token'] as String?;
    refresh = tokens['refresh_token'] as String?;
  }

  @override
  Future<void> clear() async {
    accessToken = null;
    refresh = null;
  }

  @override
  Future<String?> readDevice() async => device;

  @override
  Future<void> saveDevice(String id) async => device = id;
}

class FakeApi extends Api {
  FakeApi(this.responses) : super(FakeTokens(), () => 'ar');
  final Map<String, Object> responses;
  final posts = <(String, Map<String, dynamic>)>[];
  final uploads = <String>[];
  bool offline = false;

  /// نتيجة الخادم لكل حدث: applied افتراضياً، أو رفض برمز
  final rejectTypes = <String, String>{};

  @override
  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) async {
    if (offline) throw ApiException('OFFLINE', 0);
    final r = responses[path];
    if (r is ApiException) throw r;
    return (r ?? <String, dynamic>{}) as Map<String, dynamic>;
  }

  @override
  Future<ApiPage> page(String path, {Map<String, dynamic>? query, bool auth = true}) async {
    if (offline) throw ApiException('OFFLINE', 0);
    return ApiPage(((responses[path] ?? const <Map<String, dynamic>>[]) as List).cast<Map<String, dynamic>>(), const {});
  }

  @override
  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body, {bool auth = true}) async {
    if (offline) throw ApiException('OFFLINE', 0);
    posts.add((path, body));
    if (path == '/tech/sync/events') {
      // الخادم يطبق الحالة، فالجلب التالي يعيدها
      for (final e in (body['events'] as List).cast<Map<String, dynamic>>()) {
        final d = responses['/tech/tasks/${e['work_order_id']}'];
        if (e['type'] == 'status_changed' && !rejectTypes.containsKey('status_changed') && d is Map<String, dynamic>) {
          d['status'] = e['payload']['to'];
          d['on_hold_reason'] = e['payload']['reason'];
        }
        if (e['type'] == 'test_recorded' && d is Map<String, dynamic>) {
          d['tests'] = [...(d['tests'] as List? ?? const []), {'id': e['payload']['id'], 'template': 'x', 'result': e['payload']['local_result'], 'values': e['payload']['values']}];
        }
        if (e['type'] == 'report_submitted' && d is Map<String, dynamic>) d['status'] = 'work_completed';
      }
      return {
        'results': [
          for (final e in (body['events'] as List).cast<Map<String, dynamic>>())
            rejectTypes.containsKey(e['type'])
                ? {'client_event_id': e['client_event_id'], 'result': 'rejected', 'code': rejectTypes[e['type']], 'message': 'لم تعد هذه المهمة مسندة إليك.'}
                : {'client_event_id': e['client_event_id'], 'result': 'applied', 'code': null, 'message': null},
        ],
        'server_time': DateTime.now().toUtc().toIso8601String(),
      };
    }
    final reply = responses['POST $path'] ?? <String, dynamic>{};
    if (reply is ApiException) throw reply;
    return reply as Map<String, dynamic>;
  }

  @override
  Future<Map<String, dynamic>> upload(String filePath, String fileName) async {
    if (offline) throw ApiException('OFFLINE', 0);
    uploads.add(filePath);
    return {'id': 'att-${uploads.length}'};
  }

  List<Map<String, dynamic>> get sentEvents => [
        for (final p in posts.where((p) => p.$1 == '/tech/sync/events')) ...(p.$2['events'] as List).cast<Map<String, dynamic>>(),
      ];
}

Map<String, dynamic> task({String id = 'w1', String status = 'scheduled', bool received = true, String? start, int worked = 0}) => {
      'id': id, 'number': 'WO-2026-00000${id.substring(1)}', 'type': 'repair', 'status': status, 'on_hold_reason': null,
      'scheduled_start': start ?? DateTime.now().toUtc().toIso8601String(), 'scheduled_end': null, 'urgent': false,
      'customer': 'شركة الباطنة', 'item': 'CAT 320D', 'is_component': false, 'service_request': 'SR-2026-000001',
      'card': received ? 'RC-2026-000001' : null, 'received': received, 'my_role': 'lead',
      'team': [{'name': 'فني التطوير', 'role': 'lead'}], 'worked_seconds': worked, 'scope_total': null,
    };

Map<String, dynamic> detail([Map<String, dynamic>? over]) => {
      ...task(),
      'problem_description': 'تسريب زيت من سلندر البوم', 'component_details': null, 'instructions': 'افحص الجوانات',
      'attachments': [], 'intake': {'number': 'RC-2026-000001', 'received_at': null, 'hour_meter_reading': 9120, 'accessories': null, 'condition_notes': null, 'photos': []},
      'equipment': {'serial_number': 'X1', 'current_hour_meter': 9120}, 'history': [], 'scope': [], 'photos': [], 'parts': [], 'notes': [], 'events': [],
      ...?over,
    };

Map<String, Object> responses({Map<String, dynamic>? d}) => {
      '/tech/tasks': {'today': [task()], 'upcoming': [task(id: 'w2', start: DateTime.now().add(const Duration(days: 3)).toUtc().toIso8601String())], 'server_time': ''},
      '/tech/tasks/w1': d ?? detail(),
      '/tech/me': {'full_name': 'فني التطوير', 'skills': ['سلندرات هيدروليك'], 'branch': 'صحار'},
      '/tech/parts': [
        {'id': 'p1', 'part_number': '1R-0750', 'name': 'فلتر ديزل', 'unit': 'piece', 'available': '10.00'},
      ],
    };

/// الشاشة داخل موجّه بسيط
Widget app(FakeApi api, MemoryOutboxStore store, Widget child, {List<Override> extra = const []}) => ProviderScope(
      overrides: [apiProvider.overrideWithValue(api), outboxStoreProvider.overrideWithValue(store), tokenStoreProvider.overrideWithValue(api.tokens), ...extra],
      child: MaterialApp.router(
        theme: dwTheme(Brightness.light), // the real theme, so theme-only layout bugs fail here too
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        routerConfig: GoRouter(routes: [
          GoRoute(path: '/', builder: (_, _) => child),
          GoRoute(path: '/tasks/:id', builder: (_, state) => TaskScreen(state.pathParameters['id']!)),
          GoRoute(path: '/sync', builder: (_, _) => const SyncScreen()),
          GoRoute(path: '/:rest(.*)', builder: (_, state) => Scaffold(body: Text('route ${state.uri}'))),
        ]),
      ),
    );

void tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 4000);
  tester.view.devicePixelRatio = 2;
  addTearDown(tester.view.reset);
}

void main() {
  testWidgets('T-01: asks for the activation code on a new device, then remembers the device', (tester) async {
    final api = FakeApi({
      'POST /auth/technician/login': {'access_token': 'a', 'refresh_token': 'r', 'device_id': 'dev-1', 'user': {}},
      '/tech/me': {'full_name': 'فني التطوير', 'skills': []},
    });
    final store = MemoryOutboxStore();
    await tester.pumpWidget(app(api, store, const LoginScreen()));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('code')), findsOneWidget);

    await tester.enterText(find.byKey(const Key('login')), 'tech.dev@dwahi.test');
    await tester.enterText(find.byKey(const Key('password')), 'secret-pass');
    await tester.enterText(find.byKey(const Key('code')), 'abcd2345');
    await tester.pump();
    await tester.tap(find.text('دخول'));
    await tester.pumpAndSettle();

    final body = api.posts.single.$2;
    expect(body['activation_code'], 'abcd2345');
    expect(body.containsKey('device_id'), isFalse);
    expect((api.tokens as FakeTokens).device, 'dev-1');
    expect(store.cache['me']?['full_name'], 'فني التطوير');
  });

  testWidgets('T-02: lists today and upcoming, and works from the saved copy when offline', (tester) async {
    final api = FakeApi(responses());
    final store = MemoryOutboxStore();
    await tester.pumpWidget(app(api, store, const TasksScreen()));
    await tester.pumpAndSettle();
    expect(find.text('CAT 320D'), findsOneWidget);
    expect(find.text('RC-2026-000001'), findsOneWidget);
    expect(store.cache['task_w1'], isNotNull, reason: 'تفاصيل مهام اليوم محفوظة للعمل دون اتصال');

    api.offline = true;
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(app(api, store, const TasksScreen()));
    await tester.pumpAndSettle();
    expect(find.text('CAT 320D'), findsOneWidget);
    expect(find.textContaining('آخر تحديث'), findsOneWidget);
    expect(find.textContaining('تعمل دون اتصال'), findsOneWidget);
  });

  testWidgets('T-04: start and pause with a reason go to the outbox at once and are sent in order', (tester) async {
    tall(tester);
    final api = FakeApi(responses());
    final store = MemoryOutboxStore();
    await tester.pumpWidget(app(api, store, const TaskScreen('w1')));
    await tester.pumpAndSettle();
    expect(find.text('تسريب زيت من سلندر البوم'), findsOneWidget);

    await tester.tap(find.byKey(const Key('start')));
    await tester.pump();
    await tester.pump(const Duration(seconds: 2));
    expect(find.byKey(const Key('pause')), findsOneWidget);
    expect(find.text('جارية'), findsOneWidget);

    await tester.tap(find.byKey(const Key('pause')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('بانتظار قطع'));
    await tester.pump();
    await tester.tap(find.byKey(const Key('confirm-pause')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('resume')), findsOneWidget);
    expect(find.textContaining('بانتظار قطع'), findsWidgets);
    final sent = api.sentEvents;
    expect(sent.map((e) => e['payload']['to']).toList(), ['in_progress', 'on_hold']);
    expect(sent.last['payload']['reason'], 'awaiting_parts');
    expect(sent.every((e) => e['work_order_id'] == 'w1' && (e['client_event_id'] as String).length == 36), isTrue);
    expect(store.saved, isEmpty, reason: 'ما قبله الخادم يُحذف من الجهاز');
  });

  testWidgets('NFR-17: without a connection the events stay on the device, survive a restart, and are sent later', (tester) async {
    tall(tester);
    final api = FakeApi(responses());
    final store = MemoryOutboxStore();
    await tester.pumpWidget(app(api, store, const TaskScreen('w1')));
    await tester.pumpAndSettle();
    api.offline = true;
    await tester.tap(find.byKey(const Key('start')));
    await tester.pumpAndSettle(const Duration(seconds: 1));
    expect(store.saved.single.type, 'status_changed');
    expect(find.textContaining('بانتظار الإرسال: 1'), findsOneWidget);

    // إغلاق التطبيق وفتحه: الصندوق من التخزين
    await tester.pumpWidget(const SizedBox());
    api.offline = false;
    await tester.pumpWidget(app(api, store, const SyncScreen()));
    await tester.pumpAndSettle();
    expect(find.text('بدأت العمل'), findsOneWidget);
    await tester.tap(find.byKey(const Key('send-now')));
    await tester.pumpAndSettle();
    expect(api.sentEvents.single['payload']['to'], 'in_progress');
    expect(store.saved, isEmpty);
    expect(find.text('كل التغييرات أُرسلت.'), findsOneWidget);
  });

  testWidgets('T-11: a rejected event stays in the sync center with the server message until dismissed', (tester) async {
    final api = FakeApi(responses())..rejectTypes['status_changed'] = 'NOT_ASSIGNED';
    final store = MemoryOutboxStore();
    final container = ProviderContainer(overrides: [apiProvider.overrideWithValue(api), outboxStoreProvider.overrideWithValue(store)]);
    await container.read(outboxProvider.notifier).add('w1', 'status_changed', {'to': 'in_progress'});
    await container.read(outboxProvider.notifier).flush(); // ينتظر الإرسال الجاري
    expect(container.read(outboxProvider).rejected.single.code, 'NOT_ASSIGNED');

    await tester.pumpWidget(UncontrolledProviderScope(
      container: container,
      child: MaterialApp(theme: dwTheme(Brightness.light), locale: const Locale('ar'), supportedLocales: const [Locale('ar')], localizationsDelegates: GlobalMaterialLocalizations.delegates, home: const SyncScreen()),
    ));
    await tester.pumpAndSettle();
    expect(find.text('رفضها الخادم'), findsOneWidget);
    expect(find.textContaining('لم تعد هذه المهمة مسندة إليك.'), findsOneWidget);
    await tester.tap(find.text('تجاهل'));
    await tester.pumpAndSettle();
    expect(container.read(outboxProvider).events, isEmpty);
    await tester.pumpWidget(const SizedBox());
    container.dispose(); // مؤقت الإرسال الدوري
  });

  testWidgets('T-06: a photo is kept, uploaded first, then sent with its file id; notes and the meter too', (tester) async {
    tall(tester);
    final api = FakeApi(responses(d: detail({...task(status: 'in_progress')})));
    final store = MemoryOutboxStore();
    await tester.pumpWidget(app(api, store, const TaskScreen('w1'), extra: [photoPickerProvider.overrideWithValue(() async => '/tmp/cam.jpg')]));
    await tester.pumpAndSettle();

    await tester.tap(find.text('صورة'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('قبل'));
    await tester.pumpAndSettle();
    expect(api.uploads, ['/tmp/cam.jpg']);
    expect(api.sentEvents.last['type'], 'photo_added');
    expect(api.sentEvents.last['payload'], {'phase': 'before', 'attachment_id': 'att-1'});

    await tester.tap(find.text('ملاحظة'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('note')), 'التسريب من الجوان الداخلي');
    await tester.tap(find.text('حفظ'));
    await tester.pumpAndSettle();
    expect(api.sentEvents.last['payload']['body'], 'التسريب من الجوان الداخلي');

    await tester.tap(find.text('تسجيل القراءة'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('reading')), '9000');
    await tester.pump();
    expect(find.textContaining('أقل من السابقة'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('reading')), '9200');
    await tester.pump();
    await tester.tap(find.text('صورة العداد'));
    await tester.pump();
    await tester.tap(find.byKey(const Key('save-reading')));
    await tester.pumpAndSettle();
    expect(api.sentEvents.last['type'], 'hour_meter_recorded');
    expect(api.sentEvents.last['payload'], {'reading': 9200, 'photo_id': 'att-2'});
  });

  testWidgets('T-07: a store part comes from the branch search, a customer part needs only its description', (tester) async {
    tall(tester);
    final api = FakeApi(responses(d: detail({...task(status: 'in_progress')})));
    final store = MemoryOutboxStore();
    await tester.pumpWidget(app(api, store, const TaskScreen('w1')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('قطعة'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('part-search')), '1R07');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    await tester.tap(find.text('1R-0750 · فلتر ديزل'));
    await tester.pump();
    await tester.enterText(find.byKey(const Key('part-qty')), '2');
    await tester.pump();
    await tester.tap(find.byKey(const Key('add-part')));
    await tester.pumpAndSettle();
    final used = api.sentEvents.last;
    expect(used['type'], 'part_used');
    expect(used['payload']['part_id'], 'p1');
    expect(used['payload']['quantity'], '2');
    expect(used['payload']['source'], 'store');

    await tester.tap(find.text('قطعة'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('من العميل'));
    await tester.pump();
    expect(tester.widget<FilledButton>(find.byKey(const Key('add-part'))).onPressed, isNull);
    await tester.enterText(find.byKey(const Key('part-desc')), 'جوان من العميل');
    await tester.pump();
    await tester.tap(find.byKey(const Key('add-part')));
    await tester.pumpAndSettle();
    expect(api.sentEvents.last['payload']['description'], 'جوان من العميل');
    expect(api.sentEvents.last['payload']['source'], 'customer_supplied');
  });

  testWidgets('BR-20: start stays disabled until the item is received', (tester) async {
    tall(tester);
    final api = FakeApi(responses(d: detail({...task(received: false), 'intake': null})));
    await tester.pumpWidget(app(api, MemoryOutboxStore(), const TaskScreen('w1')));
    await tester.pumpAndSettle();
    expect(tester.widget<ButtonStyleButton>(find.byKey(const Key('start'))).onPressed, isNull);
    expect(find.textContaining('BR-20'), findsOneWidget);
  });

  testWidgets('T-12: signing out with unsent changes warns first', (tester) async {
    final api = FakeApi(responses());
    final store = MemoryOutboxStore();
    (api.tokens as FakeTokens)
      ..refresh = 'r'
      ..device = 'dev-1';
    final container = ProviderContainer(overrides: [
      apiProvider.overrideWithValue(api), outboxStoreProvider.overrideWithValue(store), tokenStoreProvider.overrideWithValue(api.tokens),
    ]);
    await container.read(authProvider.future);
    api.offline = true;
    await container.read(outboxProvider.notifier).add('w1', 'note_added', {'body': 'x'});
    await container.read(outboxProvider.notifier).flush();
    api.offline = false;

    await tester.pumpWidget(UncontrolledProviderScope(
      container: container,
      child: MaterialApp(theme: dwTheme(Brightness.light), locale: const Locale('ar'), supportedLocales: const [Locale('ar')], localizationsDelegates: GlobalMaterialLocalizations.delegates, home: const AccountScreen()),
    ));
    await tester.pumpAndSettle();
    expect(find.text('سلندرات هيدروليك'), findsOneWidget);
    await tester.tap(find.byKey(const Key('logout')));
    await tester.pumpAndSettle();
    expect(find.textContaining('لم تُرسل بعد'), findsOneWidget);
    await tester.tap(find.byKey(const Key('logout-anyway')));
    await tester.pumpAndSettle();
    expect(container.read(authProvider).value, isNull);
    expect(store.saved, isEmpty);
    expect((api.tokens as FakeTokens).device, 'dev-1', reason: 'الجهاز يبقى مفعّلاً للدخول التالي');
    await tester.pumpWidget(const SizedBox());
    container.dispose();
  });

  test('working time adds the device intervals to what the server counted', () {
    final fetched = DateTime.utc(2026, 11, 24, 8);
    OutboxEvent ev(String to, int minutes) => OutboxEvent(
        id: '$to$minutes', workOrderId: 'w1', type: 'status_changed', occurredAt: fetched.add(Duration(minutes: minutes)), payload: {'to': to, 'reason': 'end_of_shift'});
    final v = TaskView(task(worked: 600), [ev('in_progress', 0), ev('on_hold', 30), ev('in_progress', 60)], fetched);
    expect(v.status, 'in_progress');
    expect(v.worked(fetched.add(const Duration(minutes: 75))).inMinutes, 10 + 30 + 15);
  });

  Map<String, dynamic> ready({List<Map<String, dynamic>> tests = const []}) => detail({
        ...task(status: 'in_progress', worked: 3600),
        'photos': [{'id': 'ph1', 'phase': 'after', 'url': null, 'caption': null, 'taken_at': '2026-11-24T08:00:00Z'}],
        'parts': [{'id': 'pl1', 'part_id': null, 'part_number': null, 'name': 'جوانات', 'quantity': '2', 'source': 'customer_supplied', 'removed': false}],
        'meter_recorded': true,
        'tests': tests,
        'template': {'id': 'tpl', 'name': 'اختبار تشغيل المعدة', 'fields': [
          {'key': 'functions_ok', 'label_ar': 'كل الوظائف تعمل', 'label_en': 'All functions work', 'type': 'boolean', 'expect': true},
          {'key': 'run_minutes', 'label_ar': 'مدة التشغيل', 'label_en': 'Run time', 'type': 'number', 'unit': 'min', 'min': 10},
        ]},
      });

  testWidgets('T-09: the test result is evaluated on the device and finishing waits for the BR-07 checklist', (tester) async {
    tall(tester);
    final api = FakeApi(responses(d: ready()));
    final store = MemoryOutboxStore();
    await tester.pumpWidget(app(api, store, const FinishScreen('w1')));
    await tester.pumpAndSettle();
    expect(find.text('كل الوظائف تعمل'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byKey(const Key('confirm-finish'))).onPressed, isNull);

    // قيمة خارج الحدود: فاشل
    await tester.tap(find.descendant(of: find.byKey(const Key('test-functions_ok')), matching: find.text('نعم')));
    await tester.enterText(find.byKey(const Key('test-run_minutes')), '5');
    await tester.pump();
    await tester.tap(find.byKey(const Key('record-test')));
    await tester.pumpAndSettle();
    expect(api.sentEvents.last['type'], 'test_recorded');
    expect(api.sentEvents.last['payload']['values'], {'functions_ok': true, 'run_minutes': 5});
    expect(find.textContaining('الاختبار فاشل'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('test-run_minutes')), '20');
    await tester.pump();
    await tester.tap(find.byKey(const Key('record-test')));
    await tester.pumpAndSettle();
    expect(api.sentEvents.last['payload']['local_result'], 'pass');

    await tester.enterText(find.byKey(const Key('findings')), 'تلف الجوانات');
    await tester.pump();
    expect(tester.widget<FilledButton>(find.byKey(const Key('confirm-finish'))).onPressed, isNull, reason: 'الأعمال المنفذة مطلوبة');
    await tester.enterText(find.byKey(const Key('actions')), 'تغيير الجوانات');
    await tester.pump();
    await tester.tap(find.byKey(const Key('confirm-finish')));
    await tester.pumpAndSettle();
    expect(api.sentEvents.last['type'], 'report_submitted');
    expect(api.sentEvents.last['payload']['findings'], 'تلف الجوانات');
  });

  testWidgets('T-09: an assistant sees the checklist but cannot finish', (tester) async {
    tall(tester);
    final api = FakeApi(responses(d: {...ready(tests: [{'id': 't1', 'template': 'x', 'result': 'pass', 'values': {}}]), 'my_role': 'assistant'}));
    await tester.pumpWidget(app(api, MemoryOutboxStore(), const FinishScreen('w1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('findings')), 'x');
    await tester.enterText(find.byKey(const Key('actions')), 'y');
    await tester.pump();
    expect(find.text('الفني الرئيسي وحده يُنهي المهمة.'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byKey(const Key('confirm-finish'))).onPressed, isNull);
  });

  testWidgets('T-08: additional work with "pause awaiting approval" pauses the task at once', (tester) async {
    tall(tester);
    final api = FakeApi(responses(d: detail({...task(status: 'in_progress')})));
    final store = MemoryOutboxStore();
    await tester.pumpWidget(app(api, store, const TaskScreen('w1'), extra: [photoPickerProvider.overrideWithValue(() async => '/tmp/crack.jpg')]));
    await tester.pumpAndSettle();
    api.offline = true; // يبقى في الصندوق ليظهر العرض المتفائل
    await tester.tap(find.byKey(const Key('additional')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('add-desc')), 'شرخ في عمود السلندر');
    await tester.tap(find.text('صورة').last);
    await tester.pump();
    await tester.tap(find.byKey(const Key('add-hold')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('add-send')));
    await tester.pumpAndSettle();
    final e = store.saved.single;
    expect(e.type, 'additional_work_requested');
    expect(e.payload['hold'], isTrue);
    expect(e.localFile, '/tmp/crack.jpg');
    expect(find.text('متوقفة'), findsOneWidget);
    expect(find.textContaining('بانتظار موافقة'), findsWidgets);
    expect(find.text('شرخ في عمود السلندر'), findsOneWidget);
  });

  testWidgets('T-10: history shows weekly hours, returned tasks and completed ones', (tester) async {
    final api = FakeApi({
      '/tech/history': {
        'completed': [{...task(id: 'w3', status: 'verified'), 'completed_at': '2026-11-20T10:00:00Z'}],
        'days': [{'date': '2026-11-22', 'seconds': 5400}],
        'weeks': [{'week_start': '2026-11-21', 'seconds': 5400}],
        'returned': [{'id': 'w1', 'number': 'WO-2026-000001', 'note': 'صورة العداد غير واضحة', 'at': '2026-11-23T09:00:00Z'}],
      },
    });
    await tester.pumpWidget(app(api, MemoryOutboxStore(), const HistoryScreen()));
    await tester.pumpAndSettle();
    expect(find.textContaining('1:30'), findsNWidgets(2));
    expect(find.text('صورة العداد غير واضحة'), findsOneWidget);
    expect(find.textContaining('WO-2026-000003'), findsOneWidget);
  });

  test('the test rule matches the server: numbers within limits, yes/no as expected', () {
    final fields = [
      {'key': 'p', 'type': 'number', 'min': 150, 'max': 400},
      {'key': 'leak', 'type': 'boolean', 'expect': false},
    ];
    expect(evaluateTest(fields, {'p': 200, 'leak': false}), 'pass');
    expect(evaluateTest(fields, {'p': 100, 'leak': false}), 'fail');
    expect(evaluateTest(fields, {'p': 200, 'leak': true}), 'fail');
    expect(evaluateTest(fields, {'p': 200}), 'fail');
  });
}
