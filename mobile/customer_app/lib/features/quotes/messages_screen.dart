import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/api.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../auth/error_text.dart';
import 'pickers.dart';
import 'quote_screens.dart';

// M-20 المحادثة (FR-MSG-01، FR-MSG-02، US-21): رسائل الطلب، وإرفاق صورة، وسحب للتحديث، وواتساب في الترويسة

final threadProvider = FutureProvider.autoDispose.family<List<Map<String, dynamic>>, String>(
    (ref, rfqId) async => (await ref.read(apiProvider).page('/me/rfqs/$rfqId/messages')).rows);

/// معرّف UUID v4 يولده الجهاز، فإعادة الإرسال بعد انقطاع لا تكرر الرسالة
String newMessageId() {
  final r = Random.secure();
  final b = List<int>.generate(16, (_) => r.nextInt(256));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  final h = b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
}

class MessagesScreen extends ConsumerStatefulWidget {
  const MessagesScreen({super.key, required this.rfqId});
  final String rfqId;
  @override
  ConsumerState<MessagesScreen> createState() => _MessagesScreenState();
}

class _MessagesScreenState extends ConsumerState<MessagesScreen> {
  final text = TextEditingController();
  final attachments = <String>[];
  bool busy = false;
  String? pendingId;

  @override
  void dispose() {
    text.dispose();
    super.dispose();
  }

  Future<void> _attach() async {
    final f = await pickAttachment(context);
    if (f == null) return;
    setState(() => busy = true);
    try {
      final up = await ref.read(apiProvider).upload(f.path, f.name);
      setState(() => attachments.add(up['id'] as String));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message ?? errorText(e, S.of(context)))));
    }
    if (mounted) setState(() => busy = false);
  }

  Future<void> _send() async {
    if (text.text.trim().isEmpty && attachments.isEmpty) return;
    pendingId ??= newMessageId(); // نفس المعرّف عند إعادة المحاولة بعد فشل
    setState(() => busy = true);
    try {
      await ref.read(apiProvider).postList('/me/rfqs/${widget.rfqId}/messages', {'id': pendingId, 'body': text.text.trim(), 'attachment_ids': attachments});
      text.clear();
      attachments.clear();
      pendingId = null;
      ref.invalidate(threadProvider(widget.rfqId));
      ref.invalidate(rfqDetailProvider(widget.rfqId));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message ?? errorText(e, S.of(context)))));
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final t = Theme.of(context);
    final rfq = ref.watch(rfqDetailProvider(widget.rfqId)).value;
    final branch = rfq?['branch'] as Map<String, dynamic>?;
    final isParts = rfq != null && rfq['type'] == 'parts';
    final wa = branch == null ? null : (isParts ? branch['whatsapp_parts'] : branch['whatsapp_sales']) as String?;
    final thread = ref.watch(threadProvider(widget.rfqId));
    final when = DateFormat.yMMMd(Localizations.localeOf(context).languageCode).add_Hm();

    return Scaffold(
      appBar: AppBar(
        title: Text(s.messages),
        actions: [
          if (wa != null) IconButton(tooltip: s.whatsapp, icon: const Icon(Icons.chat_outlined), onPressed: () => openWhatsApp(wa, s.whatsappAbout(rfq?['number'] as String? ?? ''))),
        ],
      ),
      body: Column(children: [
        Expanded(child: RefreshIndicator(
          onRefresh: () => ref.refresh(threadProvider(widget.rfqId).future),
          child: thread.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (_, _) => ListView(children: [Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(s.genericError)))]),
            data: (rows) => rows.isEmpty
                ? ListView(children: [Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(s.noMessages)))])
                : ListView(padding: const EdgeInsets.all(12), children: [
                    for (final m in rows)
                      Align(
                        alignment: m['mine'] == true ? AlignmentDirectional.centerStart : AlignmentDirectional.centerEnd,
                        child: Container(
                          constraints: const BoxConstraints(maxWidth: 300),
                          margin: const EdgeInsets.symmetric(vertical: 4),
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: m['mine'] == true ? t.colorScheme.surface : t.colorScheme.secondary,
                            borderRadius: BorderRadius.circular(6),
                            border: Border.all(color: t.colorScheme.outline),
                          ),
                          child: DefaultTextStyle.merge(
                            style: TextStyle(color: m['mine'] == true ? t.colorScheme.onSurface : t.colorScheme.onSecondary),
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text('${(m['sender'] as Map)['is_staff'] == true ? s.team : (m['sender'] as Map)['name']} · ${when.format(DateTime.parse(m['at'] as String).toLocal())}',
                                  style: const TextStyle(fontSize: 11)),
                              if ((m['body'] as String?)?.isNotEmpty == true) Padding(padding: const EdgeInsets.only(top: 4), child: Text(m['body'] as String)),
                              for (final a in (m['attachments'] as List).cast<Map<String, dynamic>>())
                                if (a['url'] != null)
                                  Padding(padding: const EdgeInsets.only(top: 6), child: (a['mime_type'] as String).startsWith('image/')
                                      ? GestureDetector(onTap: () => openExternal(a['url'] as String), child: ClipRRect(borderRadius: BorderRadius.circular(4), child: NetImage(a['url'] as String, height: 160)))
                                      : TextButton.icon(onPressed: () => openExternal(a['url'] as String), icon: const Icon(Icons.attach_file), label: Text(a['name'] as String? ?? ''))),
                            ]),
                          ),
                        ),
                      ),
                  ]),
          ),
        )),
        SafeArea(child: Padding(padding: const EdgeInsets.all(8), child: Row(children: [
          IconButton(onPressed: busy ? null : _attach, icon: Badge(isLabelVisible: attachments.isNotEmpty, label: Text('${attachments.length}'), child: const Icon(Icons.add_photo_alternate_outlined)), tooltip: s.takePhoto),
          Expanded(child: TextField(controller: text, minLines: 1, maxLines: 4, decoration: InputDecoration(hintText: s.messageHint))),
          IconButton(onPressed: busy ? null : _send, icon: const Icon(Icons.send), tooltip: s.send),
        ]))),
      ]),
    );
  }
}
