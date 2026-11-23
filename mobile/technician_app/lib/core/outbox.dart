import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';

import 'api.dart';

/// حدث في الصندوق الصادر (04 §6.2): يُحفظ على الجهاز قبل أي إرسال، ويرسل بترتيبه مرة واحدة بمعرّفه.
/// الصورة تُنسخ لمجلد التطبيق وتُرفع أولاً، ثم يُرسل الحدث بمعرّف الملف.
class OutboxEvent {
  OutboxEvent({
    required this.id,
    required this.workOrderId,
    required this.type,
    required this.occurredAt,
    required this.payload,
    this.localFile,
    this.state = 'pending',
    this.code,
    this.message,
  });

  factory OutboxEvent.fromJson(Map<String, dynamic> j) => OutboxEvent(
        id: j['id'] as String,
        workOrderId: j['work_order_id'] as String,
        type: j['type'] as String,
        occurredAt: DateTime.parse(j['occurred_at'] as String),
        payload: (j['payload'] as Map? ?? const {}).cast<String, dynamic>(),
        localFile: j['local_file'] as String?,
        state: j['state'] as String? ?? 'pending',
        code: j['code'] as String?,
        message: j['message'] as String?,
      );

  final String id;
  final String workOrderId;
  final String type;
  final DateTime occurredAt;
  final Map<String, dynamic> payload;
  String? localFile;
  String state; // pending | rejected
  String? code;
  String? message;

  /// وقت قبول الخادم له (في الذاكرة فقط): يبقى في العرض حتى جلب يبدأ بعده
  DateTime? appliedAt;

  bool get pending => state == 'pending';

  /// مفتاح معرّف الملف في الحمولة بعد رفعه
  String get fileKey => type == 'hour_meter_recorded' ? 'photo_id' : 'attachment_id';

  bool get needsUpload => localFile != null && payload[fileKey] == null;

  Map<String, dynamic> toJson() => {
        'id': id, 'work_order_id': workOrderId, 'type': type, 'occurred_at': occurredAt.toUtc().toIso8601String(),
        'payload': payload, 'local_file': localFile, 'state': state, 'code': code, 'message': message,
      };

  Map<String, dynamic> toWire() => {
        'client_event_id': id, 'work_order_id': workOrderId, 'type': type,
        'occurred_at': occurredAt.toUtc().toIso8601String(), 'payload': payload,
      };
}

/// تخزين الصندوق ونسخة المهام للعمل دون اتصال (NFR-17): ملف JSON يُكتب كاملاً ثم يُستبدل، فلا يتلف عند إغلاق التطبيق
abstract class OutboxStore {
  Future<List<OutboxEvent>> load();
  Future<void> save(List<OutboxEvent> events);

  /// ينسخ الصورة إلى مجلد التطبيق (صورة المعرض أو الكاميرا المؤقتة قد تُحذف)
  Future<String> keepFile(String sourcePath);
  Future<void> dropFile(String path);
  Future<Map<String, dynamic>?> readCache(String key);
  Future<void> writeCache(String key, Map<String, dynamic> value);
  Future<void> clearAll();
}

class FileOutboxStore implements OutboxStore {
  Directory? _dir;

  Future<Directory> _root() async {
    final d = _dir ??= Directory('${(await getApplicationSupportDirectory()).path}/dwahi_tech');
    if (!await d.exists()) await d.create(recursive: true);
    return d;
  }

  Future<void> _writeAtomic(File f, String text) async {
    final tmp = File('${f.path}.tmp');
    await tmp.writeAsString(text, flush: true);
    await tmp.rename(f.path);
  }

  @override
  Future<List<OutboxEvent>> load() async {
    final f = File('${(await _root()).path}/outbox.json');
    if (!await f.exists()) return [];
    try {
      final list = jsonDecode(await f.readAsString()) as List;
      return list.cast<Map<String, dynamic>>().map(OutboxEvent.fromJson).toList();
    } catch (_) {
      return [];
    }
  }

  @override
  Future<void> save(List<OutboxEvent> events) async =>
      _writeAtomic(File('${(await _root()).path}/outbox.json'), jsonEncode(events.map((e) => e.toJson()).toList()));

  @override
  Future<String> keepFile(String sourcePath) async {
    final photos = Directory('${(await _root()).path}/photos');
    if (!await photos.exists()) await photos.create();
    final ext = sourcePath.contains('.') ? sourcePath.substring(sourcePath.lastIndexOf('.')) : '.jpg';
    final target = '${photos.path}/${const Uuid().v4()}$ext';
    await File(sourcePath).copy(target);
    return target;
  }

  @override
  Future<void> dropFile(String path) async {
    try {
      await File(path).delete();
    } catch (_) {
      // حُذف من قبل
    }
  }

  @override
  Future<Map<String, dynamic>?> readCache(String key) async {
    final f = File('${(await _root()).path}/cache_$key.json');
    if (!await f.exists()) return null;
    try {
      return (jsonDecode(await f.readAsString()) as Map).cast<String, dynamic>();
    } catch (_) {
      return null;
    }
  }

  @override
  Future<void> writeCache(String key, Map<String, dynamic> value) async =>
      _writeAtomic(File('${(await _root()).path}/cache_$key.json'), jsonEncode(value));

  @override
  Future<void> clearAll() async {
    final d = await _root();
    await d.delete(recursive: true);
    _dir = null;
  }
}

/// للاختبارات
class MemoryOutboxStore implements OutboxStore {
  List<OutboxEvent> saved = [];
  final cache = <String, Map<String, dynamic>>{};
  final dropped = <String>[];

  @override
  Future<List<OutboxEvent>> load() async => saved.map((e) => OutboxEvent.fromJson(e.toJson())).toList();

  @override
  Future<void> save(List<OutboxEvent> events) async => saved = events.map((e) => OutboxEvent.fromJson(e.toJson())).toList();

  @override
  Future<String> keepFile(String sourcePath) async => sourcePath;

  @override
  Future<void> dropFile(String path) async => dropped.add(path);

  @override
  Future<Map<String, dynamic>?> readCache(String key) async => cache[key];

  @override
  Future<void> writeCache(String key, Map<String, dynamic> value) async => cache[key] = value;

  @override
  Future<void> clearAll() async {
    saved = [];
    cache.clear();
  }
}

final outboxStoreProvider = Provider<OutboxStore>((ref) => FileOutboxStore());

class OutboxState {
  const OutboxState({this.events = const [], this.applied = const [], this.sending = false, this.offline = false, this.lastSync, this.loaded = false});
  final List<OutboxEvent> events;

  /// ما قبله الخادم مؤخراً: لا يرجع العرض لبيانات أقدم منه قبل الجلب التالي
  final List<OutboxEvent> applied;
  final bool sending;
  final bool offline;
  final DateTime? lastSync;
  final bool loaded;

  List<OutboxEvent> get pending => events.where((e) => e.pending).toList();
  List<OutboxEvent> get rejected => events.where((e) => !e.pending).toList();

  List<OutboxEvent> pendingFor(String workOrderId) => pending.where((e) => e.workOrderId == workOrderId).toList();

  /// أحداث الأمر فوق بيانات جُلبت في fetchedAt: المقبول بعد بدء الجلب، ثم المعلق
  List<OutboxEvent> overlayFor(String workOrderId, DateTime fetchedAt) => [
        ...applied.where((e) => e.workOrderId == workOrderId && e.appliedAt!.isAfter(fetchedAt)),
        ...pendingFor(workOrderId),
      ];

  OutboxState copy({List<OutboxEvent>? events, List<OutboxEvent>? applied, bool? sending, bool? offline, DateTime? lastSync, bool? loaded}) => OutboxState(
      events: events ?? this.events, applied: applied ?? this.applied, sending: sending ?? this.sending, offline: offline ?? this.offline,
      lastSync: lastSync ?? this.lastSync, loaded: loaded ?? this.loaded);
}

/// الإرسال: عند التشغيل، وكل 60 ثانية، وبعد كل إجراء، وبطلب الفني (T-11). نتيجة كل حدث من الخادم:
/// applied أو duplicate فيُحذف، أو rejected فيبقى في مركز الإرسال بسببه.
class Outbox extends Notifier<OutboxState> {
  static const interval = Duration(seconds: 60);
  Timer? _timer;
  Future<void>? _loading;
  Future<void>? _inflight;

  /// بعد كل إرسال ناجح: لتحدّث الشاشات بياناتها من الخادم
  final _synced = StreamController<void>.broadcast();
  Stream<void> get synced => _synced.stream;

  OutboxStore get _store => ref.read(outboxStoreProvider);

  @override
  OutboxState build() {
    _loading = _load();
    _timer = Timer.periodic(interval, (_) => flush());
    ref.onDispose(() {
      _timer?.cancel();
      _synced.close();
    });
    return const OutboxState();
  }

  Future<void> _load() async {
    final events = await _store.load();
    state = state.copy(events: events, loaded: true);
  }

  Future<void> ready() => _loading ?? Future.value();

  /// يسجّل الحدث على الجهاز أولاً، ثم يحاول إرساله
  Future<OutboxEvent> add(String workOrderId, String type, Map<String, dynamic> payload, {String? file}) async {
    await ready();
    final e = OutboxEvent(
      id: const Uuid().v4(), workOrderId: workOrderId, type: type, occurredAt: DateTime.now().toUtc(),
      payload: payload, localFile: file == null ? null : await _store.keepFile(file),
    );
    final events = [...state.events, e];
    await _store.save(events);
    state = state.copy(events: events);
    unawaited(flush());
    return e;
  }

  Future<void> dismiss(String id) async {
    final gone = state.events.where((e) => e.id == id).toList();
    final events = state.events.where((e) => e.id != id).toList();
    await _store.save(events);
    for (final e in gone) {
      if (e.localFile != null) await _store.dropFile(e.localFile!);
    }
    state = state.copy(events: events);
  }

  /// إرسال واحد في كل مرة: الطلب أثناء إرسال جارٍ ينتظره
  Future<void> flush() async {
    await ready();
    final running = _inflight;
    if (running != null) return running;
    if (state.pending.isEmpty) return;
    final f = _inflight = _flush();
    try {
      await f;
    } finally {
      _inflight = null;
    }
  }

  Future<void> _flush() async {
    state = state.copy(sending: true);
    final api = ref.read(apiProvider);
    var offline = false;
    var more = false;
    try {
      // 1) الصور أولاً بالترتيب
      for (final e in state.pending.where((e) => e.needsUpload)) {
        try {
          final file = e.localFile!;
          final res = await api.upload(file, file.split(RegExp(r'[\\/]')).last);
          e.payload[e.fileKey] = res['id'];
          await _store.save(state.events);
        } on ApiException catch (x) {
          if (x.code == 'OFFLINE' || x.status >= 500 || x.status == 429) {
            offline = x.code == 'OFFLINE';
            return;
          }
          e
            ..state = 'rejected'
            ..code = x.code
            ..message = x.message;
          await _store.save(state.events);
        }
      }
      // 2) الأحداث الجاهزة بالترتيب، على دفعات
      final ready = state.pending.where((e) => !e.needsUpload).take(100).toList();
      if (ready.isEmpty) return;
      final data = await api.post('/tech/sync/events', {'events': ready.map((e) => e.toWire()).toList()});
      final results = {
        for (final r in (data['results'] as List? ?? const []).cast<Map<String, dynamic>>()) r['client_event_id'] as String: r,
      };
      final done = <OutboxEvent>[];
      for (final e in ready) {
        final r = results[e.id];
        if (r == null) continue;
        if (r['result'] == 'rejected') {
          e
            ..state = 'rejected'
            ..code = r['code'] as String?
            ..message = r['message'] as String?;
        } else {
          done.add(e);
        }
      }
      final events = state.events.where((e) => !done.contains(e)).toList();
      await _store.save(events);
      final now = DateTime.now().toUtc();
      for (final e in done) {
        e.appliedAt = now;
        if (e.localFile != null) await _store.dropFile(e.localFile!);
      }
      final keep = state.applied.where((e) => now.difference(e.appliedAt!) < const Duration(minutes: 10));
      state = state.copy(events: events, applied: [...keep, ...done], lastSync: DateTime.now());
      more = ready.length == 100 && done.isNotEmpty;
      if (!_synced.isClosed) _synced.add(null);
    } on ApiException catch (x) {
      offline = x.code == 'OFFLINE';
    } finally {
      if (ref.mounted) state = state.copy(sending: false, offline: offline, events: [...state.events]);
    }
    if (more && ref.mounted) unawaited(Future(flush)); // دفعة كاملة: الباقي بعدها
  }

  /// عند الخروج: لا يبقى على الجهاز ما يخص هذا الفني
  Future<void> clear() async {
    await _store.clearAll();
    state = const OutboxState(loaded: true);
  }

  /// نجح طلب آخر: الاتصال عاد
  void markOnline() {
    if (state.offline) state = state.copy(offline: false);
  }

  void markOffline() {
    if (!state.offline) state = state.copy(offline: true);
  }
}

final outboxProvider = NotifierProvider<Outbox, OutboxState>(Outbox.new);
