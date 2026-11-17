import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api.dart';
import '../../core/strings.dart';
import '../../widgets/logo.dart';
import '../auth/auth_controller.dart';

/// M-06 الرئيسية: هيكل فقط في S1. الكتالوج والطلبات والصيانة تُضاف مع سبرنتاتها.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final me = ref.watch(authProvider).value;
    return Scaffold(
      appBar: AppBar(title: const DwLogo(size: 36)),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text(s.hello(me?.fullName.split(' ').first ?? ''), style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 12),
          Text(s.comingSoon),
        ],
      ),
    );
  }
}

/// M-22 حسابي: البيانات، واللغة، والخروج
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final me = ref.watch(authProvider).value;
    final locale = ref.watch(localeCodeProvider);
    return Scaffold(
      appBar: AppBar(title: Text(s.myAccount)),
      body: ListView(
        children: [
          ListTile(title: Text(me?.fullName ?? ''), subtitle: Directionality(textDirection: TextDirection.ltr, child: Text(me?.phone ?? '', textAlign: TextAlign.start))),
          for (final org in me?.organizations ?? const <Map<String, dynamic>>[])
            ListTile(leading: const Icon(Icons.business), title: Text(org['name'] as String)),
          const Divider(),
          ListTile(
            leading: const Icon(Icons.language),
            title: Text(s.language),
            trailing: SegmentedButton<String>(
              segments: const [ButtonSegment(value: 'ar', label: Text('ع')), ButtonSegment(value: 'en', label: Text('EN'))],
              selected: {locale},
              onSelectionChanged: (v) => ref.read(localeCodeProvider.notifier).set(v.first),
            ),
          ),
          ListTile(leading: const Icon(Icons.logout), title: Text(s.logout), onTap: () => ref.read(authProvider.notifier).logout()),
        ],
      ),
    );
  }
}
