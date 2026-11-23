import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:dwahi_ui/dwahi_ui.dart';

import 'core/api.dart';
import 'core/outbox.dart';
import 'features/auth.dart';
import 'features/sync_account.dart';
import 'features/task_screens.dart';

void main() => runApp(const ProviderScope(child: TechnicianApp()));

/// تطبيق الفني (07 §2، FR-TEC): الدخول بجهاز مفعّل، والمهام، والتنفيذ دون اتصال عبر الصندوق الصادر (04 §6)
class TechnicianApp extends ConsumerStatefulWidget {
  const TechnicianApp({super.key});

  @override
  ConsumerState<TechnicianApp> createState() => _TechnicianAppState();
}

class _TechnicianAppState extends ConsumerState<TechnicianApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// العودة للتطبيق: محاولة إرسال فورية
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && ref.read(authProvider).value != null) ref.read(outboxProvider.notifier).flush();
  }

  @override
  Widget build(BuildContext context) {
    final locale = ref.watch(localeCodeProvider);
    return MaterialApp.router(
      title: 'DWAHI-ALBADIA Workshop',
      debugShowCheckedModeBanner: false,
      locale: Locale(locale),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: dwTheme(Brightness.light),
      darkTheme: dwTheme(Brightness.dark),
      routerConfig: ref.watch(routerProvider),
    );
  }
}

/// T-01 حتى الدخول، ثم المهام. عند الدخول يبدأ الإرسال مباشرة.
final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier(0);
  ref.listen(authProvider, (previous, next) {
    refresh.value++;
    if (next.value != null && previous?.value == null) ref.read(outboxProvider.notifier).flush();
  });
  ref.onDispose(refresh.dispose);
  return GoRouter(
    initialLocation: '/tasks',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authProvider);
      if (auth.isLoading && !auth.hasValue) return state.matchedLocation == '/splash' ? null : '/splash';
      final signedIn = auth.value != null;
      final at = state.matchedLocation;
      if (!signedIn) return at == '/login' ? null : '/login';
      if (at == '/login' || at == '/splash') return '/tasks';
      return null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const Scaffold(body: Center(child: DwLogo(size: 120)))),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/tasks', builder: (_, _) => const TasksScreen()),
      GoRoute(path: '/tasks/:id', builder: (_, state) => TaskScreen(state.pathParameters['id']!)),
      GoRoute(path: '/scan', builder: (_, _) => const ScanScreen()),
      GoRoute(path: '/sync', builder: (_, _) => const SyncScreen()),
      GoRoute(path: '/account', builder: (_, _) => const AccountScreen()),
    ],
  );
});
