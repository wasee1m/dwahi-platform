import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:dwahi_ui/dwahi_ui.dart';

import 'core/api.dart';
import 'features/auth/auth_controller.dart';
import 'features/auth/auth_screens.dart';
import 'features/auth/onboarding_screen.dart';
import 'features/home/home_screens.dart';

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
      if (me == null) return public ? null : '/welcome';
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
          GoRoute(path: '/account', builder: (_, _) => const AccountScreen()),
        ],
      ),
    ],
  );
});

/// الشريط السفلي (07 §1.1): يبدأ بالرئيسية وحسابي، وتُضاف التبويبات مع الميزات
class _Tabs extends StatelessWidget {
  const _Tabs({required this.location, required this.child});
  final String location;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final index = location.startsWith('/account') ? 1 : 0;
    final ar = Localizations.localeOf(context).languageCode == 'ar';
    return Scaffold(
      body: child,
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: (i) => context.go(i == 0 ? '/home' : '/account'),
        destinations: [
          NavigationDestination(icon: const Icon(Icons.home_outlined), label: ar ? 'الرئيسية' : 'Home'),
          NavigationDestination(icon: const Icon(Icons.person_outline), label: ar ? 'حسابي' : 'Account'),
        ],
      ),
    );
  }
}
