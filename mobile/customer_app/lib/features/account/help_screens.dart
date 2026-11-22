import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../catalog/catalog_screens.dart';

// M-22 المساعدة (الأسئلة الشائعة، والفروع، واتصال وواتساب) والسياسات (FR-CMS-03، FR-MSG-02)

const policySlugs = ['terms', 'privacy', 'returns', 'warranty'];

final faqsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async => (await ref.read(apiProvider).page('/public/faqs', auth: false)).rows);
final branchesProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async => (await ref.read(apiProvider).page('/public/branches', auth: false)).rows);
final policyProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, String>((ref, slug) => ref.read(apiProvider).get('/public/pages/$slug'));

class HelpScreen extends ConsumerWidget {
  const HelpScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final t = Theme.of(context).textTheme;
    final branches = ref.watch(branchesProvider);
    final faqs = ref.watch(faqsProvider);
    return Scaffold(
      appBar: AppBar(title: Text(s.help)),
      body: ListView(padding: screenPadding(context), children: [
        Text(s.branches, style: t.titleMedium),
        ...branches.when(
          loading: () => [const Center(child: CircularProgressIndicator())],
          error: (_, _) => [LoadError(onRetry: () => ref.invalidate(branchesProvider))],
          data: (rows) => [
            for (final b in rows)
              Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${b['name']}', style: t.titleSmall),
                if (b['address'] != null) Text('${b['address']}'),
                Wrap(spacing: 8, children: [
                  if (b['phone'] != null)
                    TextButton.icon(
                      icon: const Icon(Icons.call_outlined),
                      label: Text('${s.call} ${ltr(formatPhone(b['phone'] as String))}'),
                      onPressed: () => launchUrl(Uri(scheme: 'tel', path: b['phone'] as String)),
                    ),
                  if (b['whatsapp_sales'] != null)
                    TextButton.icon(
                      icon: const Icon(Icons.chat_outlined),
                      label: Text(s.whatsapp),
                      onPressed: () => openWhatsApp(b['whatsapp_sales'] as String, s.whatsappAbout(s.appName)),
                    ),
                ]),
              ]))),
          ],
        ),
        const SizedBox(height: 20),
        Text(s.faq, style: t.titleMedium),
        ...faqs.when(
          loading: () => [const Center(child: CircularProgressIndicator())],
          error: (_, _) => [LoadError(onRetry: () => ref.invalidate(faqsProvider))],
          data: (rows) => [
            for (final f in rows)
              ExpansionTile(
                tilePadding: EdgeInsets.zero,
                title: Text('${f['question']}'),
                expandedCrossAxisAlignment: CrossAxisAlignment.start,
                children: [Padding(padding: const EdgeInsets.only(bottom: 12), child: Text('${f['answer']}'))],
              ),
          ],
        ),
      ]),
    );
  }
}

class PoliciesScreen extends StatelessWidget {
  const PoliciesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(s.policies)),
      body: ListView(children: [
        for (final slug in policySlugs)
          ListTile(title: Text(s.policy(slug)), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/account/policies/$slug')),
      ]),
    );
  }
}

class PolicyScreen extends ConsumerWidget {
  const PolicyScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final async = ref.watch(policyProvider(slug));
    return Scaffold(
      appBar: AppBar(title: Text(s.policy(slug))),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(policyProvider(slug))),
        data: (p) => ListView(padding: screenPadding(context), children: [
          // Markdown محدود ومنقّى في الخادم: العناوين بخط عريض والباقي نص
          for (final line in '${p['body'] ?? ''}'.split('\n'))
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: line.startsWith('#')
                  ? Text(line.replaceFirst(RegExp(r'^#+\s*'), ''), style: Theme.of(context).textTheme.titleMedium)
                  : Text(line.replaceFirst(RegExp(r'^>\s?'), '').replaceFirst(RegExp(r'^[-*]\s'), '• ').replaceAll('**', '')),
            ),
        ]),
      ),
    );
  }
}
