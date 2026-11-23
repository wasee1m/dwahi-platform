import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart' show DateFormat;

import '../core/api.dart';
import '../core/outbox.dart';
import '../core/strings.dart';
import 'auth.dart';

/// T-11: ما ينتظر الإرسال، وما رفضه الخادم بسببه، وزر الإرسال الآن
class SyncScreen extends ConsumerWidget {
  const SyncScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final o = ref.watch(outboxProvider);
    final fmt = DateFormat('d/M HH:mm:ss', Localizations.localeOf(context).languageCode);
    return Scaffold(
      appBar: AppBar(title: Text(s.syncCenter)),
      body: ListView(padding: EdgeInsets.fromLTRB(16, 16, 16, 24 + MediaQuery.paddingOf(context).bottom), children: [
        if (o.offline) Card(child: ListTile(leading: const Icon(Icons.cloud_off_outlined), title: Text(s.offlineBanner))),
        if (o.lastSync != null) Text(s.lastSync(fmt.format(o.lastSync!.toLocal()))),
        const SizedBox(height: 8),
        FilledButton.icon(
          key: const Key('send-now'),
          onPressed: o.sending || o.pending.isEmpty ? null : () => ref.read(outboxProvider.notifier).flush(),
          icon: o.sending ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.cloud_upload_outlined),
          label: Text(o.sending ? s.sending : s.sendNow),
        ),
        const SizedBox(height: 16),
        Text(s.pendingTitle, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        if (o.pending.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Text(s.nothingPending)),
        for (final e in o.pending)
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: Icon(e.localFile != null ? Icons.image_outlined : Icons.schedule),
            title: Text(s.eventLabel(e.type, e.payload)),
            subtitle: Text(fmt.format(e.occurredAt.toLocal())),
          ),
        if (o.rejected.isNotEmpty) ...[
          const SizedBox(height: 16),
          Text(s.rejectedTitle, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16, color: Theme.of(context).colorScheme.error)),
          Text(s.rejectedHint),
          for (final e in o.rejected)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.error_outline),
              title: Text(s.eventLabel(e.type, e.payload)),
              subtitle: Text('${e.message ?? e.code ?? ''}\n${fmt.format(e.occurredAt.toLocal())}'),
              isThreeLine: true,
              trailing: TextButton(onPressed: () => ref.read(outboxProvider.notifier).dismiss(e.id), child: Text(s.dismiss)),
            ),
        ],
      ]),
    );
  }
}

/// T-12: الفني ومهاراته واللغة والخروج (يحذّر من المعلق)
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  Future<void> _logout(BuildContext context, WidgetRef ref) async {
    final s = S.of(context);
    final pending = ref.read(outboxProvider).pending.length;
    if (pending > 0) {
      final go = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          content: Text(s.logoutPending(pending)),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: Text(s.cancel)),
            TextButton(key: const Key('logout-anyway'), onPressed: () => Navigator.pop(context, true), child: Text(s.logoutAnyway)),
          ],
        ),
      );
      if (go != true) return;
    }
    await ref.read(authProvider.notifier).logout();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final me = ref.watch(authProvider).value;
    final locale = ref.watch(localeCodeProvider);
    return Scaffold(
      appBar: AppBar(title: Text(s.account)),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(me?.fullName ?? '', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
        if (me?.branch != null) Text('${s.branch}: ${me!.branch}'),
        if (me != null && me.skills.isNotEmpty) ...[
          const SizedBox(height: 12),
          Text(s.skills, style: const TextStyle(fontWeight: FontWeight.w700)),
          Wrap(spacing: 6, children: [for (final k in me.skills) Chip(label: Text(k))]),
        ],
        const SizedBox(height: 16),
        Text(s.language, style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        SegmentedButton<String>(
          segments: const [ButtonSegment(value: 'ar', label: Text('العربية')), ButtonSegment(value: 'en', label: Text('English'))],
          selected: {locale},
          onSelectionChanged: (v) => ref.read(localeCodeProvider.notifier).set(v.first),
        ),
        const SizedBox(height: 32),
        OutlinedButton.icon(key: const Key('logout'), onPressed: () => _logout(context, ref), icon: const Icon(Icons.logout), label: Text(s.logout)),
      ]),
    );
  }
}
