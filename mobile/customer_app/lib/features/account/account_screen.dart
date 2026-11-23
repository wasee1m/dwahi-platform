import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/strings.dart';
import '../auth/auth_controller.dart';

/// يطابق version في pubspec.yaml (اختبار يتحقق)
const appVersion = '1.0.0';

/// M-22 حسابي (C-02 + C-03 + C-04 + C-18 + C-20)
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final me = ref.watch(authProvider).value;
    final locale = ref.watch(localeCodeProvider);

    Widget link(IconData icon, String label, String path) => ListTile(
          leading: Icon(icon),
          title: Text(label),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => context.push(path),
        );

    return Scaffold(
      appBar: AppBar(title: Text(s.myAccount)),
      body: ListView(children: [
        ListTile(
          title: Text(me?.fullName ?? ''),
          subtitle: Directionality(textDirection: TextDirection.ltr, child: Text(formatPhone(me?.phone ?? ''), textAlign: TextAlign.start)),
        ),
        for (final org in me?.organizations ?? const <Map<String, dynamic>>[])
          ListTile(dense: true, leading: const Icon(Icons.business), title: Text('${org['name']} · ${s.roleName(org['role'] as String? ?? 'member')}')),
        const Divider(),
        link(Icons.agriculture_outlined, s.myFleet, '/account/fleet'),
        link(Icons.business_outlined, s.companyAndMembers, '/account/company'),
        link(Icons.place_outlined, s.addresses, '/account/addresses'),
        link(Icons.receipt_outlined, s.invoices, '/account/invoices'),
        const Divider(),
        link(Icons.person_outline, s.profile, '/account/profile'),
        link(Icons.notifications_outlined, s.notifications, '/notifications'),
        link(Icons.tune, s.notificationPrefs, '/account/preferences'),
        ListTile(
          leading: const Icon(Icons.language),
          title: Text(s.language),
          trailing: SegmentedButton<String>(
            segments: const [ButtonSegment(value: 'ar', label: Text('ع')), ButtonSegment(value: 'en', label: Text('EN'))],
            selected: {locale},
            onSelectionChanged: (v) => ref.read(localeCodeProvider.notifier).set(v.first),
          ),
        ),
        link(Icons.devices_outlined, s.devices, '/account/sessions'),
        link(Icons.shield_outlined, s.privacy, '/account/privacy'),
        const Divider(),
        link(Icons.help_outline, s.help, '/account/help'),
        link(Icons.description_outlined, s.policies, '/account/policies'),
        ListTile(leading: const Icon(Icons.logout), title: Text(s.logout), onTap: () => ref.read(authProvider.notifier).logout()),
        Padding(padding: const EdgeInsets.all(16), child: Text(s.appVersion(appVersion), style: Theme.of(context).textTheme.bodySmall)),
      ]),
    );
  }
}
