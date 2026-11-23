import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart' show DateFormat;
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:uuid/uuid.dart';

import '../core/api.dart';
import '../core/outbox.dart';
import '../core/strings.dart';
import '../core/tasks.dart';
import 'finish_screens.dart';

/// الكاميرا لصور العمل: تُستبدل في الاختبارات
final photoPickerProvider = Provider<Future<String?> Function()>((ref) => () async {
      final x = await ImagePicker().pickImage(source: ImageSource.camera, maxWidth: 1920, imageQuality: 75);
      return x?.path;
    });

String _time(BuildContext context, String? iso) {
  if (iso == null) return '';
  final d = DateTime.parse(iso).toLocal();
  return DateFormat('d/M HH:mm', Localizations.localeOf(context).languageCode).format(d);
}

String clock(Duration d) =>
    '${d.inHours.toString().padLeft(2, '0')}:${(d.inMinutes % 60).toString().padLeft(2, '0')}:${(d.inSeconds % 60).toString().padLeft(2, '0')}';

void _toast(BuildContext context, String text) => ScaffoldMessenger.of(context)..hideCurrentSnackBar()..showSnackBar(SnackBar(content: Text(text)));

/// شريط الاتصال والمعلق (T-02، T-04): يفتح مركز الإرسال
class SyncBanner extends ConsumerWidget {
  const SyncBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final o = ref.watch(outboxProvider);
    if (!o.offline && o.pending.isEmpty && o.rejected.isEmpty) return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;
    final bad = o.rejected.isNotEmpty;
    return Material(
      color: bad ? scheme.errorContainer : (o.offline ? scheme.tertiaryContainer : scheme.secondaryContainer),
      child: InkWell(
        onTap: () => context.push('/sync'),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: Row(children: [
            Icon(o.offline ? Icons.cloud_off_outlined : (o.sending ? Icons.sync : Icons.cloud_upload_outlined), size: 20),
            const SizedBox(width: 10),
            Expanded(
              child: Text([
                if (o.offline) s.offlineBanner,
                if (o.pending.isNotEmpty) s.pendingCount(o.pending.length),
                if (bad) s.rejectedCount(o.rejected.length),
              ].join(' · ')),
            ),
            const Icon(Icons.chevron_left),
          ]),
        ),
      ),
    );
  }
}

/// T-02
class TasksScreen extends ConsumerWidget {
  const TasksScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final tasks = ref.watch(tasksProvider);
    final outbox = ref.watch(outboxProvider);
    Widget list(String key, String empty) => tasks.when(
          loading: () => const Center(child: CircularProgressIndicator()),
          error: (e, _) => _Failure(e, () => ref.invalidate(tasksProvider)),
          data: (f) {
            final rows = (f.data[key] as List? ?? const []).cast<Map<String, dynamic>>();
            return RefreshIndicator(
              onRefresh: () async {
                unawaited(ref.read(outboxProvider.notifier).flush());
                ref.invalidate(tasksProvider);
                await ref.read(tasksProvider.future).catchError((_) => f);
              },
              child: ListView(
                padding: const EdgeInsets.fromLTRB(12, 12, 12, 96),
                children: [
                  if (f.fromCache) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(s.lastUpdated(_time(context, f.fetchedAt.toIso8601String())))),
                  if (rows.isEmpty) Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(empty))),
                  for (final t in rows) _TaskTile(TaskView(t, outbox.overlayFor(t['id'] as String, f.fetchedAt), f.fetchedAt)),
                ],
              ),
            );
          },
        );
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(s.tasks),
          actions: [
            IconButton(tooltip: s.syncCenter, onPressed: () => context.push('/sync'), icon: Badge(
                isLabelVisible: outbox.pending.isNotEmpty, label: Text('${outbox.pending.length}'), child: const Icon(Icons.sync))),
            IconButton(tooltip: s.historyTitle, onPressed: () => context.push('/history'), icon: const Icon(Icons.history)),
            IconButton(tooltip: s.account, onPressed: () => context.push('/account'), icon: const Icon(Icons.person_outline)),
          ],
          bottom: TabBar(tabs: [Tab(text: s.today), Tab(text: s.upcoming)]),
        ),
        body: Column(children: [
          const SyncBanner(),
          Expanded(child: TabBarView(children: [list('today', s.noTasksToday), list('upcoming', s.noUpcoming)])),
        ]),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: () => context.push('/scan'),
          icon: const Icon(Icons.qr_code_scanner),
          label: Text(s.scan),
        ),
      ),
    );
  }
}

class _TaskTile extends StatelessWidget {
  const _TaskTile(this.t);
  final TaskView t;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final scheme = Theme.of(context).colorScheme;
    final row = t.server;
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => context.push('/tasks/${t.id}'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text('${row['item'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
              _StatusChip(t.status),
            ]),
            const SizedBox(height: 4),
            Text('${row['customer'] ?? ''}'),
            const SizedBox(height: 6),
            Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
              Text('${s.type('${row['type']}')} · ${t.number}', style: TextStyle(color: scheme.onSurfaceVariant)),
              if (row['scheduled_start'] != null) Text(_time(context, row['scheduled_start'] as String), style: TextStyle(color: scheme.onSurfaceVariant)),
              if (row['card'] != null) Text('${row['card']}', textDirection: TextDirection.ltr),
              if (row['urgent'] == true) _Pill(s.urgent, scheme.errorContainer),
              if (!t.received) _Pill(s.notReceived, scheme.tertiaryContainer),
              if (row['my_role'] == 'assistant') _Pill(s.assistant, scheme.secondaryContainer),
              if (t.hasPending) const Icon(Icons.cloud_upload_outlined, size: 16),
            ]),
          ]),
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill(this.text, this.color);
  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(20)),
        child: Text(text, style: const TextStyle(fontSize: 12)),
      );
}

class _StatusChip extends StatelessWidget {
  const _StatusChip(this.status);
  final String status;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = switch (status) {
      'in_progress' => scheme.primaryContainer,
      'on_hold' => scheme.tertiaryContainer,
      'cancelled' => scheme.errorContainer,
      _ => scheme.surfaceContainerHighest,
    };
    return _Pill(S.of(context).status(status), color);
  }
}

class _Failure extends StatelessWidget {
  const _Failure(this.error, this.retry);
  final Object error;
  final VoidCallback retry;

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final e = error;
    final text = e is ApiException && e.code == 'OFFLINE' ? s.offline : (e is ApiException ? e.message ?? s.genericError : s.genericError);
    return Center(
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Text(text),
        const SizedBox(height: 8),
        OutlinedButton(onPressed: retry, child: Text(s.retry)),
      ]),
    );
  }
}

/// فتح المهمة من ملصق بطاقة الاستلام (FR-TEC-01): الكاميرا أو كتابة الرقم
class ScanScreen extends ConsumerStatefulWidget {
  const ScanScreen({super.key, this.camera = true});
  final bool camera;

  @override
  ConsumerState<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends ConsumerState<ScanScreen> {
  final _number = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _number.dispose();
    super.dispose();
  }

  /// الملصق يحمل رقم البطاقة RC-YYYY-NNNNNN
  static String? cardFrom(String raw) => RegExp(r'RC-\d{4}-\d{6}', caseSensitive: false).firstMatch(raw)?.group(0)?.toUpperCase();

  Future<void> _open(String raw) async {
    final s = S.of(context);
    final card = cardFrom(raw) ?? raw.trim().toUpperCase();
    if (card.isEmpty || _busy) return;
    setState(() => _busy = true);
    try {
      final r = await ref.read(apiProvider).get('/tech/tasks/by-tag/$card');
      if (mounted) context.pushReplacement('/tasks/${r['id']}');
    } on ApiException catch (e) {
      // دون اتصال: البحث في المهام المحفوظة
      final cached = ref.read(tasksProvider).value?.data;
      final local = e.code == 'OFFLINE' && cached != null
          ? [...(cached['today'] as List? ?? const []), ...(cached['upcoming'] as List? ?? const [])]
              .cast<Map<String, dynamic>>().where((t) => t['card'] == card).firstOrNull
          : null;
      if (local != null && mounted) {
        context.pushReplacement('/tasks/${local['id']}');
      } else if (mounted) {
        _toast(context, e.code == 'NOT_YOUR_TASK' ? s.notYourTask : (e.code == 'OFFLINE' ? s.offline : e.message ?? s.genericError));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(s.scan)),
      body: Column(children: [
        if (widget.camera)
          Expanded(
            child: MobileScanner(onDetect: (capture) {
              final v = capture.barcodes.map((b) => b.rawValue).whereType<String>().firstOrNull;
              if (v != null) _open(v);
            }),
          )
        else
          const Spacer(),
        Padding(
          padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.paddingOf(context).bottom),
          child: Row(children: [
            Expanded(
              child: TextField(key: const Key('card'), controller: _number, textDirection: TextDirection.ltr, textCapitalization: TextCapitalization.characters,
                  decoration: InputDecoration(labelText: s.cardNumber, hintText: 'RC-2026-000001'), onSubmitted: _open),
            ),
            const SizedBox(width: 8),
            FilledButton(onPressed: _busy ? null : () => _open(_number.text), child: Text(s.open)),
          ]),
        ),
      ]),
    );
  }
}

/// T-03 و T-04 و T-06 و T-07: تفاصيل المهمة وتنفيذها. كل إجراء حدث في الصندوق الصادر يظهر فوراً.
class TaskScreen extends ConsumerStatefulWidget {
  const TaskScreen(this.id, {super.key});
  final String id;

  @override
  ConsumerState<TaskScreen> createState() => _TaskScreenState();
}

class _TaskScreenState extends ConsumerState<TaskScreen> {
  Timer? _tick;

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      final v = ref.read(taskViewProvider(widget.id)).value;
      if (mounted && v?.status == 'in_progress') setState(() {});
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _event(String type, Map<String, dynamic> payload, {String? file}) async {
    await ref.read(outboxProvider.notifier).add(widget.id, type, payload, file: file);
    if (type == 'status_changed') ref.invalidate(tasksProvider);
  }

  Future<void> _start(TaskView t) async {
    final s = S.of(context);
    final other = ref.read(activeOtherProvider(widget.id));
    if (other != null) return _toast(context, s.anotherActive(other));
    await _event('status_changed', {'to': 'in_progress'});
  }

  Future<void> _pause() async {
    final r = await showModalBottomSheet<(String, String?)>(context: context, isScrollControlled: true, builder: (_) => const _HoldSheet());
    if (r == null) return;
    await _event('status_changed', {'to': 'on_hold', 'reason': r.$1, if (r.$2 != null && r.$2!.trim().isNotEmpty) 'note': r.$2!.trim()});
  }

  Future<void> _photo() async {
    final phase = await showModalBottomSheet<String>(context: context, builder: (context) {
      final s = S.of(context);
      return SafeArea(
        child: Wrap(children: [
          for (final p in const ['before', 'during', 'after', 'damage', 'test'])
            ListTile(leading: const Icon(Icons.photo_camera_outlined), title: Text(s.phase(p)), onTap: () => Navigator.pop(context, p)),
        ]),
      );
    });
    if (phase == null) return;
    final path = await ref.read(photoPickerProvider)();
    if (path == null) return;
    await _event('photo_added', {'phase': phase}, file: path);
  }

  Future<void> _note() async {
    final body = await showDialog<String>(context: context, builder: (_) => const _TextDialog());
    if (body == null || body.trim().isEmpty) return;
    await _event('note_added', {'id': const Uuid().v4(), 'body': body.trim()});
  }

  Future<void> _meter(TaskView t) async {
    final r = await showDialog<(int, String?)>(context: context, builder: (_) => _MeterDialog(last: t.lastReading, picker: ref.read(photoPickerProvider)));
    if (r == null) return;
    await _event('hour_meter_recorded', {'reading': r.$1}, file: r.$2);
  }

  Future<void> _part() async {
    final p = await showModalBottomSheet<Map<String, dynamic>>(context: context, isScrollControlled: true, builder: (_) => const PartSheet());
    if (p == null) return;
    await _event('part_used', {'id': const Uuid().v4(), ...p});
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final view = ref.watch(taskViewProvider(widget.id));
    return Scaffold(
      appBar: AppBar(title: Text(view.value?.number ?? '')),
      body: view.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => _Failure(e, () => ref.invalidate(taskProvider(widget.id))),
        data: (t) => _body(context, s, t),
      ),
    );
  }

  Widget _body(BuildContext context, S s, TaskView t) {
    final d = t.server;
    final scheme = Theme.of(context).colorScheme;
    final intake = d['intake'] as Map<String, dynamic>?;
    final working = t.status == 'in_progress' || t.status == 'on_hold';
    final canAct = t.assigned && working;
    return Column(children: [
      const SyncBanner(),
      Expanded(
        child: RefreshIndicator(
          onRefresh: () async {
            unawaited(ref.read(outboxProvider.notifier).flush());
            ref.invalidate(taskProvider(widget.id));
          },
          child: ListView(padding: EdgeInsets.fromLTRB(16, 16, 16, 24 + MediaQuery.paddingOf(context).bottom), children: [
            Row(children: [
              Expanded(child: Text('${d['item'] ?? ''}', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800))),
              _StatusChip(t.status),
            ]),
            const SizedBox(height: 4),
            Text('${d['customer'] ?? ''} · ${s.type('${d['type']}')}'),
            if (d['scheduled_start'] != null) Text(_time(context, d['scheduled_start'] as String)),
            const SizedBox(height: 12),
            _ExecutionCard(view: t, onStart: () => _start(t), onPause: _pause),
            _Section(s.problem, [Text('${d['problem_description'] ?? ''}'), if (d['component_details'] != null) Text('${d['component_details']}')]),
            if ((d['instructions'] as String?)?.isNotEmpty ?? false) _Section(s.instructions, [Text('${d['instructions']}')]),
            if ((d['scope'] as List? ?? const []).isNotEmpty)
              _Section(s.scope, [
                for (final i in (d['scope'] as List).cast<Map<String, dynamic>>()) Text('• ${i['description']} × ${i['quantity']}'),
              ]),
            if (intake != null)
              _Section('${s.intake} · ${intake['number']}', [
                if (intake['hour_meter_reading'] != null) Text('${s.intakeReading}: ${intake['hour_meter_reading']}'),
                if (intake['accessories'] != null) Text('${s.accessories}: ${intake['accessories']}'),
                if (intake['condition_notes'] != null) Text('${s.condition}: ${intake['condition_notes']}'),
                if ((intake['photos'] as List? ?? const []).isNotEmpty)
                  SizedBox(
                    height: 72,
                    child: ListView(scrollDirection: Axis.horizontal, children: [
                      for (final u in (intake['photos'] as List).whereType<String>())
                        Padding(padding: const EdgeInsetsDirectional.only(end: 6), child: _Thumb(url: u)),
                    ]),
                  ),
              ]),
            _Section(s.photos, [
              if (t.photos.isEmpty) Text(s.noPhotos),
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final p in t.photos)
                  Stack(children: [
                    _Thumb(url: p['url'] as String?, local: p['local'] as String?),
                    PositionedDirectional(start: 2, bottom: 2, child: _Pill(s.phase('${p['phase']}'), scheme.surface.withValues(alpha: 0.85))),
                    if (p['pending'] == true) const PositionedDirectional(end: 2, top: 2, child: Icon(Icons.cloud_upload_outlined, size: 16)),
                  ]),
              ]),
            ], action: canAct || (t.assigned && t.status == 'scheduled') ? TextButton.icon(onPressed: _photo, icon: const Icon(Icons.add_a_photo_outlined), label: Text(s.addPhoto)) : null),
            if (!t.isComponent)
              _Section(s.hourMeter, [
                Text(t.lastReading == null ? '—' : s.lastReading('${t.lastReading}')),
                if (t.meterPending) Text(s.pendingMark, style: TextStyle(color: scheme.onSurfaceVariant)),
              ], action: canAct ? TextButton.icon(onPressed: () => _meter(t), icon: const Icon(Icons.speed), label: Text(s.recordMeter)) : null),
            _Section(s.parts, [
              if (t.parts.isEmpty) Text(s.noParts),
              for (final p in t.parts)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  title: Text('${p['part_number'] != null ? '${p['part_number']} · ' : ''}${p['name'] ?? ''}',
                      style: p['removed'] == true ? const TextStyle(decoration: TextDecoration.lineThrough) : null),
                  subtitle: Text('${s.source('${p['source']}')} · × ${p['quantity']}${p['pending'] == true ? ' · ${s.pendingMark}' : ''}${p['removed'] == true ? ' · ${s.removed}' : ''}'),
                  trailing: canAct && p['removed'] != true
                      ? IconButton(tooltip: s.remove, icon: const Icon(Icons.delete_outline), onPressed: () => _event('part_removed', {'id': p['id']}))
                      : null,
                ),
            ], action: canAct ? TextButton.icon(onPressed: _part, icon: const Icon(Icons.add), label: Text(s.addPart)) : null),
            if (t.additional.isNotEmpty)
              _Section(s.additionalWork, [
                for (final a in t.additional)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    title: Text('${a['description'] ?? ''}'),
                    subtitle: Text('${s.additionalStatus('${a['status']}')}${a['pending'] == true ? ' · ${s.pendingMark}' : ''}'
                        '${a['rejection_reason'] != null ? '\n${a['rejection_reason']}' : ''}'),
                  ),
              ]),
            if (t.tests.isNotEmpty)
              _Section(s.test, [
                for (final x in t.tests) Text('${x['template'] ?? s.test} · ${x['result'] == 'pass' ? s.pass : s.fail}${x['pending'] == true ? ' · ${s.pendingMark}' : ''}'),
              ]),
            _Section(s.notes, [
              if (t.notes.isEmpty) Text(s.noNotes),
              for (final n in t.notes)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Text('${n['body'] ?? ''}${n['by'] != null ? ' — ${n['by']}' : ''}${n['from_previous_technician'] == true ? ' (${s.fromPrevious})' : ''}${n['pending'] == true ? ' · ${s.pendingMark}' : ''}'),
                ),
            ], action: t.assigned ? TextButton.icon(onPressed: _note, icon: const Icon(Icons.note_add_outlined), label: Text(s.addNote)) : null),
            if ((d['team'] as List? ?? const []).isNotEmpty)
              _Section(s.team, [
                for (final m in (d['team'] as List).cast<Map<String, dynamic>>()) Text('${m['name']}${m['role'] == 'assistant' ? ' · ${s.assistant}' : ''}'),
              ]),
            if ((d['history'] as List? ?? const []).isNotEmpty)
              _Section(s.history, [
                for (final h in (d['history'] as List).cast<Map<String, dynamic>>()) Text('${h['number']} · ${_time(context, h['at'] as String?)}\n${h['problem']}'),
              ]),
          ]),
        ),
      ),
    ]);
  }
}

/// الوقت وأزرار الحالة (FR-TEC-03، FR-WO-03)
class _ExecutionCard extends ConsumerWidget {
  const _ExecutionCard({required this.view, required this.onStart, required this.onPause});
  final TaskView view;
  final VoidCallback onStart;
  final VoidCallback onPause;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final t = view;
    String? blocked;
    if (t.status == 'scheduled' && !t.received) blocked = s.startNeedsIntake;
    if (t.status == 'scheduled' && t.received && !t.dueToday) blocked = s.startNotToday;
    final action = !t.assigned
        ? null
        : switch (t.status) {
            'scheduled' => FilledButton.icon(key: const Key('start'), onPressed: blocked == null ? onStart : null, icon: const Icon(Icons.play_arrow), label: Text(s.start)),
            'in_progress' => FilledButton.tonalIcon(key: const Key('pause'), onPressed: onPause, icon: const Icon(Icons.pause), label: Text(s.pause)),
            'on_hold' => FilledButton.icon(key: const Key('resume'), onPressed: onStart, icon: const Icon(Icons.play_arrow), label: Text(s.resume)),
            _ => null,
          };
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(s.workedTime, style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant)),
              Text(clock(t.worked(DateTime.now())), key: const Key('clock'), textDirection: TextDirection.ltr,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontFeatures: const [FontFeature.tabularFigures()])),
            ]),
            const Spacer(),
            ?action,
          ]),
          if (t.status == 'on_hold' && t.holdReason != null) Text('${s.pauseReason}: ${s.holdReason(t.holdReason!)}'),
          if (blocked != null) Text(blocked, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          if (t.returnedNote != null)
            Padding(padding: const EdgeInsets.only(top: 6), child: Text(s.returnedBy(t.returnedNote!), style: TextStyle(color: Theme.of(context).colorScheme.error))),
          if (t.status == 'work_completed') Padding(padding: const EdgeInsets.only(top: 6), child: Text(s.finishedWaiting)),
          if (t.assigned && (t.status == 'in_progress' || t.status == 'on_hold'))
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Wrap(spacing: 8, runSpacing: 8, children: [
                OutlinedButton.icon(key: const Key('additional'), onPressed: () => requestAdditional(context, ref, t.id),
                    icon: const Icon(Icons.add_task), label: Text(s.additionalWork)),
                if (t.status == 'in_progress')
                  FilledButton.icon(key: const Key('finish'), onPressed: () => context.push('/tasks/${t.id}/finish'),
                      icon: const Icon(Icons.fact_check_outlined), label: Text(s.testAndFinish)),
              ]),
            ),
        ]),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section(this.title, this.children, {this.action});
  final String title;
  final List<Widget> children;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
            ?action,
          ]),
          const SizedBox(height: 6),
          ...children,
        ]),
      );
}

class _Thumb extends StatelessWidget {
  const _Thumb({this.url, this.local});
  final String? url;
  final String? local;

  @override
  Widget build(BuildContext context) {
    final bg = Container(width: 72, height: 72, color: Theme.of(context).colorScheme.surfaceContainerHighest, child: const Icon(Icons.image_outlined));
    final Widget img = local != null
        ? Image.file(File(local!), width: 72, height: 72, fit: BoxFit.cover, errorBuilder: (_, _, _) => bg)
        : (url != null ? Image.network(mediaUrl(url!), width: 72, height: 72, fit: BoxFit.cover, errorBuilder: (_, _, _) => bg) : bg);
    return ClipRRect(borderRadius: BorderRadius.circular(8), child: img);
  }
}

/// روابط الملفات في التطوير تُعاد بـ localhost، والمحاكي يرى جهاز التطوير بعنوان API_URL
String mediaUrl(String url) {
  final api = Uri.parse(apiUrl);
  final u = Uri.parse(url);
  if (u.host == 'localhost' || u.host == '127.0.0.1') return u.replace(host: api.host, port: api.port, scheme: api.scheme).toString();
  return url;
}

/// سبب الإيقاف من القائمة (5.8)
class _HoldSheet extends StatefulWidget {
  const _HoldSheet();

  @override
  State<_HoldSheet> createState() => _HoldSheetState();
}

class _HoldSheetState extends State<_HoldSheet> {
  String? _reason;
  final _note = TextEditingController();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.viewInsetsOf(context).bottom + MediaQuery.paddingOf(context).bottom),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(s.pauseReason, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        RadioGroup<String>(
          groupValue: _reason,
          onChanged: (v) => setState(() => _reason = v),
          child: Column(children: [
            for (final r in const ['awaiting_parts', 'awaiting_approval', 'awaiting_customer', 'end_of_shift', 'other'])
              RadioListTile<String>(value: r, title: Text(s.holdReason(r)), contentPadding: EdgeInsets.zero),
          ]),
        ),
        TextField(controller: _note, decoration: InputDecoration(labelText: s.noteOptional), maxLength: 300),
        FilledButton(
          key: const Key('confirm-pause'),
          onPressed: _reason == null ? null : () => Navigator.pop(context, (_reason!, _note.text)),
          child: Text(s.pause),
        ),
      ]),
    );
  }
}

class _TextDialog extends StatefulWidget {
  const _TextDialog();

  @override
  State<_TextDialog> createState() => _TextDialogState();
}

class _TextDialogState extends State<_TextDialog> {
  final _c = TextEditingController();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return AlertDialog(
      title: Text(s.addNote),
      content: TextField(key: const Key('note'), controller: _c, autofocus: true, minLines: 2, maxLines: 6, maxLength: 2000,
          decoration: InputDecoration(helperText: s.noteHint)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(s.cancel)),
        FilledButton(onPressed: () => Navigator.pop(context, _c.text), child: Text(s.save)),
      ],
    );
  }
}

/// قراءة العداد مع صورته (FR-TEC-04)
class _MeterDialog extends StatefulWidget {
  const _MeterDialog({required this.last, required this.picker});
  final int? last;
  final Future<String?> Function() picker;

  @override
  State<_MeterDialog> createState() => _MeterDialogState();
}

class _MeterDialogState extends State<_MeterDialog> {
  final _c = TextEditingController();
  String? _photo;

  @override
  void initState() {
    super.initState();
    _c.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final value = int.tryParse(_c.text.trim());
    final lower = value != null && widget.last != null && value < widget.last!;
    return AlertDialog(
      title: Text(s.recordMeter),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (widget.last != null) Text(s.lastReading('${widget.last}')),
        TextField(key: const Key('reading'), controller: _c, keyboardType: TextInputType.number, textDirection: TextDirection.ltr,
            decoration: InputDecoration(labelText: s.reading)),
        if (lower) Padding(padding: const EdgeInsets.only(top: 6), child: Text(s.lowerReading, style: TextStyle(color: Theme.of(context).colorScheme.error))),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          onPressed: () async {
            final p = await widget.picker();
            if (p != null) setState(() => _photo = p);
          },
          icon: Icon(_photo == null ? Icons.photo_camera_outlined : Icons.check),
          label: Text(s.meterPhoto),
        ),
      ]),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(s.cancel)),
        FilledButton(key: const Key('save-reading'), onPressed: value == null || value < 0 ? null : () => Navigator.pop(context, (value, _photo)), child: Text(s.save)),
      ],
    );
  }
}

/// T-07: قطعة من مخزن الفرع بالبحث (يحتاج اتصالاً)، أو من العميل أو مشتراة بالوصف (تعمل دون اتصال)
class PartSheet extends ConsumerStatefulWidget {
  const PartSheet({super.key});

  @override
  ConsumerState<PartSheet> createState() => _PartSheetState();
}

class _PartSheetState extends ConsumerState<PartSheet> {
  String _source = 'store';
  final _q = TextEditingController();
  final _qty = TextEditingController(text: '1');
  final _desc = TextEditingController();
  Map<String, dynamic>? _picked;
  List<Map<String, dynamic>> _found = const [];
  String? _error;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    for (final c in [_qty, _desc]) {
      c.addListener(() => setState(() {}));
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _q.dispose();
    _qty.dispose();
    _desc.dispose();
    super.dispose();
  }

  void _search(String q) {
    _debounce?.cancel();
    if (q.trim().length < 2) return;
    _debounce = Timer(const Duration(milliseconds: 350), () async {
      final s = S.of(context);
      try {
        final page = await ref.read(apiProvider).page('/tech/parts', query: {'q': q.trim()});
        if (mounted) {
          setState(() {
            _found = page.rows;
            _error = null;
          });
        }
      } on ApiException catch (e) {
        if (mounted) {
          setState(() {
            _found = const [];
            _error = e.code == 'OFFLINE' ? s.storeNeedsConnection : (e.message ?? s.genericError);
          });
        }
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final qty = double.tryParse(_qty.text.trim());
    final ok = qty != null && qty > 0 && (_source == 'store' ? _picked != null : _desc.text.trim().isNotEmpty);
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.viewInsetsOf(context).bottom + MediaQuery.paddingOf(context).bottom),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        SegmentedButton<String>(
          segments: [
            ButtonSegment(value: 'store', label: Text(s.fromStore)),
            ButtonSegment(value: 'customer_supplied', label: Text(s.fromCustomer)),
            ButtonSegment(value: 'purchased', label: Text(s.purchased)),
          ],
          selected: {_source},
          onSelectionChanged: (v) => setState(() => _source = v.first),
        ),
        const SizedBox(height: 12),
        if (_source == 'store') ...[
          if (_picked == null) ...[
            TextField(key: const Key('part-search'), controller: _q, onChanged: _search, textDirection: TextDirection.ltr,
                decoration: InputDecoration(labelText: s.searchParts, prefixIcon: const Icon(Icons.search))),
            if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!)),
            ConstrainedBox(
              constraints: const BoxConstraints(maxHeight: 260),
              child: ListView(shrinkWrap: true, children: [
                for (final p in _found)
                  ListTile(
                    title: Text('${p['part_number']} · ${p['name']}'),
                    subtitle: Text(s.available('${p['available']}')),
                    enabled: (double.tryParse('${p['available']}') ?? 0) > 0,
                    onTap: () => setState(() => _picked = p),
                  ),
              ]),
            ),
          ] else
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text('${_picked!['part_number']} · ${_picked!['name']}'),
              subtitle: Text(s.available('${_picked!['available']}')),
              trailing: IconButton(tooltip: s.cancel, icon: const Icon(Icons.close), onPressed: () => setState(() => _picked = null)),
            ),
        ] else
          TextField(key: const Key('part-desc'), controller: _desc, decoration: InputDecoration(labelText: s.description), maxLength: 300),
        TextField(key: const Key('part-qty'), controller: _qty, keyboardType: const TextInputType.numberWithOptions(decimal: true),
            textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: s.quantity)),
        const SizedBox(height: 12),
        FilledButton(
          key: const Key('add-part'),
          onPressed: ok
              ? () => Navigator.pop(context, {
                    'source': _source,
                    'quantity': _qty.text.trim(),
                    if (_source == 'store') ...{'part_id': _picked!['id'], 'part_number': _picked!['part_number'], 'label': _picked!['name']}
                    else 'description': _desc.text.trim(),
                  })
              : null,
          child: Text(s.addPart),
        ),
      ]),
    );
  }
}
