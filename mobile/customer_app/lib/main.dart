import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:dwahi_ui/dwahi_ui.dart';

import 'core/api.dart';
import 'features/auth/auth_controller.dart';
import 'features/auth/auth_screens.dart';
import 'features/auth/onboarding_screen.dart';
import 'core/strings.dart';
import 'features/catalog/catalog_screens.dart';
import 'features/home/home_screens.dart';
import 'features/notifications/notifications_screen.dart';
import 'features/quotes/messages_screen.dart';
import 'features/quotes/quote_screens.dart';

void main() => runApp(const ProviderScope(child: CustomerApp()));

/// تطبيق العملاء (07 §1): عربي افتراضياً بالاتجاه من اليمين، ووضع داكن حسب الجهاز
class CustomerApp extends ConsumerWidget {
  const CustomerApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeCodeProvider);
    return MaterialApp.router(
      title: 'DWAHI-ALBADIA',
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

/// يتصفحها الزائر دون دخول (07 M-02 "تصفح كزائر"): الرئيسية والكتالوج. الطلبات والحساب تطلب الدخول.
bool isGuestPath(String path) => path == '/home' || path.startsWith('/equipment') || path.startsWith('/parts');

/// التنقل (07 §1.1) مع حارس الدخول: الجلسة تُستعاد أولاً (M-01)، ثم الترحيب أو الرئيسية
final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier(0);
  ref.listen(authProvider, (_, _) => refresh.value++);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    initialLocation: '/splash',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authProvider);
      final path = state.uri.path;
      if (auth.isLoading) return path == '/splash' ? null : '/splash';
      final me = auth.value;
      final public = path == '/welcome' || path.startsWith('/login');
      if (me == null) {
        if (public || isGuestPath(path)) return null;
        return path == '/splash' ? '/welcome' : '/login'; // زائر طلب ما يحتاج دخولاً
      }
      if (me.needsOnboarding) return path == '/onboarding' ? null : '/onboarding';
      if (public || path == '/splash' || path == '/onboarding') return '/home';
      return null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const Scaffold(body: Center(child: DwLogo(size: 96)))),
      GoRoute(path: '/welcome', builder: (_, _) => const WelcomeScreen()),
      GoRoute(path: '/login', builder: (_, _) => const PhoneScreen()),
      GoRoute(
        path: '/login/verify',
        builder: (_, state) {
          final args = state.extra as ({String phone, String? debug})?;
          return OtpScreen(phone: args?.phone ?? '', debugCode: args?.debug);
        },
      ),
      GoRoute(path: '/onboarding', builder: (_, _) => const OnboardingScreen()),
      ShellRoute(
        builder: (context, state, child) => _Tabs(location: state.uri.path, child: child),
        routes: [
          GoRoute(path: '/home', builder: (_, _) => const HomeScreen()),
          GoRoute(path: '/equipment', builder: (_, _) => const EquipmentScreen()),
          GoRoute(path: '/parts', builder: (_, state) => PartsScreen(initialQuery: state.uri.queryParameters['q'])),
          GoRoute(path: '/requests', builder: (_, _) => const RequestsScreen()),
          GoRoute(path: '/account', builder: (_, _) => const AccountScreen()),
        ],
      ),
      // شاشات فوق التبويبات (تُفتح بـ push، وبالرابط العميق من الإشعار 07 §3)
      GoRoute(path: '/equipment/:slug', builder: (_, state) => EquipmentDetailScreen(slug: state.pathParameters['slug']!)),
      GoRoute(path: '/parts/:brand/:number', builder: (_, state) => PartDetailScreen(brand: state.pathParameters['brand']!, number: state.pathParameters['number']!)),
      GoRoute(
        path: '/requests/new',
        builder: (_, state) {
          final q = state.uri.queryParameters;
          return NewRfqScreen(type: q['type'] ?? 'parts', listingId: q['listing'], partId: q['part'], label: q['label'], qty: q['qty']);
        },
      ),
      GoRoute(path: '/requests/:id', builder: (_, state) => QuoteDetailScreen(id: state.pathParameters['id']!)),
      GoRoute(path: '/requests/:id/messages', builder: (_, state) => MessagesScreen(rfqId: state.pathParameters['id']!)),
      GoRoute(path: '/notifications', builder: (_, _) => const NotificationsScreen()),
    ],
  );
});

/// الشريط السفلي بخمسة تبويبات (07 §1.1): الرئيسية · المعدات · القطع · طلباتي · حسابي
class _Tabs extends StatelessWidget {
  const _Tabs({required this.location, required this.child});
  final String location;
  final Widget child;

  static const _paths = ['/home', '/equipment', '/parts', '/requests', '/account'];

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final index = _paths.indexWhere((p) => location.startsWith(p)).clamp(0, _paths.length - 1);
    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: (i) => context.go(_paths[i]),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.home_outlined), label: s.tabHome),
          NavigationDestination(icon: const Icon(Icons.agriculture_outlined), label: s.tabEquipment),
          NavigationDestination(icon: const Icon(Icons.settings_input_component_outlined), label: s.tabParts),
          NavigationDestination(icon: const Icon(Icons.receipt_long_outlined), label: s.tabRequests),
          NavigationDestination(icon: const Icon(Icons.person_outline), label: s.tabAccount),
        ],
      ),
    );
  }
}
