import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../account/account_common.dart';
import '../auth/auth_controller.dart';
import 'catalog_screens.dart';

// M-09 "حسب المعدة": من أسطولي، أو ماركة ← موديل (FR-PRT-02، US-04)

final compatiblePartsProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, String>(
    (ref, modelId) => ref.read(apiProvider).get('/parts/compatible', query: {'model_id': modelId}));

class ByMachineScreen extends ConsumerStatefulWidget {
  const ByMachineScreen({super.key, this.modelId, this.equipmentId});
  final String? modelId;
  final String? equipmentId;

  @override
  ConsumerState<ByMachineScreen> createState() => _ByMachineScreenState();
}

class _ByMachineScreenState extends ConsumerState<ByMachineScreen> {
  late String? modelId = widget.modelId;
  late String? equipmentId = widget.equipmentId;
  String? brand;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final t = Theme.of(context).textTheme;
    final lang = Localizations.localeOf(context).languageCode;
    final models = ref.watch(modelsProvider);
    final signedIn = ref.watch(authProvider).value != null; // الزائر يختار من الكتالوج فقط
    final fleet = signedIn ? ref.watch(fleetProvider).value ?? const <Map<String, dynamic>>[] : const <Map<String, dynamic>>[];
    final machine = fleet.where((e) => e['id'] == equipmentId).firstOrNull;
    final all = models.value ?? const <Map<String, dynamic>>[];
    final shownBrand = brand ?? all.where((m) => m['id'] == modelId).firstOrNull?['brand'] as String?;

    void request([Map<String, dynamic>? part]) => context.push(Uri(path: '/requests/new', queryParameters: {
          'type': 'parts',
          if (equipmentId != null) 'equipment': equipmentId,
          if (part != null) 'part': part['id'],
          if (part != null) 'label': '${(part['brand'] as Map)['name']} ${part['part_number']} · ${part['name']}',
        }).toString());

    return Scaffold(
      appBar: AppBar(title: Text(s.byMachine)),
      body: ListView(padding: screenPadding(context), children: [
        if (fleet.isNotEmpty) ...[
          Text(s.fromFleet, style: t.titleSmall),
          Wrap(spacing: 8, children: [
            for (final e in fleet)
              ChoiceChip(
                label: Text(e['name'] as String),
                selected: e['id'] == equipmentId,
                onSelected: (_) => setState(() {
                  equipmentId = e['id'] as String;
                  modelId = e['model_id'] as String?;
                  brand = null;
                }),
              ),
          ]),
          const SizedBox(height: 16),
        ],
        if (models.hasError) LoadError(onRetry: () => ref.invalidate(modelsProvider)),
        Choice(
          label: s.brand,
          value: shownBrand,
          options: {for (final m in all) m['brand'] as String: m['brand'] as String},
          onChanged: (v) => setState(() {
            brand = v;
            modelId = null;
            equipmentId = null;
          }),
        ),
        Choice(
          label: s.model,
          value: modelId,
          options: {for (final m in all.where((m) => m['brand'] == shownBrand)) m['id'] as String: m['model_name'] as String},
          onChanged: (v) => setState(() => modelId = v),
        ),
        if (machine != null && machine['model_id'] == null && modelId == null) ...[
          Text(s.notLinked),
          const SizedBox(height: 8),
          FilledButton(onPressed: () => request(), child: Text(s.requestPartForMachine)),
        ],
        if (modelId != null)
          ...ref.watch(compatiblePartsProvider(modelId!)).when(
                loading: () => [const Center(child: CircularProgressIndicator())],
                error: (_, _) => [LoadError(onRetry: () => ref.invalidate(compatiblePartsProvider(modelId!)))],
                data: (d) {
                  final groups = (d['groups'] as List? ?? const []).cast<Map<String, dynamic>>();
                  return [
                    if (groups.isEmpty) ...[
                      Text(s.noPartsForModel),
                      const SizedBox(height: 8),
                      FilledButton(onPressed: () => request(), child: Text(s.requestPartForMachine)),
                    ],
                    for (final g in groups) ...[
                      Padding(padding: const EdgeInsets.only(top: 12, bottom: 4), child: Text('${g['category'] ?? s.otherCategory}', style: t.titleSmall)),
                      for (final p in (g['parts'] as List).cast<Map<String, dynamic>>())
                        Card(child: ListTile(
                          title: Text('${ltr(p['part_number'] as String)} · ${p['name']}'),
                          subtitle: Text('${s.availability(p['availability'] as String? ?? '')} · ${formatMoney(p['price'] as Map<String, dynamic>?, lang)}'),
                          trailing: equipmentId == null
                              ? null
                              : IconButton(tooltip: s.requestPartForMachine, icon: const Icon(Icons.request_quote_outlined), onPressed: () => request(p)),
                          onTap: () => context.push('/parts/${(p['brand'] as Map)['slug']}/${Uri.encodeComponent(p['part_number'] as String)}'),
                        )),
                    ],
                  ];
                },
              ),
      ]),
    );
  }
}
