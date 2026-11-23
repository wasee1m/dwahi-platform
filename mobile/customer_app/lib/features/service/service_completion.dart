import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/service_strings.dart';
import '../../core/strings.dart';
import '../account/account_common.dart';

/// M-17 (S9): جاهز للاستلام بالمستحق، والتقديرات الإضافية، وتقرير الخدمة بصوره ونتيجة الاختبار، وإيصال التسليم، والفاتورة، والتقييم
class ServiceCompletion extends ConsumerWidget {
  const ServiceCompletion({super.key, required this.id, required this.data, required this.onChanged});
  final String id;
  final Map<String, dynamic> data;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final lang = Localizations.localeOf(context).languageCode;
    final t = Theme.of(context).textTheme;
    final pickup = data['pickup'] as Map<String, dynamic>?;
    final handover = data['handover'] as Map<String, dynamic>?;
    final invoice = data['invoice'] as Map<String, dynamic>?;
    final review = data['review'] as Map<String, dynamic>?;
    final reports = (data['reports'] as List? ?? const []).cast<Map<String, dynamic>>();
    final additional = (data['additional'] as List? ?? const []).cast<Map<String, dynamic>>();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (pickup != null)
        Card(
          color: Theme.of(context).colorScheme.primaryContainer,
          child: ListTile(
            title: Text(s.readyForPickup, style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text('${s.amountDue}: ${formatMoney(pickup['due'] as Map<String, dynamic>?, lang)}\n${s.pickupHint}'),
            isThreeLine: true,
          ),
        ),
      for (final a in additional)
        ListTile(
          contentPadding: EdgeInsets.zero,
          title: Text('${s.additionalEstimate} · ${a['description']}'),
          subtitle: a['quotation'] == null ? null : Text(formatMoney((a['quotation'] as Map)['total'] as Map<String, dynamic>?, lang)),
          trailing: a['status'] == 'quoted' && a['rfq_id'] != null
              ? FilledButton(onPressed: () => context.push('/requests/${a['rfq_id']}'), child: Text(s.reviewEstimate))
              : Text(s.additionalStatus('${a['status']}')),
        ),
      for (final r in reports)
        if (r['report'] != null) _Report(r, lang: lang),
      if (handover != null)
        ListTile(
          contentPadding: EdgeInsets.zero,
          title: Text('${s.handedOver} · ${handover['receiver_name']}'),
          subtitle: handover['handed_over_at'] == null ? null : Text(formatDateTime(handover['handed_over_at'] as String, lang)),
          trailing: handover['receipt_url'] == null ? null : TextButton(onPressed: () => openExternal(handover['receipt_url'] as String), child: Text(s.handoverReceipt)),
        ),
      if (invoice != null)
        ListTile(
          contentPadding: EdgeInsets.zero,
          title: Text('${s.invoiceLabel} ${ltr('${invoice['number']}')}'),
          trailing: Text(formatMoney(invoice['total'] as Map<String, dynamic>?, lang)),
          onTap: () => context.push('/account/invoices'),
        ),
      if (review != null) Text('${s.yourRating}: ${'★' * ((review['rating'] as num?)?.toInt() ?? 0)}', style: t.bodyMedium),
      if (data['can_review'] == true)
        FilledButton.icon(
          key: const Key('rate'),
          icon: const Icon(Icons.star_outline),
          label: Text(s.rateService),
          onPressed: () async {
            final body = await showModalBottomSheet<Map<String, dynamic>>(context: context, isScrollControlled: true, builder: (_) => const ReviewSheet());
            if (body == null || !context.mounted) return;
            if (await runAction(context, () => ref.read(apiProvider).post('/me/service-requests/$id/review', body), done: s.thanksForRating)) onChanged();
          },
        ),
    ]);
  }
}

class _Report extends StatelessWidget {
  const _Report(this.r, {required this.lang});
  final Map<String, dynamic> r;
  final String lang;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final rep = (r['report'] as Map).cast<String, dynamic>();
    final tests = (r['tests'] as List? ?? const []).cast<Map<String, dynamic>>();
    Widget photos(String key, String label) {
      final urls = (r[key] as List? ?? const []).whereType<String>().toList();
      if (urls.isEmpty) return const SizedBox.shrink();
      return Padding(
        padding: const EdgeInsets.only(top: 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 4),
          SizedBox(
            height: 80,
            child: ListView(scrollDirection: Axis.horizontal, children: [
              for (final u in urls) Padding(padding: const EdgeInsetsDirectional.only(end: 6), child: SizedBox(width: 80, child: NetImage(u, height: 80))),
            ]),
          ),
        ]),
      );
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${s.serviceReport}${r['no_charge'] == true ? ' · ${s.noCharge}' : ''}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          const SizedBox(height: 6),
          Text('${s.findingsLabel}: ${rep['findings']}'),
          if ('${rep['actions_taken'] ?? ''}'.isNotEmpty) Text('${s.actionsLabel}: ${rep['actions_taken']}'),
          if (rep['recommendations'] != null) Text('${s.recommendationsLabel}: ${rep['recommendations']}'),
          if (rep['hour_meter_reading'] != null) Text('${s.hourReading}: ${ltr('${rep['hour_meter_reading']}')}'),
          for (final x in tests) Text('${s.testResult}: ${x['template'] ?? ''} · ${x['result'] == 'pass' ? s.testPass : s.testFail}'),
          photos('before', s.beforePhotos),
          photos('after', s.afterPhotos),
          if (rep['pdf_url'] != null)
            Align(alignment: AlignmentDirectional.centerStart, child: TextButton.icon(onPressed: () => openExternal(rep['pdf_url'] as String),
                icon: const Icon(Icons.picture_as_pdf_outlined), label: Text(s.reportPdf))),
        ]),
      ),
    );
  }
}

/// US-20: نجوم إجمالية ومعايير اختيارية وتعليق وموافقة على النشر
class ReviewSheet extends StatefulWidget {
  const ReviewSheet({super.key});

  @override
  State<ReviewSheet> createState() => _ReviewSheetState();
}

class _ReviewSheetState extends State<ReviewSheet> {
  final _ratings = <String, int>{};
  final _comment = TextEditingController();
  bool _publish = false;

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  Widget _stars(String key, String label) => Row(children: [
        Expanded(child: Text(label)),
        for (var n = 1; n <= 5; n++)
          IconButton(
            key: Key('$key-$n'),
            visualDensity: VisualDensity.compact,
            onPressed: () => setState(() => _ratings[key] = n),
            icon: Icon(n <= (_ratings[key] ?? 0) ? Icons.star : Icons.star_border, color: Colors.amber.shade700),
          ),
      ]);

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.viewInsetsOf(context).bottom + MediaQuery.paddingOf(context).bottom),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(s.rateService, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          _stars('rating', s.overallRating),
          _stars('timeliness_rating', s.timeliness),
          _stars('quality_rating', s.quality),
          _stars('communication_rating', s.communication),
          TextField(controller: _comment, maxLength: 2000, minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: s.ratingComment)),
          CheckboxListTile(value: _publish, onChanged: (v) => setState(() => _publish = v ?? false), title: Text(s.publishConsent), contentPadding: EdgeInsets.zero),
          FilledButton(
            key: const Key('send-rating'),
            onPressed: _ratings['rating'] == null
                ? null
                : () => Navigator.pop(context, {..._ratings, 'comment': _comment.text.trim().isEmpty ? null : _comment.text.trim(), 'publish_consent': _publish}),
            child: Text(s.sendRating),
          ),
        ]),
      ),
    );
  }
}
