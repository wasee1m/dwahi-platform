import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../catalog/catalog_screens.dart';
import '../quotes/pickers.dart';
import 'account_common.dart';

// M-13 أسطولي، M-14 تفاصيل معدة (FR-ACC-05، FR-PRT-02، US-04، US-10). "اطلب صيانة" مع S7.

final equipmentProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, String>(
    (ref, id) => ref.read(apiProvider).get('/me/fleet/$id'));
final equipmentHistoryProvider = FutureProvider.autoDispose.family<List<Map<String, dynamic>>, String>(
    (ref, id) async => (await ref.read(apiProvider).page('/me/fleet/$id/history')).rows);

/// M-13: بطاقات المعدات مع العداد، والبحث، وإضافة معدة
class FleetScreen extends ConsumerStatefulWidget {
  const FleetScreen({super.key});

  @override
  ConsumerState<FleetScreen> createState() => _FleetScreenState();
}

class _FleetScreenState extends ConsumerState<FleetScreen> {
  String query = '';

  bool _matches(Map<String, dynamic> e) =>
      query.isEmpty || '${e['name']} ${e['serial_number'] ?? ''}'.toLowerCase().contains(query.toLowerCase());

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final lang = Localizations.localeOf(context).languageCode;
    final account = ref.watch(selectedAccountProvider);
    final async = ref.watch(fleetProvider);
    return Scaffold(
      appBar: AppBar(title: Text(s.myFleet)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(fleetProvider)),
        data: (all) {
          final mine = all.where((e) => accountOf(e) == account).toList();
          return RefreshIndicator(
            onRefresh: () => ref.refresh(fleetProvider.future),
            child: ListView(padding: screenPadding(context), children: [
              const AccountPicker(),
              if (mine.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: TextField(
                    decoration: InputDecoration(hintText: s.searchFleet, prefixIcon: const Icon(Icons.search)),
                    onChanged: (v) => setState(() => query = v.trim()),
                  ),
                ),
              if (mine.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 24), child: Text(s.noFleet)),
              for (final e in mine.where(_matches))
                Card(child: ListTile(
                  leading: const Icon(Icons.agriculture_outlined),
                  title: Text('${e['nickname'] ?? e['machine']}'),
                  subtitle: Text([
                    if (e['nickname'] != null) '${e['machine']}',
                    if (e['serial_number'] != null) ltr('${e['serial_number']}'),
                    e['hour_meter'] == null
                        ? s.noReading
                        : '${s.hoursShort(e['hour_meter'] as Object)}${e['hour_meter_at'] == null ? '' : ' · ${formatDateTime(e['hour_meter_at'] as String, lang)}'}',
                  ].join('\n')),
                  onTap: () => context.push('/account/fleet/${e['id']}'),
                )),
              const SizedBox(height: 12),
              FilledButton.icon(
                icon: const Icon(Icons.add),
                label: Text(s.addEquipment),
                onPressed: () async {
                  final ok = await Navigator.of(context).push<bool>(
                      MaterialPageRoute(builder: (_) => EquipmentFormScreen(organizationId: organizationOf(account))));
                  if (ok == true) ref.invalidate(fleetProvider);
                },
              ),
            ]),
          );
        },
      ),
    );
  }
}

/// إضافة وتعديل: من الكتالوج أو "موديل آخر"، وصورة اللوحة التعريفية
class EquipmentFormScreen extends ConsumerStatefulWidget {
  const EquipmentFormScreen({super.key, this.item, this.organizationId});
  final Map<String, dynamic>? item;
  final String? organizationId;

  @override
  ConsumerState<EquipmentFormScreen> createState() => _EquipmentFormScreenState();
}

class _EquipmentFormScreenState extends ConsumerState<EquipmentFormScreen> {
  static const _other = '';
  late final e = widget.item;
  late final bool _linked = e?['model_id'] != null;
  late String? brand = e == null ? null : (_linked ? e!['brand'] as String? : _other);
  late String? modelId = e?['model_id'] as String?;
  late final brandText = TextEditingController(text: _linked ? null : e?['brand'] as String?);
  late final modelText = TextEditingController(text: _linked ? null : e?['model'] as String?);
  late final nickname = TextEditingController(text: e?['nickname'] as String?);
  late final serial = TextEditingController(text: e?['serial_number'] as String?);
  late final year = TextEditingController(text: e?['year']?.toString());
  final meter = TextEditingController();
  late String? site = (e?['site'] as Map?)?['id'] as String?;
  String? photo;
  Map<String, String> errors = {};
  bool busy = false;

  @override
  void dispose() {
    for (final c in [brandText, modelText, nickname, serial, year, meter]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _photo() async {
    final f = await pickAttachment(context);
    if (f == null || !mounted) return;
    setState(() => busy = true);
    await runAction(context, () async => photo = (await ref.read(apiProvider).upload(f.path, f.name))['id'] as String?);
    if (mounted) setState(() => busy = false);
  }

  Future<void> _save() async {
    setState(() {
      busy = true;
      errors = {};
    });
    String? text(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
    final typed = brand == _other;
    final body = {
      'model_id': typed ? null : modelId,
      'brand_text': typed ? text(brandText) : null,
      'model_text': typed ? text(modelText) : null,
      'serial_number': text(serial),
      'year': int.tryParse(year.text.trim()),
      'nickname': text(nickname),
      'site_address_id': site,
      'nameplate_photo_id': ?photo,
    };
    final api = ref.read(apiProvider);
    try {
      if (e == null) {
        await api.post('/me/fleet', {...body, 'organization_id': widget.organizationId, 'hour_meter': int.tryParse(meter.text.trim())});
      } else {
        await api.patch('/me/fleet/${e!['id']}', body);
      }
      if (mounted) Navigator.pop(context, true);
      return;
    } on ApiException catch (err) {
      if (mounted) {
        setState(() => errors = fieldErrors(err));
        toast(context, failureText(err, S.of(context)));
      }
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final models = ref.watch(modelsProvider).value ?? const <Map<String, dynamic>>[];
    final account = widget.organizationId ?? personalAccount;
    final sites = (ref.watch(addressesProvider).value ?? const <Map<String, dynamic>>[])
        .where((a) => accountOf(a) == account && a['kind'] != 'delivery');
    final typed = brand == _other;

    return Scaffold(
      appBar: AppBar(title: Text(e == null ? s.addEquipment : s.edit)),
      body: ListView(padding: screenPadding(context), children: [
        Choice(
          label: s.brand,
          value: brand,
          options: {for (final m in models) m['brand'] as String: m['brand'] as String, _other: s.otherModel},
          onChanged: (v) => setState(() {
            brand = v;
            modelId = null;
          }),
        ),
        if (typed) ...[
          Input(brandText, s.brandName, error: errors['brand_text']),
          Input(modelText, s.modelName, error: errors['model_text']),
        ] else
          Choice(
            label: s.model,
            value: modelId,
            error: errors['model_id'] ?? errors['model_text'],
            options: {for (final m in models.where((m) => m['brand'] == brand)) m['id'] as String: m['model_name'] as String},
            onChanged: (v) => setState(() => modelId = v),
          ),
        Input(nickname, s.nickname, error: errors['nickname']),
        Input(serial, s.serial, error: errors['serial_number'], latin: true),
        Input(year, s.year, error: errors['year'], latin: true, keyboard: TextInputType.number),
        if (e == null) Input(meter, s.hourMeter, error: errors['hour_meter'], latin: true, keyboard: TextInputType.number),
        if (sites.isNotEmpty)
          Choice(
            label: s.siteAddress,
            value: site ?? '',
            error: errors['site_address_id'],
            options: {'': s.none, for (final a in sites) a['id'] as String: a['label'] as String},
            onChanged: (v) => setState(() => site = v == '' ? null : v),
          ),
        OutlinedButton.icon(
          onPressed: busy ? null : _photo,
          icon: Icon(photo == null ? Icons.photo_camera_outlined : Icons.check),
          label: Text(photo == null ? s.nameplatePhoto : s.photoAdded),
        ),
        const SizedBox(height: 16),
        FilledButton(onPressed: busy ? null : _save, child: Text(s.save)),
      ]),
    );
  }
}

/// M-14: العداد، وقطع المعدة، والسجل
class EquipmentScreenDetail extends ConsumerWidget {
  const EquipmentScreenDetail({super.key, required this.id});
  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final async = ref.watch(equipmentProvider(id));
    final history = ref.watch(equipmentHistoryProvider(id));
    final t = Theme.of(context).textTheme;
    final lang = Localizations.localeOf(context).languageCode;

    void refresh() {
      ref.invalidate(equipmentProvider(id));
      ref.invalidate(equipmentHistoryProvider(id));
      ref.invalidate(fleetProvider);
    }

    return Scaffold(
      appBar: AppBar(
        title: Text('${async.value?['nickname'] ?? async.value?['machine'] ?? ''}'),
        actions: [
          if (async.value != null)
            IconButton(
              tooltip: s.edit,
              icon: const Icon(Icons.edit_outlined),
              onPressed: () async {
                final ok = await Navigator.of(context).push<bool>(MaterialPageRoute(
                    builder: (_) => EquipmentFormScreen(item: async.value, organizationId: organizationOf(accountOf(async.value!)))));
                if (ok == true) refresh();
              },
            ),
        ],
      ),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: refresh),
        data: (e) {
          final plate = (e['nameplate'] as Map?)?['url'] as String?;
          return ListView(padding: screenPadding(context), children: [
            Text('${e['machine']}', style: t.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
            Text([
              if (e['year'] != null) '${e['year']}',
              if (e['serial_number'] != null) ltr('${e['serial_number']}'),
              if (e['site'] != null) '${(e['site'] as Map)['label']}',
              if (e['account'] != null) '${(e['account'] as Map)['name']}',
            ].join(' · ')),
            if (plate != null)
              Align(alignment: AlignmentDirectional.centerStart, child: TextButton.icon(onPressed: () => openExternal(plate), icon: const Icon(Icons.image_outlined), label: Text(s.nameplatePhoto))),
            const SizedBox(height: 16),
            Text(s.hourMeter, style: t.titleSmall),
            Text(e['hour_meter'] == null ? s.noReading : s.hoursShort(e['hour_meter'] as Object), style: t.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
            const SizedBox(height: 8),
            FilledButton(
              onPressed: () async {
                final ok = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => ReadingSheet(id: id));
                if (ok == true) refresh();
              },
              child: Text(s.updateMeter),
            ),
            const SizedBox(height: 8),
            if (e['model_id'] != null)
              OutlinedButton.icon(
                icon: const Icon(Icons.settings_input_component_outlined),
                label: Text(s.partsForMachine),
                onPressed: () => context.push(Uri(path: '/parts/for-machine', queryParameters: {'model': e['model_id'], 'equipment': id}).toString()),
              )
            else ...[
              Text(s.notLinked, style: t.bodySmall),
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed: () => context.push(Uri(path: '/requests/new', queryParameters: {'type': 'parts', 'equipment': id}).toString()),
                child: Text(s.requestPartForMachine),
              ),
            ],
            const SizedBox(height: 8),
            OutlinedButton(onPressed: () => context.push('/service/new?equipment=${e['id']}'), child: Text(s.requestService)),
            const SizedBox(height: 20),
            Text(s.history, style: t.titleMedium),
            ...history.when(
              loading: () => [const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()))],
              error: (_, _) => [LoadError(onRetry: () => ref.invalidate(equipmentHistoryProvider(id)))],
              data: (rows) => [
                if (rows.isEmpty) Text(s.noHistory),
                for (final h in rows)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(h['type'] == 'reading' ? Icons.speed : Icons.request_quote_outlined),
                    title: Text(h['type'] == 'reading' ? s.hoursShort(h['reading'] as Object) : '${ltr('${h['number']}')} · ${s.rfqStatus('${h['status']}')}'),
                    subtitle: Text([
                      [formatDateTime(h['at'] as String, lang), if (h['by'] != null) '${h['by']}'].join(' · '),
                      if (h['needs_review'] == true) s.needsReview,
                      if (h['note'] != null) '${h['note']}',
                    ].join('\n')),
                    onTap: h['type'] == 'rfq'
                        ? () => context.push('/requests/${h['id']}')
                        : h['photo_url'] != null ? () => openExternal(h['photo_url'] as String) : null,
                  ),
              ],
            ),
            const SizedBox(height: 16),
            TextButton.icon(
              icon: const Icon(Icons.delete_outline),
              label: Text(s.removeFromFleet),
              onPressed: () async {
                if (!await askConfirm(context, s.removeFleetQuestion) || !context.mounted) return;
                final ok = await runAction(context, () => ref.read(apiProvider).delete('/me/fleet/$id'));
                if (!ok || !context.mounted) return;
                ref.invalidate(fleetProvider);
                Navigator.of(context).pop();
              },
            ),
          ]);
        },
      ),
    );
  }
}

/// تحديث العداد بلوحة أرقام وحقل كبير، وتحذير القراءة الأقل (US-10)
class ReadingSheet extends ConsumerStatefulWidget {
  const ReadingSheet({super.key, required this.id});
  final String id;

  @override
  ConsumerState<ReadingSheet> createState() => _ReadingSheetState();
}

class _ReadingSheetState extends ConsumerState<ReadingSheet> {
  final reading = TextEditingController();
  final note = TextEditingController();
  Object? previous; // غير فارغ = حذّر الخادم، وبانتظار التأكيد
  String? photo;
  String? error;
  bool busy = false;

  @override
  void dispose() {
    reading.dispose();
    note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final value = int.tryParse(reading.text);
    if (value == null) return setState(() => error = S.of(context).required);
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await ref.read(apiProvider).post('/me/fleet/${widget.id}/hour-readings', {
        'reading': value,
        'confirm_lower': previous != null,
        'note': note.text.trim().isEmpty ? null : note.text.trim(),
        'photo_id': photo,
      });
      if (mounted) Navigator.pop(context, true);
      return;
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        if (e.code == 'HOUR_METER_LOWER') {
          previous = e.details['previous'] as Object? ?? '';
        } else {
          error = failureText(e, S.of(context));
        }
      });
    }
    if (mounted) setState(() => busy = false);
  }

  Future<void> _photo() async {
    final f = await pickAttachment(context);
    if (f == null || !mounted) return;
    setState(() => busy = true);
    await runAction(context, () async => photo = (await ref.read(apiProvider).upload(f.path, f.name))['id'] as String?);
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.viewInsetsOf(context).bottom + 24),
      child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        TextField(
          controller: reading,
          autofocus: true,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(6)],
          textDirection: TextDirection.ltr,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 36, fontWeight: FontWeight.w800),
          decoration: InputDecoration(labelText: s.hourMeter, errorText: error, errorMaxLines: 3),
          // التأكيد يخص القيمة التي حُذر منها
          onChanged: (_) {
            if (previous != null) setState(() => previous = null);
          },
        ),
        const SizedBox(height: 12),
        if (previous != null) ...[
          Semantics(liveRegion: true, child: Text(s.lowerReading(previous!), style: TextStyle(color: scheme.error, fontWeight: FontWeight.w700))),
          const SizedBox(height: 12),
          Input(note, s.lowerReason),
        ],
        OutlinedButton.icon(
          onPressed: busy ? null : _photo,
          icon: Icon(photo == null ? Icons.photo_camera_outlined : Icons.check),
          label: Text(photo == null ? s.meterPhoto : s.photoAdded),
        ),
        const SizedBox(height: 12),
        FilledButton(onPressed: busy ? null : _save, child: Text(previous == null ? s.save : s.confirmLower)),
      ])),
    );
  }
}
