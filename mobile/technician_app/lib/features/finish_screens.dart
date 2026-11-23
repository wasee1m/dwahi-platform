import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart' show DateFormat;
import 'package:uuid/uuid.dart';

import '../core/api.dart';
import '../core/outbox.dart';
import '../core/strings.dart';
import '../core/tasks.dart';
import 'task_screens.dart';

/// T-08: طلب عمل إضافي مع صورة، والفني يختار المتابعة أو الإيقاف بانتظار الموافقة (4a.1)
class AdditionalSheet extends ConsumerStatefulWidget {
  const AdditionalSheet({super.key});

  @override
  ConsumerState<AdditionalSheet> createState() => _AdditionalSheetState();
}

class _AdditionalSheetState extends ConsumerState<AdditionalSheet> {
  final _desc = TextEditingController();
  final _parts = TextEditingController();
  final _amount = TextEditingController();
  bool _hold = false;
  String? _photo;

  @override
  void initState() {
    super.initState();
    _desc.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _desc.dispose();
    _parts.dispose();
    _amount.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.viewInsetsOf(context).bottom + MediaQuery.paddingOf(context).bottom),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(s.additionalTitle, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          const SizedBox(height: 8),
          TextField(key: const Key('add-desc'), controller: _desc, minLines: 2, maxLines: 5, maxLength: 2000, decoration: InputDecoration(labelText: s.additionalHint)),
          TextField(key: const Key('add-parts'), controller: _parts, decoration: InputDecoration(labelText: s.partsNeeded), maxLength: 500),
          TextField(key: const Key('add-amount'), controller: _amount, keyboardType: const TextInputType.numberWithOptions(decimal: true),
              textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: s.roughEstimate)),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: () async {
              final p = await ref.read(photoPickerProvider)();
              if (p != null) setState(() => _photo = p);
            },
            icon: Icon(_photo == null ? Icons.add_a_photo_outlined : Icons.check),
            label: Text(s.addPhoto),
          ),
          RadioGroup<bool>(
            groupValue: _hold,
            onChanged: (v) => setState(() => _hold = v ?? false),
            child: Column(children: [
              RadioListTile<bool>(value: false, title: Text(s.continueApproved), contentPadding: EdgeInsets.zero),
              RadioListTile<bool>(key: const Key('add-hold'), value: true, title: Text(s.holdForApproval), contentPadding: EdgeInsets.zero),
            ]),
          ),
          FilledButton(
            key: const Key('add-send'),
            onPressed: _desc.text.trim().isEmpty
                ? null
                : () => Navigator.pop(context, {
                      'payload': {
                        'id': const Uuid().v4(), 'description': _desc.text.trim(),
                        if (_parts.text.trim().isNotEmpty) 'parts_needed': _parts.text.trim(),
                        if (double.tryParse(_amount.text.trim()) != null) 'estimated_amount': _amount.text.trim(),
                        'hold': _hold,
                      },
                      'file': _photo,
                    }),
            child: Text(s.sendToCoordinator),
          ),
        ]),
      ),
    );
  }
}

String _label(BuildContext context, Map<String, dynamic> f) =>
    '${f[Localizations.localeOf(context).languageCode == 'en' ? 'label_en' : 'label_ar'] ?? f['key']}';

/// T-09: الاختبار بقالب نوع العمل، والتقرير، وقائمة BR-07، و"تأكيد الإنهاء"
class FinishScreen extends ConsumerStatefulWidget {
  const FinishScreen(this.id, {super.key});
  final String id;

  @override
  ConsumerState<FinishScreen> createState() => _FinishScreenState();
}

class _FinishScreenState extends ConsumerState<FinishScreen> {
  final _values = <String, dynamic>{};
  final _numbers = <String, TextEditingController>{};
  String? _testPhoto;
  final _findings = TextEditingController();
  final _actions = TextEditingController();
  final _recommend = TextEditingController();
  bool _noParts = false;

  @override
  void initState() {
    super.initState();
    for (final c in [_findings, _actions]) {
      c.addListener(() => setState(() {}));
    }
  }

  @override
  void dispose() {
    for (final c in [..._numbers.values, _findings, _actions, _recommend]) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _number(String key) => _numbers.putIfAbsent(key, () {
        final c = TextEditingController();
        c.addListener(() => setState(() {
              final v = num.tryParse(c.text.trim());
              v == null ? _values.remove(key) : _values[key] = v;
            }));
        return c;
      });

  Future<void> _recordTest(TaskView t) async {
    final tpl = t.template;
    final fields = ((tpl?['fields'] as List?) ?? const []).cast<Map<String, dynamic>>();
    final result = evaluateTest(fields, _values);
    await ref.read(outboxProvider.notifier).add(widget.id, 'test_recorded', {
      'id': const Uuid().v4(), 'template_id': tpl?['id'], 'values': Map.of(_values), 'local_result': result,
    }, file: _testPhoto);
    setState(() => _testPhoto = null);
  }

  Future<void> _finish() async {
    await ref.read(outboxProvider.notifier).add(widget.id, 'report_submitted', {
      'findings': _findings.text.trim(), 'actions_taken': _actions.text.trim(),
      if (_recommend.text.trim().isNotEmpty) 'recommendations': _recommend.text.trim(), 'no_parts_used': _noParts,
    });
    ref.invalidate(tasksProvider);
    if (mounted && context.canPop()) context.pop();
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final view = ref.watch(taskViewProvider(widget.id));
    return Scaffold(
      appBar: AppBar(title: Text(s.testAndFinish)),
      body: view.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(child: Text(e is ApiException && e.code == 'OFFLINE' ? s.offline : s.genericError)),
        data: (t) => _body(context, s, t),
      ),
    );
  }

  Widget _body(BuildContext context, S s, TaskView t) {
    final scheme = Theme.of(context).colorScheme;
    final inspection = t.server['type'] == 'inspection';
    final fields = ((t.template?['fields'] as List?) ?? const []).cast<Map<String, dynamic>>();
    final complete = fields.every((f) => _values.containsKey(f['key']));
    final checks = t.checklist(noPartsUsed: _noParts);
    final ready = t.isLead && checks.values.every((v) => v) && _findings.text.trim().isNotEmpty && (inspection || _actions.text.trim().isNotEmpty);
    return ListView(padding: EdgeInsets.fromLTRB(16, 16, 16, 24 + MediaQuery.paddingOf(context).bottom), children: [
      const SyncBanner(),
      if (!inspection) ...[
        Text('${s.test}${t.template != null ? ' · ${t.template!['name']}' : ''}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        for (final f in fields)
          (f['type'] ?? 'number') == 'boolean'
              ? Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Row(children: [
                    Expanded(child: Text(_label(context, f))),
                    SegmentedButton<bool>(
                      key: Key('test-${f['key']}'),
                      emptySelectionAllowed: true,
                      segments: [ButtonSegment(value: true, label: Text(s.yes)), ButtonSegment(value: false, label: Text(s.no))],
                      selected: _values.containsKey(f['key']) ? {_values[f['key']] as bool} : const {},
                      onSelectionChanged: (v) => setState(() => v.isEmpty ? _values.remove(f['key']) : _values[f['key'] as String] = v.first),
                    ),
                  ]),
                )
              : TextField(
                  key: Key('test-${f['key']}'),
                  controller: _number(f['key'] as String),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  textDirection: TextDirection.ltr,
                  decoration: InputDecoration(labelText: _label(context, f), helperText: s.range(f['min'] as num?, f['max'] as num?, f['unit'] as String?)),
                ),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: [
          OutlinedButton.icon(
            onPressed: () async {
              final p = await ref.read(photoPickerProvider)();
              if (p != null) setState(() => _testPhoto = p);
            },
            icon: Icon(_testPhoto == null ? Icons.photo_camera_outlined : Icons.check),
            label: Text(s.testMedia),
          ),
          FilledButton.tonal(key: const Key('record-test'), onPressed: complete && t.isLead ? () => _recordTest(t) : null, child: Text(s.recordTest)),
        ]),
        if (t.tests.isEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(s.noTests)),
        for (final x in t.tests)
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: Icon(x['result'] == 'pass' ? Icons.check_circle : Icons.cancel, color: x['result'] == 'pass' ? Colors.green : scheme.error),
            title: Text('${x['template'] ?? s.test} · ${x['result'] == 'pass' ? s.pass : s.fail}'),
            subtitle: x['pending'] == true ? Text(s.pendingMark) : null,
          ),
        if (t.tests.isNotEmpty && !t.lastTestPassed) Text(s.testFailedHint, style: TextStyle(color: scheme.error)),
        const Divider(height: 32),
      ],
      TextField(key: const Key('findings'), controller: _findings, minLines: 2, maxLines: 6, decoration: InputDecoration(labelText: s.findings)),
      if (!inspection) TextField(key: const Key('actions'), controller: _actions, minLines: 2, maxLines: 6, decoration: InputDecoration(labelText: s.actionsTaken)),
      TextField(controller: _recommend, minLines: 1, maxLines: 4, decoration: InputDecoration(labelText: s.recommendations)),
      if (!inspection) CheckboxListTile(key: const Key('no-parts'), value: _noParts, onChanged: (v) => setState(() => _noParts = v ?? false),
          title: Text(s.noPartsUsed), contentPadding: EdgeInsets.zero),
      const SizedBox(height: 12),
      Text(s.checklist, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
      for (final e in checks.entries)
        ListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          leading: Icon(e.value ? Icons.check_circle : Icons.radio_button_unchecked, color: e.value ? Colors.green : scheme.error),
          title: Text(s.checkItem(e.key)),
        ),
      if (!t.isLead) Text(s.leadOnly, style: TextStyle(color: scheme.error)),
      const SizedBox(height: 12),
      FilledButton(key: const Key('confirm-finish'), onPressed: ready ? _finish : null, child: Text(s.confirmFinish)),
    ]);
  }
}

/// T-10: المنجزة، وساعات العمل اليومية والأسبوعية، والمعادة من المنسق
final historyProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/tech/history'));

class HistoryScreen extends ConsumerWidget {
  const HistoryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final lang = Localizations.localeOf(context).languageCode;
    String hours(int seconds) => '${seconds ~/ 3600}:${((seconds % 3600) ~/ 60).toString().padLeft(2, '0')}';
    return Scaffold(
      appBar: AppBar(title: Text(s.historyTitle)),
      body: ref.watch(historyProvider).when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (e, _) => Center(child: Text(e is ApiException && e.code == 'OFFLINE' ? s.offline : s.genericError)),
            data: (h) {
              final done = (h['completed'] as List? ?? const []).cast<Map<String, dynamic>>();
              final days = (h['days'] as List? ?? const []).cast<Map<String, dynamic>>().reversed.take(14).toList();
              final weeks = (h['weeks'] as List? ?? const []).cast<Map<String, dynamic>>().reversed.toList();
              final returned = (h['returned'] as List? ?? const []).cast<Map<String, dynamic>>();
              Widget title(String t) => Padding(padding: const EdgeInsets.only(top: 16, bottom: 4), child: Text(t, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)));
              return RefreshIndicator(
                onRefresh: () async => ref.invalidate(historyProvider),
                child: ListView(padding: const EdgeInsets.all(16), children: [
                  title(s.weeklyHours),
                  if (weeks.isEmpty) Text(s.nothingYet),
                  for (final w in weeks) Text('${s.weekOf('${w['week_start']}')}: ${hours((w['seconds'] as num).toInt())}'),
                  title(s.dailyHours),
                  if (days.isEmpty) Text(s.nothingYet),
                  for (final d in days)
                    Text('${DateFormat('EEE d/M', lang).format(DateTime.parse('${d['date']}'))}: ${hours((d['seconds'] as num).toInt())}'),
                  title(s.returnedTasks),
                  if (returned.isEmpty) Text(s.nothingYet),
                  for (final r in returned)
                    ListTile(contentPadding: EdgeInsets.zero, title: Text('${r['number']}'), subtitle: Text('${r['note'] ?? ''}'),
                        onTap: () => context.push('/tasks/${r['id']}')),
                  title(s.completedTasks),
                  if (done.isEmpty) Text(s.nothingYet),
                  for (final t in done)
                    ListTile(contentPadding: EdgeInsets.zero, title: Text('${t['item'] ?? ''} · ${t['number']}'),
                        subtitle: Text('${t['customer'] ?? ''} · ${s.status('${t['status']}')} · ${hours((t['worked_seconds'] as num? ?? 0).toInt())}'),
                        onTap: () => context.push('/tasks/${t['id']}')),
                ]),
              );
            },
          ),
    );
  }
}

/// يُفتح من T-04: الطلب ثم الإضافة للصندوق
Future<void> requestAdditional(BuildContext context, WidgetRef ref, String id) async {
  final r = await showModalBottomSheet<Map<String, dynamic>>(context: context, isScrollControlled: true, builder: (_) => const AdditionalSheet());
  if (r == null) return;
  await ref.read(outboxProvider.notifier).add(id, 'additional_work_requested', (r['payload'] as Map).cast<String, dynamic>(), file: r['file'] as String?);
  if ((r['payload'] as Map)['hold'] == true) unawaited(Future(() => ref.invalidate(tasksProvider)));
}
