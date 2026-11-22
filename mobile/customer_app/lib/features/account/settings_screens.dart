import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../auth/auth_controller.dart';
import '../catalog/catalog_screens.dart';
import 'account_common.dart';

// M-22: تفضيلات الإشعارات، والأجهزة والجلسات، وبياناتي وحذف الحساب (C-20، FR-NOT-03، FR-AUTH-04، FR-ACC-06)

final preferencesProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/me/notification-preferences'));
final sessionsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async => (await ref.read(apiProvider).page('/me/sessions')).rows);
final exportProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/me/data-export'));
final deletionProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/me/deletion'));

class PreferencesScreen extends ConsumerStatefulWidget {
  const PreferencesScreen({super.key});

  @override
  ConsumerState<PreferencesScreen> createState() => _PreferencesScreenState();
}

class _PreferencesScreenState extends ConsumerState<PreferencesScreen> {
  bool busy = false;

  Future<void> _put(String path, Map<String, dynamic> body) async {
    setState(() => busy = true);
    await runAction(context, () async {
      await ref.read(apiProvider).put(path, body);
      ref.invalidate(preferencesProvider);
    });
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final async = ref.watch(preferencesProvider);
    return Scaffold(
      appBar: AppBar(title: Text(s.notificationPrefs)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(preferencesProvider)),
        data: (d) {
          final consent = d['marketing_consent'] == true;
          final cells = (d['preferences'] as List)
              .cast<Map<String, dynamic>>()
              .where((c) => c['state'] != 'unused' && (c['category'] != 'marketing' || consent));
          final categories = {for (final c in cells) c['category'] as String};
          return ListView(padding: screenPadding(context), children: [
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(s.marketingMessages),
              subtitle: Text(s.marketingHint),
              value: consent,
              onChanged: busy ? null : (v) => _put('/me/marketing-consent', {'granted': v}),
            ),
            for (final category in categories) ...[
              const Divider(),
              Text(s.category(category), style: Theme.of(context).textTheme.titleMedium),
              for (final c in cells.where((c) => c['category'] == category))
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(s.channel(c['channel'] as String)),
                  subtitle: c['state'] == 'locked' ? Text(s.mandatory) : c['has_mandatory'] == true ? Text(s.exceptMandatory) : null,
                  value: c['enabled'] == true,
                  onChanged: busy || c['state'] == 'locked'
                      ? null
                      : (v) => _put('/me/notification-preferences', {
                            'preferences': [
                              {'category': category, 'channel': c['channel'], 'enabled': v},
                            ],
                          }),
                ),
            ],
            const SizedBox(height: 8),
            Text(s.mandatoryHint, style: Theme.of(context).textTheme.bodySmall),
          ]);
        },
      ),
    );
  }
}

class SessionsScreen extends ConsumerWidget {
  const SessionsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final lang = Localizations.localeOf(context).languageCode;
    final async = ref.watch(sessionsProvider);
    return Scaffold(
      appBar: AppBar(title: Text(s.devices)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(sessionsProvider)),
        data: (rows) => ListView(padding: screenPadding(context), children: [
          for (final x in rows)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: Icon(x['client'] == 'customer_app' ? Icons.smartphone : Icons.language),
              title: Text('${s.client(x['client'] as String)}${x['current'] == true ? ' · ${s.thisDevice}' : ''}'),
              subtitle: x['last_used_at'] == null ? null : Text(formatDateTime(x['last_used_at'] as String, lang)),
              trailing: x['current'] == true
                  ? null
                  : TextButton(
                      child: Text(s.endSession),
                      onPressed: () => runAction(context, () async {
                        await ref.read(apiProvider).delete('/me/sessions/${x['id']}');
                        ref.invalidate(sessionsProvider);
                      }),
                    ),
            ),
          const SizedBox(height: 16),
          OutlinedButton(
            child: Text(s.logoutAll),
            onPressed: () async {
              if (!await askConfirm(context, s.logoutAllQuestion) || !context.mounted) return;
              final auth = ref.read(authProvider.notifier);
              final router = GoRouter.of(context);
              if (!await runAction(context, () => ref.read(apiProvider).post('/auth/logout-all', {}))) return;
              router.go('/home');
              await auth.endLocally();
            },
          ),
        ]),
      ),
    );
  }
}

class PrivacyScreen extends ConsumerStatefulWidget {
  const PrivacyScreen({super.key});

  @override
  ConsumerState<PrivacyScreen> createState() => _PrivacyScreenState();
}

class _PrivacyScreenState extends ConsumerState<PrivacyScreen> {
  final code = TextEditingController();
  bool sent = false, busy = false;
  String? debug;

  @override
  void dispose() {
    code.dispose();
    super.dispose();
  }

  Future<void> _delete() async {
    final s = S.of(context);
    final api = ref.read(apiProvider);
    final auth = ref.read(authProvider.notifier);
    final router = GoRouter.of(context);
    final messenger = ScaffoldMessenger.of(context);
    setState(() => busy = true);
    final ok = await runAction(context, () async {
      if (sent) {
        await api.post('/me/deletion', {'code': code.text.trim()});
      } else {
        debug = (await api.post('/me/deletion/request', {}))['debug_code'] as String?;
      }
    });
    if (ok && sent) {
      // زائر في الرئيسية، لا شاشة دخول لحساب لم يعد موجوداً
      router.go('/home');
      await auth.endLocally();
      messenger.showSnackBar(SnackBar(content: Text(s.accountDeleted)));
      return;
    }
    if (mounted) {
      setState(() {
        busy = false;
        sent = sent || ok;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final t = Theme.of(context);
    final lang = Localizations.localeOf(context).languageCode;
    final export = ref.watch(exportProvider);
    final deletion = ref.watch(deletionProvider);
    final latest = export.value?['id'] == null ? null : export.value;
    final status = latest?['status'];

    return Scaffold(
      appBar: AppBar(title: Text(s.privacy)),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(exportProvider);
          ref.invalidate(deletionProvider);
        },
        child: ListView(padding: screenPadding(context), children: [
          Text(s.exportTitle, style: t.textTheme.titleMedium),
          Text(s.exportIntro),
          const SizedBox(height: 8),
          if (export.hasError) LoadError(onRetry: () => ref.invalidate(exportProvider)),
          if (status == 'pending') Text(s.exportPending),
          if (status == 'failed') Text(s.exportFailed, style: TextStyle(color: t.colorScheme.error)),
          if (status == 'ready' && latest?['url'] != null)
            FilledButton.icon(
              // الرابط موقّع 15 دقيقة، فيُجلب من جديد لحظة الضغط
              onPressed: () => runAction(context, () async {
                final url = (await ref.read(apiProvider).get('/me/data-export'))['url'] as String?;
                if (url != null) await openExternal(url);
              }),
              icon: const Icon(Icons.download),
              label: Text('${s.exportDownload} · ${formatDateTime(latest!['expires_at'] as String, lang)}'),
            ),
          if (export.hasValue && status != 'pending') ...[
            const SizedBox(height: 8),
            OutlinedButton(
              onPressed: () => runAction(context, () async {
                await ref.read(apiProvider).post('/me/data-export', {});
                ref.invalidate(exportProvider);
              }),
              child: Text(s.exportRequest),
            ),
          ],
          const Divider(height: 40),
          Text(s.deleteAccount, style: t.textTheme.titleMedium),
          ...deletion.when(
            loading: () => [const Center(child: CircularProgressIndicator())],
            error: (_, _) => [LoadError(onRetry: () => ref.invalidate(deletionProvider))],
            data: (d) {
              final companies = (d['companies'] as List).cast<Map<String, dynamic>>();
              final requests = (d['requests'] as List).cast<Map<String, dynamic>>();
              return [
                Text(s.deleteIntro(d['grace_days'] as int)),
                if (companies.isNotEmpty) Text(s.transferFirst, style: TextStyle(color: t.colorScheme.error)),
                for (final c in companies) ListTile(title: Text(c['name'] as String), onTap: () => context.push('/account/company')),
                if (requests.isNotEmpty) Text(s.closeFirst, style: TextStyle(color: t.colorScheme.error)),
                for (final r in requests) ListTile(title: Text(ltr(r['number'] as String)), onTap: () => context.push('/requests/${r['id']}')),
                if (d['can_delete'] == true) ...[
                  const SizedBox(height: 12),
                  if (debug != null) Text('DEV: $debug'),
                  if (sent) Input(code, s.codeTitle, latin: true, keyboard: TextInputType.number),
                  OutlinedButton(
                    style: OutlinedButton.styleFrom(foregroundColor: t.colorScheme.error),
                    onPressed: busy ? null : _delete,
                    child: Text(sent ? s.deleteNow : s.sendDeleteCode),
                  ),
                ],
              ];
            },
          ),
        ]),
      ),
    );
  }
}
