import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:dwahi_ui/dwahi_ui.dart';

import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../auth/auth_controller.dart';
import '../catalog/catalog_screens.dart';
import '../notifications/notifications_screen.dart';

// M-06 الرئيسية: بحث موحد، وثلاثة إجراءات، و"بحاجة لإجراء منك"، والبنرات (FR-CMS-04)، والمعدات المميزة

final _bannersProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>(
    (ref) async => (await ref.read(apiProvider).page('/public/banners', query: {'placement': 'app_home'}, auth: false)).rows);
final _featuredProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>(
    (ref) async => (await ref.read(apiProvider).page('/equipment', query: {'featured': 1, 'limit': 6})).rows);
final awaitingProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  if (ref.watch(authProvider).value == null) return const [];
  return (await ref.read(apiProvider).page('/me/rfqs', query: {'tab': 'awaiting'})).rows;
});

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final t = Theme.of(context);
    final me = ref.watch(authProvider).value;
    final lang = Localizations.localeOf(context).languageCode;
    final banners = ref.watch(_bannersProvider).value ?? const [];
    final featured = ref.watch(_featuredProvider).value ?? const [];
    final awaiting = ref.watch(awaitingProvider).value ?? const [];

    return Scaffold(
      appBar: AppBar(title: const DwLogo(size: 36), actions: [if (me != null) const NotificationBell()]),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(_bannersProvider);
          ref.invalidate(_featuredProvider);
          ref.invalidate(awaitingProvider);
          ref.invalidate(unreadCountProvider);
        },
        child: ListView(padding: const EdgeInsets.all(16), children: [
          if (me != null) Text(s.hello(me.fullName.split(' ').first), style: t.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 12),
          TextField(
            readOnly: false,
            textInputAction: TextInputAction.search,
            decoration: InputDecoration(hintText: s.searchAll, prefixIcon: const Icon(Icons.search)),
            // رقم قطعة يحوي أرقاماً، فيذهب لبحث القطع، وإلا لتصفح المعدات
            onSubmitted: (q) => q.trim().isEmpty ? null
                : RegExp(r'\d').hasMatch(q) ? context.go(Uri(path: '/parts', queryParameters: {'q': q.trim()}).toString()) : context.go('/equipment'),
          ),
          const SizedBox(height: 16),
          IntrinsicHeight(child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            for (final (icon, label, route) in [
              (Icons.build_outlined, s.requestService, null),
              (Icons.search, s.findPart, '/parts'),
              (Icons.agriculture_outlined, s.browseEquipment, '/equipment'),
            ])
              Expanded(child: Padding(padding: const EdgeInsets.symmetric(horizontal: 4), child: Card(child: InkWell(
                onTap: route == null ? null : () => context.go(route),
                child: Padding(padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 8), child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(icon, size: 32),
                  const SizedBox(height: 8),
                  Text(label, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700)),
                  if (route == null) Text(s.soon, style: t.textTheme.bodySmall),
                ])),
              )))),
          ])),
          if (awaiting.isNotEmpty) ...[
            const SizedBox(height: 20),
            Text(s.needsAction, style: t.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
            for (final r in awaiting)
              Card(
                shape: RoundedRectangleBorder(side: BorderSide(color: t.colorScheme.primary, width: 2), borderRadius: BorderRadius.circular(3)),
                child: ListTile(
                  onTap: () => context.push('/requests/${r['id']}'),
                  title: Text('${(r['quotation'] as Map?)?['number'] ?? r['number']}'),
                  subtitle: Text((r['summary'] as List).join(lang == 'ar' ? '، ' : ', '), maxLines: 1, overflow: TextOverflow.ellipsis),
                  trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                    if (r['quotation'] != null) Text(formatMoney((r['quotation'] as Map)['total'] as Map<String, dynamic>, lang), style: const TextStyle(fontWeight: FontWeight.w800)),
                    if ((r['quotation'] as Map?)?['valid_until'] != null) Text(s.daysLeftText(daysLeft((r['quotation'] as Map)['valid_until'] as String)), style: const TextStyle(fontSize: 12)),
                  ]),
                ),
              ),
          ],
          if (banners.isNotEmpty) ...[
            const SizedBox(height: 20),
            SizedBox(height: 140, child: PageView(children: [
              for (final b in banners)
                Padding(padding: const EdgeInsets.symmetric(horizontal: 4), child: ClipRRect(borderRadius: BorderRadius.circular(3), child: GestureDetector(
                  onTap: b['link'] == null ? null : () {
                    final link = b['link'] as String;
                    // الرابط الداخلي /ar/parts أو /parts ← مسار التطبيق، والخارجي في المتصفح
                    final path = link.replaceFirst(RegExp(r'^/(ar|en)(?=/)'), '');
                    path.startsWith('/') ? context.go(path) : openExternal(link);
                  },
                  child: NetImage(b['image_url'] as String?),
                ))),
            ])),
          ],
          if (featured.isNotEmpty) ...[
            const SizedBox(height: 20),
            Text(s.featured, style: t.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
            const SizedBox(height: 8),
            for (final e in featured) EquipmentTile(e),
          ],
        ]),
      ),
    );
  }
}
