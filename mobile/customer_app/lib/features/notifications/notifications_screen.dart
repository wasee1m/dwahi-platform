import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/api.dart';
import '../../core/strings.dart';
import '../auth/auth_controller.dart';

// M-21 الإشعارات (FR-NOT-01، US-23): مجمعة حسب التاريخ، وتفتح الشاشة المعنية، و"تعليم الكل كمقروء"

final notificationsProvider = FutureProvider.autoDispose<ApiPage>((ref) => ref.read(apiProvider).page('/me/notifications'));

/// عداد غير المقروء لأيقونة الجرس (07 §1.1)، صفر للزائر
final unreadCountProvider = FutureProvider.autoDispose<int>((ref) async {
  if (ref.watch(authProvider).value == null) return 0;
  final r = await ref.read(apiProvider).get('/me/notifications/count');
  return r['unread_count'] as int? ?? 0;
});

/// رابط الإشعار من الويب ← مسار التطبيق: /account/quotes/{id} ← /requests/{id}
String? appRoute(String link) {
  final m = RegExp(r'^/account/quotes/([0-9a-f-]{36})(#messages)?').firstMatch(link);
  if (m == null) return null;
  return m[2] != null ? '/requests/${m[1]}/messages' : '/requests/${m[1]}';
}

/// أيقونة الجرس مع العداد
class NotificationBell extends ConsumerWidget {
  const NotificationBell({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final n = ref.watch(unreadCountProvider).value ?? 0;
    return IconButton(
      tooltip: S.of(context).notifications,
      icon: Badge(isLabelVisible: n > 0, label: Text('$n'), child: const Icon(Icons.notifications_outlined)),
      onPressed: () => context.push('/notifications'),
    );
  }
}

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  String _bucket(DateTime d) {
    final now = DateTime.now();
    final day = DateTime(d.year, d.month, d.day);
    final today = DateTime(now.year, now.month, now.day);
    if (day == today) return 'today';
    return day == today.subtract(const Duration(days: 1)) ? 'yesterday' : 'older';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final async = ref.watch(notificationsProvider);
    final time = DateFormat.yMMMd(Localizations.localeOf(context).languageCode).add_Hm();
    final unread = (async.value?.meta['unread_count'] as int?) ?? 0;
    final labels = {'today': s.today, 'yesterday': s.yesterday, 'older': s.older};

    Future<void> refresh() async {
      ref.invalidate(unreadCountProvider);
      ref.invalidate(notificationsProvider);
      await ref.read(notificationsProvider.future);
    }

    return Scaffold(
      appBar: AppBar(title: Text(s.notifications), actions: [
        if (unread > 0)
          TextButton(onPressed: () async {
            await ref.read(apiProvider).post('/me/notifications/read-all', {});
            await refresh();
          }, child: Text(s.markAllRead)),
      ]),
      body: RefreshIndicator(
        onRefresh: refresh,
        child: async.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (_, _) => ListView(children: [Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(s.genericError)))]),
          data: (page) {
            if (page.rows.isEmpty) return ListView(children: [Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(s.noNotifications)))]);
            final groups = <String, List<Map<String, dynamic>>>{};
            for (final n in page.rows) {
              groups.putIfAbsent(_bucket(DateTime.parse(n['created_at'] as String).toLocal()), () => []).add(n);
            }
            return ListView(children: [
              for (final g in ['today', 'yesterday', 'older'])
                if (groups[g] != null) ...[
                  Padding(padding: const EdgeInsets.fromLTRB(16, 16, 16, 4), child: Text(labels[g]!, style: Theme.of(context).textTheme.labelLarge)),
                  for (final n in groups[g]!)
                    ListTile(
                      leading: n['read'] == true ? null : Icon(Icons.circle, size: 10, color: Theme.of(context).colorScheme.primary),
                      title: Text(n['title'] as String, style: TextStyle(fontWeight: n['read'] == true ? FontWeight.normal : FontWeight.w700)),
                      subtitle: Text('${n['body']}\n${time.format(DateTime.parse(n['created_at'] as String).toLocal())}'),
                      isThreeLine: true,
                      onTap: () async {
                        await ref.read(apiProvider).post('/me/notifications/${n['id']}/read', {});
                        ref.invalidate(unreadCountProvider);
                        ref.invalidate(notificationsProvider);
                        final route = appRoute(n['link'] as String);
                        if (route != null && context.mounted) context.push(route);
                      },
                    ),
                ],
            ]);
          },
        ),
      ),
    );
  }
}
