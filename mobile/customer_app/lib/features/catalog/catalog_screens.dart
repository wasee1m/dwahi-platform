import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';

// M-07 المعدات، M-08 تفاصيل معدة، M-09 القطع، M-10 تفاصيل قطعة (FR-CAT-01..03، FR-PRT-01..03)
// للزائر أيضاً: التصفح لا يحتاج دخولاً، والطلب يطلبه (07 M-02).

final equipmentDetailProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, String>(
    (ref, slug) => ref.read(apiProvider).get('/equipment/${Uri.encodeComponent(slug)}'));

final facetsProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, String>(
    (ref, kind) => ref.read(apiProvider).get('/equipment/facets', query: {if (kind.isNotEmpty) 'kind': kind}));

final partDetailProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, ({String brand, String number})>(
    (ref, k) => ref.read(apiProvider).get('/parts/${Uri.encodeComponent(k.brand)}/${Uri.encodeComponent(k.number)}'));

String _lang(BuildContext c) => Localizations.localeOf(c).languageCode;

/// خطأ تحميل مع "إعادة المحاولة"
class LoadError extends StatelessWidget {
  const LoadError({super.key, required this.onRetry});
  final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
      Text(s.genericError),
      const SizedBox(height: 8),
      OutlinedButton(onPressed: onRetry, child: Text(s.retry)),
    ]));
  }
}

/// بطاقة معدة (M-06، M-07)
class EquipmentTile extends StatelessWidget {
  const EquipmentTile(this.e, {super.key});
  final Map<String, dynamic> e;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final t = Theme.of(context);
    final price = e['price'] as Map<String, dynamic>?;
    final status = e['status'] as String?;
    return Card(
      clipBehavior: Clip.antiAlias,
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        onTap: () => context.push('/equipment/${e['slug']}'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          AspectRatio(aspectRatio: 16 / 10, child: Stack(fit: StackFit.expand, children: [
            NetImage(e['cover_image_url'] as String?),
            if (status == 'reserved' || status == 'sold')
              PositionedDirectional(top: 8, start: 8, child: Chip(label: Text(status == 'sold' ? s.sold : s.reserved))),
          ])),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(e['title'] as String? ?? '', style: t.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
              const SizedBox(height: 4),
              Text([
                s.conditionName(e['condition'] as String? ?? ''),
                if (e['year'] != null) '${e['year']}',
                if (e['hour_meter'] != null) '${e['hour_meter']} h',
              ].join(' · '), style: t.textTheme.bodySmall),
              const SizedBox(height: 6),
              Text(price == null ? s.priceOnRequest : '${formatMoney(price, _lang(context))} ${s.exVat}',
                  style: t.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
            ]),
          ),
        ]),
      ),
    );
  }
}

/// M-07: تبويبات النوع، وشرائح الفئات، وفلترة في ورقة سفلية، وتمرير لا نهائي
class EquipmentScreen extends ConsumerStatefulWidget {
  const EquipmentScreen({super.key});
  @override
  ConsumerState<EquipmentScreen> createState() => _EquipmentScreenState();
}

class _EquipmentScreenState extends ConsumerState<EquipmentScreen> {
  static const kinds = ['machine', 'component', 'scrap'];
  String kind = 'machine';
  String? category;
  String? condition;
  String? brand;
  String sort = 'newest';
  final rows = <Map<String, dynamic>>[];
  String? cursor;
  bool loading = false;
  bool done = false;
  bool failed = false;
  final scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    scroll.addListener(() {
      if (scroll.position.pixels > scroll.position.maxScrollExtent - 600) _more();
    });
    _reload();
  }

  @override
  void dispose() {
    scroll.dispose();
    super.dispose();
  }

  void _reload() {
    rows.clear();
    cursor = null;
    done = false;
    _more();
  }

  Future<void> _more() async {
    if (loading || done) return;
    setState(() { loading = true; failed = false; });
    try {
      final page = await ref.read(apiProvider).page('/equipment', query: {
        'kind': kind, 'sort': sort, 'cursor': ?cursor, 'category': ?category, 'condition': ?condition, 'brand': ?brand,
      });
      rows.addAll(page.rows);
      cursor = page.nextCursor;
      done = cursor == null;
    } on ApiException {
      failed = true;
    }
    if (mounted) setState(() => loading = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final facets = ref.watch(facetsProvider(kind)).value;
    final cats = (facets?['categories'] as List? ?? const []).cast<Map<String, dynamic>>();
    final names = {'machine': s.kindMachine, 'component': s.kindComponent, 'scrap': s.kindScrap};

    return DefaultTabController(
      length: kinds.length,
      child: Scaffold(
        appBar: AppBar(
          title: Text(s.tabEquipment),
          actions: [IconButton(tooltip: s.filter, icon: const Icon(Icons.tune), onPressed: () => _filters(facets))],
          bottom: TabBar(
            onTap: (i) { setState(() { kind = kinds[i]; category = null; }); _reload(); },
            tabs: [for (final k in kinds) Tab(text: names[k])],
          ),
        ),
        body: RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(controller: scroll, padding: const EdgeInsets.all(12), children: [
            if (cats.isNotEmpty)
              SizedBox(
                height: 44,
                child: ListView(scrollDirection: Axis.horizontal, children: [
                  Padding(padding: const EdgeInsetsDirectional.only(end: 8), child: ChoiceChip(label: Text(s.all), selected: category == null,
                      onSelected: (_) { setState(() => category = null); _reload(); })),
                  for (final c in cats)
                    Padding(padding: const EdgeInsetsDirectional.only(end: 8), child: ChoiceChip(label: Text('${c['name']} (${c['count']})'), selected: category == c['slug'],
                        onSelected: (_) { setState(() => category = c['slug'] as String); _reload(); })),
                ]),
              ),
            const SizedBox(height: 8),
            for (final e in rows) EquipmentTile(e),
            if (loading) const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
            if (failed) LoadError(onRetry: _more),
            if (!loading && !failed && rows.isEmpty) Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(s.empty))),
          ]),
        ),
      ),
    );
  }

  /// الفلاتر في ورقة سفلية (07 M-07)
  Future<void> _filters(Map<String, dynamic>? facets) async {
    final s = S.of(context);
    final conds = (facets?['conditions'] as List? ?? const []).cast<Map<String, dynamic>>();
    final brands = (facets?['brands'] as List? ?? const []).cast<Map<String, dynamic>>();
    var c = condition, b = brand, so = sort;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(builder: (ctx, set) => SafeArea(child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(s.condition, style: Theme.of(ctx).textTheme.titleSmall),
          Wrap(spacing: 8, children: [
            for (final x in conds.where((x) => (x['count'] as int) > 0))
              ChoiceChip(label: Text('${s.conditionName(x['value'] as String)} (${x['count']})'), selected: c == x['value'], onSelected: (v) => set(() => c = v ? x['value'] as String : null)),
          ]),
          const SizedBox(height: 12),
          Text(s.brand, style: Theme.of(ctx).textTheme.titleSmall),
          Wrap(spacing: 8, children: [
            for (final x in brands) ChoiceChip(label: Text('${x['name']} (${x['count']})'), selected: b == x['slug'], onSelected: (v) => set(() => b = v ? x['slug'] as String : null)),
          ]),
          const SizedBox(height: 12),
          Text(s.sort, style: Theme.of(ctx).textTheme.titleSmall),
          SegmentedButton<String>(
            segments: [ButtonSegment(value: 'newest', label: Text(s.newest)), ButtonSegment(value: 'price_asc', label: Text(s.priceAsc)), ButtonSegment(value: 'price_desc', label: Text(s.priceDesc))],
            selected: {so},
            onSelectionChanged: (v) => set(() => so = v.first),
          ),
          const SizedBox(height: 16),
          Row(children: [
            Expanded(child: OutlinedButton(onPressed: () => set(() { c = null; b = null; so = 'newest'; }), child: Text(s.clear))),
            const SizedBox(width: 12),
            Expanded(child: FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(s.apply))),
          ]),
        ]),
      ))),
    );
    if (ok == true) {
      setState(() { condition = c; brand = b; sort = so; });
      _reload();
    }
  }
}

/// M-08: معرض بالسحب، والمواصفات قابلة للطي، وشريط سفلي ثابت "اطلب عرض سعر" + "واتساب"
class EquipmentDetailScreen extends ConsumerWidget {
  const EquipmentDetailScreen({super.key, required this.slug});
  final String slug;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final async = ref.watch(equipmentDetailProvider(slug));
    return Scaffold(
      appBar: AppBar(),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(equipmentDetailProvider(slug))),
        data: (e) {
          final t = Theme.of(context);
          final media = (e['media'] as List? ?? const []).cast<Map<String, dynamic>>();
          final specs = (e['specs'] as List? ?? const []).cast<Map<String, dynamic>>();
          final price = e['price'] as Map<String, dynamic>?;
          return ListView(children: [
            AspectRatio(aspectRatio: 4 / 3, child: media.isEmpty ? const NetImage(null)
                : PageView(children: [for (final m in media) NetImage(m['url'] as String?)])),
            Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(e['title'] as String? ?? '', style: t.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              Text(price == null ? s.priceOnRequest : '${formatMoney(price, _lang(context))} ${s.exVat}', style: t.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              Wrap(spacing: 8, runSpacing: 4, children: [
                Chip(label: Text(s.conditionName(e['condition'] as String? ?? ''))),
                if (e['year'] != null) Chip(label: Text('${s.year}: ${e['year']}')),
                if (e['hour_meter'] != null) Chip(label: Text('${s.hours}: ${e['hour_meter']}')),
                if (e['warranty_months'] != null) Chip(label: Text('${s.warranty}: ${s.months(e['warranty_months'] as int)}')),
              ]),
              if ((e['description'] as String?)?.isNotEmpty == true) ...[
                const SizedBox(height: 12),
                Text(e['description'] as String),
              ],
              if ((e['missing_parts'] as String?)?.isNotEmpty == true) ...[
                const SizedBox(height: 12),
                Text('${s.missingParts}: ${e['missing_parts']}'),
              ],
            ])),
            if (specs.isNotEmpty)
              ExpansionTile(title: Text(s.specs), initiallyExpanded: true, children: [
                for (final sp in specs)
                  ListTile(dense: true, title: Text(sp['label'] as String), trailing: Text('${sp['value']} ${sp['unit'] ?? ''}')),
              ]),
            const SizedBox(height: 96),
          ]);
        },
      ),
      bottomNavigationBar: async.value == null ? null : _DetailBar(e: async.value!),
    );
  }
}

class _DetailBar extends StatelessWidget {
  const _DetailBar({required this.e});
  final Map<String, dynamic> e;
  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final wa = (e['branch'] as Map<String, dynamic>?)?['whatsapp_sales'] as String?;
    final available = e['status'] == 'published';
    return SafeArea(child: Padding(padding: const EdgeInsets.all(12), child: Row(children: [
      if (available)
        Expanded(child: FilledButton(
          onPressed: () => context.push(Uri(path: '/requests/new', queryParameters: {'type': 'equipment', 'listing': e['id'], 'label': e['title']}).toString()),
          child: Text(s.requestQuote),
        )),
      if (available && wa != null) const SizedBox(width: 12),
      if (wa != null)
        Expanded(child: OutlinedButton.icon(
          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          icon: const Icon(Icons.chat_outlined),
          label: Text(s.whatsapp),
          onPressed: () => openWhatsApp(wa, s.whatsappAbout(e['title'] as String? ?? '')),
        )),
    ])));
  }
}

/// M-09: بحث بالرقم بلوحة أبجدية رقمية (الأرقام والحروف والشرطة دون تصحيح تلقائي)
class PartsScreen extends ConsumerStatefulWidget {
  const PartsScreen({super.key, this.initialQuery});
  final String? initialQuery;
  @override
  ConsumerState<PartsScreen> createState() => _PartsScreenState();
}

class _PartsScreenState extends ConsumerState<PartsScreen> {
  late final controller = TextEditingController(text: widget.initialQuery);
  List<Map<String, dynamic>>? results;
  bool loading = false;
  bool failed = false;

  @override
  void initState() {
    super.initState();
    if ((widget.initialQuery ?? '').isNotEmpty) _search();
  }

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    final q = controller.text.trim();
    if (q.length < 2) return;
    setState(() { loading = true; failed = false; });
    try {
      results = (await ref.read(apiProvider).page('/parts/search', query: {'q': q})).rows;
    } on ApiException {
      failed = true;
    }
    if (mounted) setState(() => loading = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final lang = _lang(context);
    return Scaffold(
      appBar: AppBar(title: Text(s.tabParts)),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        TextField(
          controller: controller,
          keyboardType: TextInputType.visiblePassword, // أبجدية رقمية بلا اقتراحات
          autocorrect: false,
          enableSuggestions: false,
          textInputAction: TextInputAction.search,
          textDirection: TextDirection.ltr,
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[A-Za-z0-9\- ./؀-ۿ]'))],
          decoration: InputDecoration(hintText: s.partSearchHint, prefixIcon: const Icon(Icons.search),
              suffixIcon: IconButton(icon: const Icon(Icons.arrow_forward), onPressed: _search, tooltip: s.findPart)),
          onSubmitted: (_) => _search(),
        ),
        const SizedBox(height: 6),
        Text(s.partSearchIntro, style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: 12),
        if (loading) const Center(child: CircularProgressIndicator()),
        if (failed) LoadError(onRetry: _search),
        if (results != null && results!.isEmpty && !loading) Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(s.empty))),
        for (final p in results ?? const <Map<String, dynamic>>[])
          Card(child: ListTile(
            title: Text('${p['part_number']} · ${p['name']}', textDirection: TextDirection.ltr, textAlign: TextAlign.start),
            subtitle: Text([
              (p['brand'] as Map)['name'] as String,
              s.availability(p['availability'] as String? ?? ''),
              if (p['matched_alternative'] != null) s.matchedAlternative(p['matched_alternative'] as String),
            ].join(' · ')),
            trailing: Text(formatMoney(p['price'] as Map<String, dynamic>?, lang), style: const TextStyle(fontWeight: FontWeight.w700)),
            onTap: () => context.push('/parts/${(p['brand'] as Map)['slug']}/${Uri.encodeComponent(p['part_number'] as String)}'),
          )),
      ]),
    );
  }
}

/// M-10: الأرقام، والتوافق، والسعر، والتوفر، والكمية، و"اطلب كمية" (السلة مع S6)
class PartDetailScreen extends ConsumerStatefulWidget {
  const PartDetailScreen({super.key, required this.brand, required this.number});
  final String brand;
  final String number;
  @override
  ConsumerState<PartDetailScreen> createState() => _PartDetailScreenState();
}

class _PartDetailScreenState extends ConsumerState<PartDetailScreen> {
  int qty = 1;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final key = (brand: widget.brand, number: widget.number);
    final async = ref.watch(partDetailProvider(key));
    return Scaffold(
      appBar: AppBar(),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(partDetailProvider(key))),
        data: (p) {
          final t = Theme.of(context);
          final alts = (p['alternatives'] as List? ?? const []).cast<Map<String, dynamic>>();
          final models = (p['compatible_models'] as List? ?? const []).cast<Map<String, dynamic>>();
          final media = (p['media'] as List? ?? const []).cast<Map<String, dynamic>>();
          return ListView(padding: const EdgeInsets.all(16), children: [
            if (media.isNotEmpty) ClipRRect(borderRadius: BorderRadius.circular(3), child: AspectRatio(aspectRatio: 4 / 3, child: NetImage(media.first['url'] as String?))),
            const SizedBox(height: 12),
            Text(p['part_number'] as String, textDirection: TextDirection.ltr, style: t.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
            Text('${(p['brand'] as Map)['name']} · ${p['name']}'),
            const SizedBox(height: 8),
            Text('${formatMoney(p['price'] as Map<String, dynamic>?, _lang(context))} ${s.exVat}', style: t.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
            Text('${s.availability(p['availability'] as String? ?? '')} · ${p['is_returnable'] == true ? s.returnable : s.notReturnable}'
                '${p['warranty_months'] != null ? ' · ${s.warranty}: ${s.months(p['warranty_months'] as int)}' : ''}'),
            if ((p['description'] as String?)?.isNotEmpty == true) ...[const SizedBox(height: 12), Text(p['description'] as String)],
            if (alts.isNotEmpty) ...[
              const SizedBox(height: 16),
              Text(s.alternatives, style: t.textTheme.titleSmall),
              Wrap(spacing: 8, children: [for (final a in alts) Chip(label: Text('${a['number']}${a['brand'] != null ? ' (${a['brand']})' : ''}', textDirection: TextDirection.ltr))]),
            ],
            if (models.isNotEmpty) ...[
              const SizedBox(height: 16),
              Text(s.compatibleWith, style: t.textTheme.titleSmall),
              Wrap(spacing: 8, children: [for (final m in models) Chip(label: Text('${m['brand']} ${m['model_name']}', textDirection: TextDirection.ltr))]),
            ],
            const SizedBox(height: 20),
            Row(children: [
              Text(s.quantity),
              const Spacer(),
              IconButton.outlined(onPressed: qty > 1 ? () => setState(() => qty--) : null, icon: const Icon(Icons.remove)),
              Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: Text('$qty', style: t.textTheme.titleLarge)),
              IconButton.outlined(onPressed: () => setState(() => qty++), icon: const Icon(Icons.add)),
            ]),
            const SizedBox(height: 16),
            FilledButton(
              onPressed: () => context.push(Uri(path: '/requests/new', queryParameters: {
                'type': 'parts', 'part': p['id'], 'qty': '$qty', 'label': '${(p['brand'] as Map)['name']} ${p['part_number']} · ${p['name']}',
              }).toString()),
              child: Text(s.requestQuantity),
            ),
            const SizedBox(height: 8),
            Text(s.cartSoon, style: t.textTheme.bodySmall, textAlign: TextAlign.center),
          ]);
        },
      ),
    );
  }
}
