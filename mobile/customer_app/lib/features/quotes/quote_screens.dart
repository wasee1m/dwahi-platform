import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../account/account_common.dart' hide toast;
import '../auth/auth_controller.dart';
import '../auth/error_text.dart';
import '../catalog/catalog_screens.dart';
import 'pickers.dart';

// M-16 طلباتي (تبويب العروض)، M-18 تفاصيل العرض، M-24 طلب عرض سعر جديد (FR-RFQ-01، FR-RFQ-03، FR-RFQ-04، US-12، US-13)

final rfqListProvider = FutureProvider.autoDispose.family<ApiPage, String>((ref, tab) => ref.read(apiProvider).page('/me/rfqs', query: {'tab': tab}));
final rfqDetailProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, String>((ref, id) => ref.read(apiProvider).get('/me/rfqs/$id'));

String _lang(BuildContext c) => Localizations.localeOf(c).languageCode;

void _toast(BuildContext context, String text) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));

/// M-16: تبويبات علوية الصيانة · العروض · القطع. العروض الآن، والبقية مع وحداتها (S6، S7).
class RequestsScreen extends StatelessWidget {
  const RequestsScreen({super.key});
  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return DefaultTabController(
      length: 3,
      initialIndex: 1,
      child: Scaffold(
        appBar: AppBar(
          title: Text(s.tabRequests),
          bottom: TabBar(tabs: [Tab(text: s.service), Tab(text: s.quotes), Tab(text: s.partsOrders)]),
        ),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => context.push('/requests/new'), icon: const Icon(Icons.add), label: Text(s.newRequest)),
        body: TabBarView(children: [
          Center(child: Text(s.soon)),
          const _QuoteLists(),
          Center(child: Text(s.soon)),
        ]),
      ),
    );
  }
}

class _QuoteLists extends ConsumerStatefulWidget {
  const _QuoteLists();
  @override
  ConsumerState<_QuoteLists> createState() => _QuoteListsState();
}

class _QuoteListsState extends ConsumerState<_QuoteLists> {
  static const tabs = ['awaiting', 'review', 'done', 'closed'];
  String tab = 'awaiting';

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final ar = _lang(context) == 'ar';
    final labels = {'awaiting': ar ? 'بانتظاري' : 'Awaiting me', 'review': ar ? 'قيد المراجعة' : 'In review', 'done': ar ? 'مكتملة' : 'Done', 'closed': ar ? 'مغلقة' : 'Closed'};
    final async = ref.watch(rfqListProvider(tab));
    final counts = (async.value?.meta['counts'] as Map?)?.cast<String, dynamic>() ?? const {};

    return RefreshIndicator(
      onRefresh: () => ref.refresh(rfqListProvider(tab).future),
      child: ListView(padding: const EdgeInsets.all(12), children: [
        SingleChildScrollView(scrollDirection: Axis.horizontal, child: Row(children: [
          for (final t in tabs)
            Padding(padding: const EdgeInsetsDirectional.only(end: 8), child: ChoiceChip(
              label: Text('${labels[t]}${(counts[t] ?? 0) > 0 ? ' (${counts[t]})' : ''}'),
              selected: tab == t, onSelected: (_) => setState(() => tab = t))),
        ])),
        const SizedBox(height: 8),
        ...async.when(
          loading: () => [const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))],
          error: (_, _) => [LoadError(onRetry: () => ref.invalidate(rfqListProvider(tab)))],
          data: (page) => page.rows.isEmpty
              ? [Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(s.noRequests)))]
              : [for (final r in page.rows) _RfqTile(r)],
        ),
      ]),
    );
  }
}

class _RfqTile extends StatelessWidget {
  const _RfqTile(this.r);
  final Map<String, dynamic> r;
  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final q = r['quotation'] as Map<String, dynamic>?;
    final left = q?['status'] == 'sent' && q?['valid_until'] != null ? daysLeft(q!['valid_until'] as String) : null;
    return Card(child: ListTile(
      onTap: () => context.push('/requests/${r['id']}'),
      title: Text('${r['number']} · ${s.typeName(r['type'] as String)}'),
      subtitle: Text([
        (r['summary'] as List).join(s.listSeparator),
        if (r['account'] != null) '${(r['account'] as Map)['name']}',
      ].join(' · '), maxLines: 2, overflow: TextOverflow.ellipsis),
      trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
        Text(s.rfqStatus(r['status'] as String), style: const TextStyle(fontWeight: FontWeight.w700)),
        if (q != null) Text(formatMoney(q['total'] as Map<String, dynamic>, _lang(context))),
        if (left != null) Text(s.daysLeftText(left), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
      ]),
    ));
  }
}

/// M-18: البنود والإجمالي والصلاحية، و PDF، وشريط سفلي بالرد (أو "إرسال للاعتماد")، و"اطلب تجديد العرض"
class QuoteDetailScreen extends ConsumerWidget {
  const QuoteDetailScreen({super.key, required this.id});
  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final async = ref.watch(rfqDetailProvider(id));
    return Scaffold(
      appBar: AppBar(
        title: Text(async.value?['number'] as String? ?? ''),
        actions: [
          IconButton(tooltip: s.messages, icon: Badge(isLabelVisible: (async.value?['unread_messages'] ?? 0) > 0, child: const Icon(Icons.chat_bubble_outline)),
              onPressed: () => context.push('/requests/$id/messages')),
        ],
      ),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(rfqDetailProvider(id))),
        data: (r) => RefreshIndicator(onRefresh: () => ref.refresh(rfqDetailProvider(id).future), child: _QuoteBody(r: r)),
      ),
      bottomNavigationBar: async.value == null ? null : _ReplyBar(r: async.value!),
    );
  }
}

class _QuoteBody extends ConsumerWidget {
  const _QuoteBody({required this.r});
  final Map<String, dynamic> r;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final t = Theme.of(context);
    final lang = _lang(context);
    final quotes = (r['quotations'] as List).cast<Map<String, dynamic>>();
    final q = quotes.isEmpty ? null : quotes.first;
    final items = (r['items'] as List).cast<Map<String, dynamic>>();

    return ListView(padding: const EdgeInsets.all(16), children: [
      Text('${s.typeName(r['type'] as String)} · ${s.rfqStatus(r['status'] as String)}', style: t.textTheme.titleMedium),
      if (r['status'] == 'lost' && r['lost_reason'] != null && r['lost_reason'] != 'expired')
        Padding(padding: const EdgeInsets.only(top: 8), child: Text('${r['lost_reason']}'.replaceFirst(RegExp(r'^rejected:?\s*'), ''), style: TextStyle(color: t.colorScheme.error))),
      if (r['account'] != null) Text('${s.account}: ${(r['account'] as Map)['name']}'),
      if (r['equipment'] != null) Text('${s.relatedEquipment}: ${(r['equipment'] as Map)['name']}'),
      const SizedBox(height: 12),
      if (q == null && r['status'] != 'cancelled' && r['status'] != 'lost') Card(child: Padding(padding: const EdgeInsets.all(16), child: Text(s.noQuoteYet))),
      if (q != null) _QuoteCard(q: q, lang: lang),
      const SizedBox(height: 16),
      Text(s.requestItems, style: t.textTheme.titleSmall),
      for (final i in items)
        ListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(i['title'] as String),
          subtitle: i['specs'] == null ? null : Text(_specs(s, (i['specs'] as Map).cast<String, dynamic>())),
          trailing: Text('× ${formatQty(i['quantity'] as String)}'),
        ),
      if ((r['notes'] as String?)?.isNotEmpty == true) Text('${s.notes}: ${r['notes']}'),
      if (quotes.length > 1) ...[
        const SizedBox(height: 12),
        ExpansionTile(tilePadding: EdgeInsets.zero, title: Text(lang == 'ar' ? 'النسخ السابقة' : 'Earlier versions'), children: [
          for (final o in quotes.skip(1))
            ListTile(dense: true, title: Text('${o['number']}'), subtitle: Text(s.quoteStatus(o['status'] as String)), trailing: Text(formatMoney(o['total'] as Map<String, dynamic>, lang)),
                onTap: o['pdf_url'] == null ? null : () => openExternal(o['pdf_url'] as String)),
        ]),
      ],
      const SizedBox(height: 96),
    ]);
  }

  String _specs(S s, Map<String, dynamic> sp) => [
        if (sp['bore_mm'] != null) '${s.bore}: ${sp['bore_mm']}',
        if (sp['rod_mm'] != null) '${s.rod}: ${sp['rod_mm']}',
        if (sp['stroke_mm'] != null) '${s.stroke}: ${sp['stroke_mm']}',
        if (sp['mounting'] != null) '${s.mounting}: ${sp['mounting']}',
        if (sp['pressure_bar'] != null) '${s.pressure}: ${sp['pressure_bar']}',
      ].join(' · ');
}

class _QuoteCard extends StatelessWidget {
  const _QuoteCard({required this.q, required this.lang});
  final Map<String, dynamic> q;
  final String lang;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final t = Theme.of(context);
    final left = q['status'] == 'sent' && q['valid_until'] != null ? daysLeft(q['valid_until'] as String) : null;
    final lines = (q['items'] as List).cast<Map<String, dynamic>>();
    Widget row(String k, String v, {bool bold = false}) => Padding(padding: const EdgeInsets.symmetric(vertical: 2), child: Row(children: [
          Expanded(child: Text(k, style: bold ? const TextStyle(fontWeight: FontWeight.w800) : null)),
          Text(v, style: bold ? const TextStyle(fontWeight: FontWeight.w800, fontSize: 18) : null),
        ]));

    return Card(
      shape: RoundedRectangleBorder(side: BorderSide(color: t.colorScheme.primary, width: 2), borderRadius: BorderRadius.circular(3)),
      child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Align(
            alignment: AlignmentDirectional.centerStart,
            child: Text('${q['number']}', style: t.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800), textDirection: TextDirection.ltr),
          )),
          Chip(label: Text(s.quoteStatus(q['status'] as String))),
        ]),
        if (q['valid_until'] != null) Text('${s.validUntil} ${ltr(q['valid_until'] as String)}${left != null ? ' · ${s.daysLeftText(left)}' : ''}'),
        const Divider(),
        for (final l in lines)
          Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(child: Text('${lang == 'ar' ? l['description_ar'] : l['description_en']}\n'
                '${formatQty(l['quantity'] as String)} × ${formatAmount(l['unit_price'] as String, lang)}'
                '${double.parse(l['discount_amount'] as String) > 0 ? ' − ${formatAmount(l['discount_amount'] as String, lang)}' : ''}', style: t.textTheme.bodyMedium)),
            Text(formatAmount(l['line_total'] as String, lang)),
          ])),
        const Divider(),
        row(s.subtotal, formatMoney(q['subtotal'] as Map<String, dynamic>, lang)),
        if (double.parse((q['discount_total'] as Map)['amount'] as String) > 0) row(s.discount, '− ${formatMoney(q['discount_total'] as Map<String, dynamic>, lang)}'),
        row(s.vat, formatMoney(q['vat_total'] as Map<String, dynamic>, lang)),
        row(s.total, formatMoney(q['total'] as Map<String, dynamic>, lang), bold: true),
        Text(s.pricesNote, style: t.textTheme.bodySmall),
        for (final k in ['delivery_terms', 'warranty_terms', 'payment_terms', 'estimated_duration'])
          if ((q[k] as String?)?.isNotEmpty == true) Padding(padding: const EdgeInsets.only(top: 6), child: Text(q[k] as String, style: t.textTheme.bodySmall)),
        if (q['pdf_url'] != null) ...[
          const SizedBox(height: 8),
          OutlinedButton.icon(onPressed: () => openExternal(q['pdf_url'] as String), icon: const Icon(Icons.picture_as_pdf_outlined), label: Text(s.pdf)),
        ],
        if (q['status'] == 'accepted') Padding(padding: const EdgeInsets.only(top: 8), child: Text(s.accepted, style: TextStyle(color: t.colorScheme.secondary))),
      ])),
    );
  }
}

/// الشريط السفلي: قبول/تعديل/رفض، أو إرسال للاعتماد، أو تجديد العرض المنتهي، أو إلغاء الطلب قبل العرض
class _ReplyBar extends ConsumerStatefulWidget {
  const _ReplyBar({required this.r});
  final Map<String, dynamic> r;
  @override
  ConsumerState<_ReplyBar> createState() => _ReplyBarState();
}

class _ReplyBarState extends ConsumerState<_ReplyBar> {
  bool busy = false;

  Map<String, dynamic>? get _quote {
    final qs = (widget.r['quotations'] as List).cast<Map<String, dynamic>>();
    return qs.isEmpty ? null : qs.first;
  }

  Future<void> _run(String path, Map<String, dynamic> body, {String? done}) async {
    setState(() => busy = true);
    try {
      await ref.read(apiProvider).post(path, body);
      ref.invalidate(rfqDetailProvider(widget.r['id'] as String));
      ref.invalidate(rfqListProvider);
      if (mounted && done != null) _toast(context, done);
    } on ApiException catch (e) {
      if (mounted) _toast(context, e.message ?? errorText(e, S.of(context)));
    }
    if (mounted) setState(() => busy = false);
  }

  /// نافذة بحقل نصي (السبب أو الملاحظة)، وتعيد النص أو null عند الإلغاء
  Future<String?> _ask(String label, {bool required = false}) {
    final s = S.of(context);
    final c = TextEditingController();
    return showDialog<String>(context: context, builder: (ctx) => AlertDialog(
      content: TextField(controller: c, maxLines: 3, decoration: InputDecoration(labelText: label)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx), child: Text(s.cancel)),
        FilledButton(onPressed: () { if (!required || c.text.trim().isNotEmpty) Navigator.pop(ctx, c.text.trim()); }, child: Text(s.send)),
      ],
    ));
  }

  Future<void> _accept(Map<String, dynamic> q) async {
    final s = S.of(context);
    final lpo = TextEditingController();
    String? fileId;
    final ok = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, builder: (ctx) => StatefulBuilder(builder: (ctx, set) => SafeArea(
      child: Padding(padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.viewInsetsOf(ctx).bottom + 16), child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('${s.accept} ${q['number']} · ${formatMoney(q['total'] as Map<String, dynamic>, _lang(context))}', style: Theme.of(ctx).textTheme.titleMedium),
        const SizedBox(height: 12),
        TextField(controller: lpo, decoration: InputDecoration(labelText: s.lpoNumber), textDirection: TextDirection.ltr),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          icon: Icon(fileId == null ? Icons.attach_file : Icons.check),
          label: Text(fileId == null ? s.lpoAttach : '✓'),
          onPressed: () async {
            final f = await pickAttachment(ctx, allowPdf: true);
            if (f == null) return;
            try {
              final up = await ref.read(apiProvider).upload(f.path, f.name);
              set(() => fileId = up['id'] as String);
            } on ApiException catch (e) {
              if (ctx.mounted) _toast(ctx, e.message ?? '');
            }
          },
        ),
        const SizedBox(height: 12),
        FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(s.accept)),
      ])),
    )));
    if (ok == true) {
      await _run('/me/quotations/${q['id']}/accept', {'lpo_number': lpo.text.trim().isEmpty ? null : lpo.text.trim(), 'lpo_attachment_id': fileId});
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final r = widget.r;
    final q = _quote;
    final children = <Widget>[];

    if (q != null && q['acceptable'] == true) {
      if (r['can_decide'] == true) {
        children.add(Expanded(child: FilledButton(onPressed: busy ? null : () => _accept(q), child: Text(s.accept))));
      } else {
        children.add(Expanded(child: FilledButton(
          onPressed: busy || q['approval_requested_at'] != null ? null : () => _run('/me/quotations/${q['id']}/request-approval', {}, done: s.approvalSent),
          child: Text(q['approval_requested_at'] != null ? s.approvalSent : s.sendForApproval))));
      }
      children.add(const SizedBox(width: 8));
      children.add(OutlinedButton(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 52)), onPressed: busy ? null : () async {
        final note = await _ask(s.changesNote, required: true);
        if (note != null) await _run('/me/quotations/${q['id']}/revision', {'note': note});
      }, child: Text(s.askChanges)));
      if (r['can_decide'] == true) {
        children.add(TextButton(onPressed: busy ? null : () async {
          final reason = await _ask(s.reason);
          if (reason != null) await _run('/me/quotations/${q['id']}/reject', {'reason': reason.isEmpty ? null : reason});
        }, child: Text(s.reject)));
      }
    } else if (r['can_renew'] == true) {
      children.add(Expanded(child: FilledButton(onPressed: busy ? null : () => _run('/me/rfqs/${r['id']}/renew', {}), child: Text(s.renew))));
    } else if (r['can_cancel'] == true) {
      children.add(Expanded(child: OutlinedButton(style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)), onPressed: busy ? null : () async {
        final reason = await _ask(s.reason);
        if (reason != null) await _run('/me/rfqs/${r['id']}/cancel', {'reason': reason.isEmpty ? null : reason});
      }, child: Text(s.cancelRequest))));
    }
    if (children.isEmpty) return const SizedBox.shrink();
    return SafeArea(child: Padding(padding: const EdgeInsets.all(12), child: Row(children: children)));
  }
}

/// بند في نموذج الطلب
class _Draft {
  _Draft({this.listingId, this.partId, this.label = '', this.freeText, this.qty = '1'});
  final String? listingId;
  final String? partId;
  final String label;
  String? freeText;
  String qty;
  final photos = <String>[];
  bool cylinder = false;
  final specs = <String, String>{};
}

/// M-24: نفس C-07 مع صورة القطعة أو لوحتها من الكاميرا
class NewRfqScreen extends ConsumerStatefulWidget {
  const NewRfqScreen({super.key, this.type = 'parts', this.listingId, this.partId, this.label, this.qty, this.equipmentId});
  final String? equipmentId;
  final String type;
  final String? listingId;
  final String? partId;
  final String? label;
  final String? qty;
  @override
  ConsumerState<NewRfqScreen> createState() => _NewRfqScreenState();
}

class _NewRfqScreenState extends ConsumerState<NewRfqScreen> {
  late String type = widget.type == 'equipment' ? 'equipment' : 'parts';
  late final items = <_Draft>[
    if (widget.listingId != null || widget.partId != null)
      _Draft(listingId: widget.listingId, partId: widget.partId, label: widget.label ?? '', qty: widget.qty ?? '1'),
  ];
  final notes = TextEditingController();
  final country = TextEditingController();
  bool busy = false;
  String? uploading;
  Map<String, List<String>> errors = {};
  String? account; // null = المحدد في التطبيق، أو حساب المعدة المرتبطة
  late String? equipmentId = widget.equipmentId;
  String? addressId;

  String get _account {
    if (account != null) return account!;
    final fleet = ref.read(fleetProvider).value ?? const <Map<String, dynamic>>[];
    final machine = fleet.where((e) => e['id'] == equipmentId).firstOrNull;
    return machine == null ? ref.read(selectedAccountProvider) : accountOf(machine);
  }

  /// الحساب والمعدة والعنوان، وكلها من الحساب نفسه (06 §2، C-07)
  List<Widget> _context(S s) {
    final me = ref.watch(authProvider).value;
    if (me == null) return const [];
    ref.watch(selectedAccountProvider);
    final fleet = ref.watch(fleetProvider).value ?? const <Map<String, dynamic>>[];
    final addresses = ref.watch(addressesProvider).value ?? const <Map<String, dynamic>>[];
    final current = _account;
    final machines = fleet.where((e) => accountOf(e) == current).toList();
    final places = addresses.where((a) => accountOf(a) == current && a['kind'] != 'site').toList();
    return [
      if (me.organizations.isNotEmpty)
        Choice(
          label: s.account,
          value: current,
          error: errors['organization_id']?.first,
          options: {for (final o in me.organizations) o['id'] as String: o['name'] as String, personalAccount: s.personal},
          onChanged: (v) => setState(() {
            account = v;
            equipmentId = null;
            addressId = null;
          }),
        ),
      if (type == 'parts' && machines.isNotEmpty)
        Choice(
          label: s.relatedEquipment,
          value: equipmentId ?? '',
          error: errors['customer_equipment_id']?.first,
          options: {'': s.none, for (final e in machines) e['id'] as String: e['name'] as String},
          onChanged: (v) => setState(() {
            account = current;
            equipmentId = v == '' ? null : v;
          }),
        ),
      if (places.isNotEmpty)
        Choice(
          label: s.deliveryAddress,
          value: addressId ?? '',
          error: errors['delivery_address_id']?.first,
          options: {'': s.none, for (final a in places) a['id'] as String: a['label'] as String},
          onChanged: (v) => setState(() => addressId = v == '' ? null : v),
        ),
    ];
  }

  @override
  void dispose() {
    notes.dispose();
    country.dispose();
    super.dispose();
  }

  /// صنف الكتالوج يحدد نوع الطلب، فلا يتغير النوع تحته
  bool get typeLocked => items.any((d) => d.partId != null || d.listingId != null);

  Future<void> _searchCatalog() async {
    final picked = await showModalBottomSheet<Map<String, dynamic>>(context: context, isScrollControlled: true, builder: (_) => const _PartPicker());
    if (picked != null) {
      setState(() => items.add(_Draft(partId: picked['id'] as String, label: '${(picked['brand'] as Map)['name']} ${picked['part_number']} · ${picked['name']}')));
    }
  }

  Future<void> _photo(_Draft d) async {
    final f = await pickAttachment(context);
    if (f == null) return;
    setState(() => uploading = f.name);
    try {
      final up = await ref.read(apiProvider).upload(f.path, f.name);
      setState(() => d.photos.add(up['id'] as String));
    } on ApiException catch (e) {
      if (mounted) _toast(context, e.message ?? errorText(e, S.of(context)));
    }
    if (mounted) setState(() => uploading = null);
  }

  Future<void> _submit() async {
    final s = S.of(context);
    if (items.isEmpty) return _toast(context, s.addItemFirst);
    setState(() { busy = true; errors = {}; });
    try {
      // الحساب يتبع المعدة، فلا يُرسل قبل معرفته
      if (equipmentId != null && account == null) await ref.read(fleetProvider.future);
      final r = await ref.read(apiProvider).post('/me/rfqs', {
        'organization_id': organizationOf(_account),
        'customer_equipment_id': type == 'parts' ? equipmentId : null,
        'delivery_address_id': addressId,
        'type': type,
        'source': 'app',
        'notes': notes.text.trim().isEmpty ? null : notes.text.trim(),
        'delivery_country': country.text.trim().isEmpty ? 'OM' : country.text.trim().toUpperCase(),
        'items': [
          for (final d in items)
            {
              'listing_id': ?d.listingId, 'part_id': ?d.partId, 'free_text': ?d.freeText,
              'quantity': d.qty, 'attachment_ids': d.photos,
              if (type == 'parts' && d.cylinder && d.specs.values.any((v) => v.isNotEmpty)) 'specs': {for (final e in d.specs.entries) if (e.value.isNotEmpty) e.key: e.value},
            },
        ],
      });
      ref.invalidate(rfqListProvider);
      if (!mounted) return;
      _toast(context, s.requestSent);
      context.pushReplacement('/requests/${r['id']}');
    } on ApiException catch (e) {
      // details رسائل حقول في أخطاء التحقق فقط. في غيرها قيم مفردة (retry_after، from، to)
      setState(() => errors = {for (final d in e.details.entries) if (d.value is List) d.key: (d.value as List).cast<String>()});
      if (mounted) _toast(context, errors.values.firstOrNull?.first ?? e.message ?? errorText(e, s));
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(s.newRequest)),
      body: ListView(padding: screenPadding(context), children: [
        Text(s.rfqIntro),
        const SizedBox(height: 12),
        SegmentedButton<String>(
          segments: [ButtonSegment(value: 'parts', label: Text(s.typeName('parts'))), ButtonSegment(value: 'equipment', label: Text(s.typeName('equipment')))],
          selected: {type},
          onSelectionChanged: typeLocked ? null : (v) => setState(() { type = v.first; errors = {}; }),
        ),
        if (typeLocked) Padding(padding: const EdgeInsets.only(top: 4), child: Text(s.typeLocked, style: Theme.of(context).textTheme.bodySmall)),
        const SizedBox(height: 12),
        ..._context(s),
        for (final (i, d) in items.indexed) _itemCard(s, i, d),
        Wrap(spacing: 8, children: [
          if (type == 'parts') OutlinedButton.icon(onPressed: _searchCatalog, icon: const Icon(Icons.search), label: Text(s.addFromCatalog)),
          OutlinedButton.icon(
            onPressed: () => setState(() => items.add(_Draft(freeText: ''))),
            icon: const Icon(Icons.add),
            label: Text(type == 'parts' ? s.addUnlisted : s.addUnlistedMachine),
          ),
        ]),
        const SizedBox(height: 16),
        TextField(controller: notes, maxLines: 3, decoration: InputDecoration(labelText: s.notes)),
        const SizedBox(height: 12),
        TextField(controller: country, maxLength: 2, textCapitalization: TextCapitalization.characters, textDirection: TextDirection.ltr,
            decoration: InputDecoration(labelText: s.exportCountry, errorText: errors['delivery_country']?.first)),
        const SizedBox(height: 12),
        FilledButton(onPressed: busy || uploading != null ? null : _submit, child: Text(uploading != null ? s.uploading : s.sendRequest)),
      ]),
    );
  }

  Widget _itemCard(S s, int i, _Draft d) {
    final err = errors['items.$i.free_text']?.first ?? errors['items.$i.listing_id']?.first ?? errors['items.$i.part_id']?.first;
    // المفتاح من البند نفسه: بحذف بند لا تنتقل حقوله إلى الذي يليه
    return Card(key: ObjectKey(d), child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        Expanded(child: d.freeText == null
            ? Text(d.label, style: const TextStyle(fontWeight: FontWeight.w700))
            : TextFormField(initialValue: d.freeText, onChanged: (v) => d.freeText = v, decoration: InputDecoration(labelText: type == 'parts' ? s.unlistedHint : s.unlistedMachineHint, errorText: err))),
        const SizedBox(width: 8),
        SizedBox(width: 72, child: TextFormField(initialValue: d.qty, keyboardType: const TextInputType.numberWithOptions(decimal: true), textDirection: TextDirection.ltr,
            onChanged: (v) => d.qty = v, decoration: InputDecoration(labelText: s.quantity))),
        IconButton(onPressed: () => setState(() { items.removeAt(i); errors = {}; }), icon: const Icon(Icons.delete_outline), tooltip: s.remove),
      ]),
      if (d.freeText == null && err != null) Text(err, style: TextStyle(color: Theme.of(context).colorScheme.error)),
      if (d.freeText != null) ...[
        const SizedBox(height: 8),
        Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          OutlinedButton.icon(onPressed: () => _photo(d), icon: const Icon(Icons.photo_camera_outlined), label: Text(s.takePhoto)),
          for (final _ in d.photos) const Chip(label: Icon(Icons.image_outlined, size: 18)),
        ]),
        if (type == 'parts') CheckboxListTile(contentPadding: EdgeInsets.zero, value: d.cylinder, onChanged: (v) => setState(() => d.cylinder = v ?? false), title: Text(s.cylinderSpecs)),
        if (type == 'parts' && d.cylinder)
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final (k, label) in [('bore_mm', s.bore), ('rod_mm', s.rod), ('stroke_mm', s.stroke), ('mounting', s.mounting), ('pressure_bar', s.pressure)])
              SizedBox(width: 150, child: TextFormField(
                initialValue: d.specs[k], onChanged: (v) => d.specs[k] = v.trim(),
                keyboardType: k == 'mounting' ? TextInputType.text : const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(labelText: label, errorText: errors['items.$i.specs.$k']?.first),
              )),
          ]),
      ],
    ])));
  }
}

/// بحث قطعة من الكتالوج داخل نموذج الطلب
class _PartPicker extends ConsumerStatefulWidget {
  const _PartPicker();
  @override
  ConsumerState<_PartPicker> createState() => _PartPickerState();
}

class _PartPickerState extends ConsumerState<_PartPicker> {
  final c = TextEditingController();
  List<Map<String, dynamic>> rows = [];
  bool loading = false;

  @override
  void dispose() {
    c.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    if (c.text.trim().length < 2) return;
    setState(() => loading = true);
    try {
      rows = (await ref.read(apiProvider).page('/parts/search', query: {'q': c.text.trim()})).rows;
    } on ApiException {
      rows = [];
    }
    if (mounted) setState(() => loading = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return SafeArea(child: Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.viewInsetsOf(context).bottom + 16),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: c, autofocus: true, keyboardType: TextInputType.visiblePassword, autocorrect: false, textDirection: TextDirection.ltr,
            decoration: InputDecoration(hintText: s.partSearchHint, suffixIcon: IconButton(icon: const Icon(Icons.search), onPressed: _search)), onSubmitted: (_) => _search()),
        if (loading) const Padding(padding: EdgeInsets.all(16), child: CircularProgressIndicator()),
        ConstrainedBox(constraints: const BoxConstraints(maxHeight: 360), child: ListView(shrinkWrap: true, children: [
          for (final p in rows)
            ListTile(title: Text('${p['part_number']} · ${p['name']}'), subtitle: Text((p['brand'] as Map)['name'] as String), onTap: () => Navigator.pop(context, p)),
        ])),
      ]),
    ));
  }
}
