import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api.dart';
import 'outbox.dart';

/// بيانات من الخادم، أو آخر نسخة محفوظة على الجهاز إن لم يوجد اتصال (FR-TEC-07)
class Fetched {
  Fetched(this.data, this.fetchedAt, {this.fromCache = false});
  final Map<String, dynamic> data;
  final DateTime fetchedAt;
  final bool fromCache;
}

Future<Fetched> _fetch(Ref ref, String path, String cacheKey) async {
  final store = ref.read(outboxStoreProvider);
  final outbox = ref.read(outboxProvider.notifier);
  final sub = outbox.synced.listen((_) => ref.invalidateSelf());
  ref.onDispose(sub.cancel);
  final now = DateTime.now().toUtc(); // بدء الجلب: ما قبله الخادم قبلها موجود في الرد
  try {
    final data = await ref.read(apiProvider).get(path);
    await store.writeCache(cacheKey, {'data': data, 'fetched_at': now.toIso8601String()});
    outbox.markOnline();
    return Fetched(data, now);
  } on ApiException catch (e) {
    if (e.code != 'OFFLINE' && e.status < 500) rethrow;
    outbox.markOffline();
    final cached = await store.readCache(cacheKey);
    if (cached == null) rethrow;
    return Fetched((cached['data'] as Map).cast<String, dynamic>(), DateTime.parse(cached['fetched_at'] as String), fromCache: true);
  }
}

/// T-02. بعد كل جلب ناجح تُحفظ تفاصيل مهام اليوم أيضاً، لتفتح دون اتصال.
final tasksProvider = FutureProvider<Fetched>((ref) async {
  final f = await _fetch(ref, '/tech/tasks', 'tasks');
  if (!f.fromCache) {
    for (final t in (f.data['today'] as List? ?? const []).cast<Map<String, dynamic>>()) {
      unawaited(ref.read(apiProvider).get('/tech/tasks/${t['id']}').then((d) => ref.read(outboxStoreProvider)
          .writeCache('task_${t['id']}', {'data': d, 'fetched_at': f.fetchedAt.toIso8601String()})).catchError((_) {}));
    }
  }
  return f;
});

final taskProvider = FutureProvider.family<Fetched, String>((ref, id) => _fetch(ref, '/tech/tasks/$id', 'task_$id'));

/// المهمة كما يراها الفني الآن: بيانات الخادم وفوقها ما لم يُرسل بعد من هذا الجهاز (عرض متفائل)
class TaskView {
  TaskView(this.server, this.pending, this.fetchedAt);

  final Map<String, dynamic> server;
  final List<OutboxEvent> pending;
  final DateTime fetchedAt;

  String get id => server['id'] as String;
  String get number => server['number'] as String? ?? '';
  String get serverStatus => server['status'] as String? ?? 'scheduled';
  bool get received => server['received'] == true;
  bool get isComponent => server['is_component'] == true;
  bool get assigned => server['my_role'] != null;
  DateTime? get scheduledStart => server['scheduled_start'] == null ? null : DateTime.parse(server['scheduled_start'] as String);

  Iterable<OutboxEvent> get _statusEvents => pending.where((e) => e.type == 'status_changed');

  String get status {
    var s = serverStatus;
    for (final e in _statusEvents) {
      s = e.payload['to'] as String? ?? s;
    }
    return s;
  }

  String? get holdReason {
    String? r = server['on_hold_reason'] as String?;
    for (final e in _statusEvents) {
      r = e.payload['to'] == 'on_hold' ? e.payload['reason'] as String? : null;
    }
    return r;
  }

  bool get hasPending => pending.any((e) => e.appliedAt == null);

  /// وقت العمل: ما حسبه الخادم عند الجلب، ثم الفترات من أحداث الجهاز (الخادم يعيد حسابه من الأحداث نفسها)
  Duration worked(DateTime now) {
    var seconds = (server['worked_seconds'] as num? ?? 0).toInt();
    DateTime? since = serverStatus == 'in_progress' ? fetchedAt : null;
    for (final e in _statusEvents) {
      if (e.payload['to'] == 'in_progress' && since == null) since = e.occurredAt;
      if (e.payload['to'] == 'on_hold' && since != null) {
        seconds += e.occurredAt.difference(since).inSeconds.clamp(0, 1 << 31);
        since = null;
      }
    }
    if (since != null) seconds += now.toUtc().difference(since).inSeconds.clamp(0, 1 << 31);
    return Duration(seconds: seconds);
  }

  bool get dueToday {
    final s = scheduledStart;
    if (s == null) return true;
    final local = s.toLocal();
    final n = DateTime.now();
    return !DateTime(local.year, local.month, local.day).isAfter(DateTime(n.year, n.month, n.day));
  }

  List<Map<String, dynamic>> get photos => [
        ...(server['photos'] as List? ?? const []).cast<Map<String, dynamic>>(),
        for (final e in pending.where((e) => e.type == 'photo_added'))
          {'id': e.id, 'phase': e.payload['phase'], 'local': e.localFile, 'pending': true},
      ];

  List<Map<String, dynamic>> get notes => [
        ...(server['notes'] as List? ?? const []).cast<Map<String, dynamic>>(),
        for (final e in pending.where((e) => e.type == 'note_added')) {'id': e.id, 'body': e.payload['body'], 'pending': true},
      ];

  List<Map<String, dynamic>> get parts {
    final removed = pending.where((e) => e.type == 'part_removed').map((e) => e.payload['id']).toSet();
    return [
      for (final p in (server['parts'] as List? ?? const []).cast<Map<String, dynamic>>())
        {...p, if (removed.contains(p['id'])) 'removed': true},
      for (final e in pending.where((e) => e.type == 'part_used'))
        if (!removed.contains(e.payload['id']))
          {'id': e.payload['id'], 'name': e.payload['label'] ?? e.payload['description'], 'part_number': e.payload['part_number'],
            'quantity': e.payload['quantity'], 'source': e.payload['source'], 'removed': false, 'pending': true},
    ];
  }

  int? get lastReading {
    int? r = ((server['equipment'] as Map?)?['current_hour_meter'] as num?)?.toInt();
    for (final e in pending.where((e) => e.type == 'hour_meter_recorded')) {
      final v = (e.payload['reading'] as num?)?.toInt();
      if (v != null && (r == null || v > r)) r = v;
    }
    return r;
  }

  bool get meterPending => pending.any((e) => e.type == 'hour_meter_recorded');
}

/// التفاصيل مع الأحداث المعلقة لهذا الأمر
final taskViewProvider = Provider.family<AsyncValue<TaskView>, String>((ref, id) {
  final outbox = ref.watch(outboxProvider);
  return ref.watch(taskProvider(id)).whenData((f) => TaskView(f.data, outbox.overlayFor(id, f.fetchedAt), f.fetchedAt));
});

/// رقم المهمة الجارية لهذا الفني (غير المهمة المعطاة)، من القائمة والأحداث المعلقة
String? _activeOther(Fetched? tasks, OutboxState outbox, String id) {
  if (tasks == null) return null;
  for (final t in [...(tasks.data['today'] as List? ?? const []), ...(tasks.data['upcoming'] as List? ?? const [])].cast<Map<String, dynamic>>()) {
    if (t['id'] == id || t['my_role'] == null) continue;
    if (TaskView(t, outbox.overlayFor(t['id'] as String, tasks.fetchedAt), tasks.fetchedAt).status == 'in_progress') return t['number'] as String?;
  }
  return null;
}

final activeOtherProvider = Provider.family<String?, String>(
    (ref, id) => _activeOther(ref.watch(tasksProvider).value, ref.watch(outboxProvider), id));
