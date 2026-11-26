import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/service_strings.dart';
import 'service_completion.dart';
import '../../core/strings.dart';
import '../account/account_common.dart';
import '../catalog/catalog_screens.dart';
import '../quotes/pickers.dart';

// S7: طلب صيانة جديد (M-15) وتبويب الصيانة وتفاصيله (M-16). التسجيل الصوتي وحفظ المسودة محلياً مع S8.

final serviceListProvider = FutureProvider.autoDispose.family<ApiPage, String>((ref, tab) => ref.read(apiProvider).page('/me/service-requests', query: {'tab': tab}));
final serviceProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, String>((ref, id) => ref.read(apiProvider).get('/me/service-requests/$id'));
final serviceMetaProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) => ref.read(apiProvider).get('/me/service-requests/meta'));

String _lang(BuildContext c) => Localizations.localeOf(c).languageCode;

String statusOf(S s, Map<String, dynamic> r, String lang) {
  final cs = r['customer_status'] as Map<String, dynamic>;
  final date = cs['date'] as String?;
  return s.customerStatus(cs['key'] as String, date: date == null ? null : formatDateTime(date, lang), reason: cs['reason'] as String?);
}

/// M-16 تبويب الصيانة
class ServiceLists extends ConsumerStatefulWidget {
  const ServiceLists({super.key});
  @override
  ConsumerState<ServiceLists> createState() => _ServiceListsState();
}

class _ServiceListsState extends ConsumerState<ServiceLists> {
  static const tabs = ['active', 'awaiting', 'done', 'closed'];
  String tab = 'active';

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final lang = _lang(context);
    final async = ref.watch(serviceListProvider(tab));
    final counts = (async.value?.meta['counts'] as Map?)?.cast<String, dynamic>() ?? const {};
    return RefreshIndicator(
      onRefresh: () => ref.refresh(serviceListProvider(tab).future),
      child: ListView(padding: const EdgeInsets.all(12), children: [
        SingleChildScrollView(scrollDirection: Axis.horizontal, child: Row(children: [
          for (final t in tabs)
            Padding(padding: const EdgeInsetsDirectional.only(end: 8), child: ChoiceChip(
              label: Text('${s.serviceTab(t)}${(counts[t] ?? 0) > 0 ? ' (${counts[t]})' : ''}'),
              selected: tab == t, onSelected: (_) => setState(() => tab = t))),
        ])),
        const SizedBox(height: 8),
        OutlinedButton.icon(onPressed: () => context.push('/service/new'), icon: const Icon(Icons.build_outlined), label: Text(s.newServiceRequest)),
        const SizedBox(height: 8),
        ...async.when(
          loading: () => [const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))],
          error: (_, _) => [LoadError(onRetry: () => ref.invalidate(serviceListProvider(tab)))],
          data: (page) => page.rows.isEmpty
              ? [Padding(padding: const EdgeInsets.all(32), child: Center(child: Text(s.noServiceRequests)))]
              : [
                  for (final r in page.rows)
                    Card(child: ListTile(
                      onTap: () => context.push('/service/${r['id']}'),
                      title: Text('${ltr(r['number'] as String)} · ${(r['equipment'] as Map?)?['name'] ?? s.component(r['component_type'] as String?)}'),
                      subtitle: Text(statusOf(s, r, lang)),
                      trailing: r['urgency'] == 'urgent' ? Text(s.urgencyName('urgent'), style: TextStyle(color: Theme.of(context).colorScheme.error)) : null,
                    )),
                ],
        ),
      ]),
    );
  }
}

/// M-15: معالج من أربع خطوات، والمرفقات تُرفع فور اختيارها
class NewServiceScreen extends ConsumerStatefulWidget {
  const NewServiceScreen({super.key, this.equipmentId});
  final String? equipmentId;
  @override
  ConsumerState<NewServiceScreen> createState() => _NewServiceScreenState();
}

class _NewServiceScreenState extends ConsumerState<NewServiceScreen> {
  int step = 0;
  String subject = 'fleet';
  String? machine;
  String component = 'hydraulic_cylinder';
  final brand = TextEditingController();
  final model = TextEditingController();
  final serial = TextEditingController();
  final details = TextEditingController();
  final problem = TextEditingController();
  final media = <({String id, String kind})>[];
  int uploading = 0;
  String urgency = 'normal';
  DateTime? preferred;
  bool ack = false;
  bool sending = false;
  Map<String, String> errors = const {};

  @override
  void initState() {
    super.initState();
    machine = widget.equipmentId;
    // "التالي" يتفعّل مع الكتابة، لا بعد مغادرة الحقل
    problem.addListener(_refresh);
    model.addListener(_refresh);
  }

  void _refresh() => setState(() {});

  @override
  void dispose() {
    for (final c in [brand, model, serial, details, problem]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _attach({bool video = false}) async {
    final s = S.of(context);
    String? path;
    String? name;
    if (video) {
      final x = await ImagePicker().pickVideo(source: ImageSource.camera, maxDuration: const Duration(minutes: 2));
      path = x?.path;
      name = x?.name;
    } else {
      final f = await pickAttachment(context);
      path = f?.path;
      name = f?.name;
    }
    if (path == null || name == null) return;
    setState(() => uploading++);
    try {
      final up = await ref.read(apiProvider).upload(path, name);
      final kind = (up['mime_type'] as String? ?? '').startsWith('video/') ? 'video' : 'photo';
      setState(() => media.add((id: up['id'] as String, kind: kind)));
    } on ApiException catch (e) {
      if (mounted) toast(context, failureText(e, s));
    } finally {
      if (mounted) setState(() => uploading--);
    }
  }

  Future<void> _send(String account) async {
    setState(() => sending = true);
    try {
      final r = await ref.read(apiProvider).post('/me/service-requests', {
        'organization_id': organizationOf(account),
        'customer_equipment_id': subject == 'fleet' ? machine : null,
        'new_equipment': subject == 'new' ? {'brand_text': brand.text.trim(), 'model_text': model.text.trim(), 'serial_number': serial.text.trim()} : null,
        'component_type': subject == 'component' ? component : null,
        'component_details': subject == 'component' && details.text.trim().isNotEmpty ? details.text.trim() : null,
        'problem_description': problem.text.trim(),
        'attachments': [for (final m in media) {'id': m.id, 'kind': m.kind}],
        'urgency': urgency,
        'preferred_date': preferred == null ? null : '${preferred!.year}-${preferred!.month.toString().padLeft(2, '0')}-${preferred!.day.toString().padLeft(2, '0')}',
        'fees_acknowledged': ack,
      });
      ref.invalidate(serviceListProvider);
      if (!mounted) return;
      toast(context, S.of(context).requestSent);
      context.go('/requests');
      context.push('/service/${r['id']}');
    } on ApiException catch (e) {
      setState(() {
        errors = fieldErrors(e);
        sending = false;
      });
      if (mounted) toast(context, failureText(e, S.of(context)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final lang = _lang(context);
    final account = ref.watch(selectedAccountProvider);
    final fleet = (ref.watch(fleetProvider).value ?? const []).where((e) => accountOf(e) == account).toList();
    final meta = ref.watch(serviceMetaProvider).value;
    if (subject == 'fleet' && fleet.isEmpty && ref.watch(fleetProvider).hasValue) subject = 'new';
    if (subject == 'fleet' && (machine == null || !fleet.any((e) => e['id'] == machine)) && fleet.isNotEmpty) machine = fleet.first['id'] as String;
    final subjectOk = subject == 'fleet' ? machine != null : subject == 'new' ? model.text.trim().isNotEmpty : true;

    final steps = [
      Step(title: Text(s.stepMachine), isActive: step >= 0, content: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const AccountPicker(),
        RadioGroup<String>(
          groupValue: subject,
          onChanged: (v) => setState(() => subject = v!),
          child: Column(children: [
            if (fleet.isNotEmpty) RadioListTile(value: 'fleet', title: Text(s.fromMyFleet), contentPadding: EdgeInsets.zero),
            RadioListTile(value: 'new', title: Text(s.otherMachine), contentPadding: EdgeInsets.zero),
            RadioListTile(value: 'component', title: Text(s.separateComponent), contentPadding: EdgeInsets.zero),
          ]),
        ),
        if (subject == 'fleet')
          Choice(label: s.myFleet, value: machine, options: {for (final e in fleet) e['id'] as String: e['name'] as String}, onChanged: (v) => setState(() => machine = v)),
        if (subject == 'new') ...[
          Input(brand, s.brandLabel, maxLength: 100),
          Input(model, s.modelLabel, maxLength: 100, error: errors['new_equipment.model_text']),
          Input(serial, s.serialOptional, latin: true, maxLength: 100),
        ],
        if (subject == 'component') ...[
          Choice(label: s.componentTypeLabel, value: component,
              options: {for (final c in (meta?['components'] as List? ?? const ['hydraulic_cylinder'])) c as String: s.component(c)},
              onChanged: (v) => setState(() => component = v ?? component)),
          Input(details, s.componentDetailsLabel, maxLength: 1000),
        ],
      ])),
      Step(title: Text(s.stepProblem), isActive: step >= 1, content: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Input(problem, s.problemLabel, lines: 4, maxLength: 4000, error: errors['problem_description']),
        Wrap(spacing: 8, children: [
          OutlinedButton.icon(onPressed: uploading > 0 ? null : () => _attach(), icon: const Icon(Icons.photo_camera_outlined), label: Text(s.addPhotoOrVideo)),
          OutlinedButton.icon(onPressed: uploading > 0 ? null : () => _attach(video: true), icon: const Icon(Icons.videocam_outlined), label: Text(s.recordVideo)),
        ]),
        if (media.isNotEmpty || uploading > 0) Text('${s.attachmentsCount}: ${media.length}${uploading > 0 ? ' · ${s.uploadingFiles}' : ''}'),
      ])),
      Step(title: Text(s.stepTiming), isActive: step >= 2, content: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(s.urgencyLabel, style: Theme.of(context).textTheme.titleSmall),
        RadioGroup<String>(
          groupValue: urgency,
          onChanged: (v) => setState(() => urgency = v!),
          child: Column(children: [
            for (final u in ['normal', 'urgent'])
              RadioListTile(value: u, contentPadding: EdgeInsets.zero, title: Text(s.urgencyName(u)),
                  subtitle: meta == null ? null : Text(s.replyWithin(u, ((meta['sla'] as Map)[u == 'urgent' ? 'urgent_hours' : 'normal_working_hours'] as num).toInt()))),
          ]),
        ),
        ListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(s.preferredDropoff),
          subtitle: Text(preferred == null ? s.chooseDate : ltr('${preferred!.year}-${preferred!.month}-${preferred!.day}')),
          trailing: const Icon(Icons.calendar_today_outlined),
          onTap: () async {
            final now = DateTime.now();
            final d = await showDatePicker(context: context, firstDate: now, lastDate: now.add(const Duration(days: 90)), initialDate: preferred ?? now);
            if (d != null) setState(() => preferred = d);
          },
        ),
        if (meta?['workshop'] != null) Text('${s.workshopLabel}: ${(meta!['workshop'] as Map)['name']}'),
      ])),
      Step(title: Text(s.stepReview), isActive: step >= 3, content: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(meta?['inspection_fee'] == null ? s.noInspectionFee : s.inspectionFee(formatMoney(meta!['inspection_fee'] as Map<String, dynamic>, lang)),
            style: const TextStyle(fontWeight: FontWeight.w700)),
        Text(s.feeRule),
        CheckboxListTile(
          contentPadding: EdgeInsets.zero, controlAffinity: ListTileControlAffinity.leading,
          value: ack, onChanged: (v) => setState(() => ack = v == true), title: Text(s.acknowledgeFees)),
      ])),
    ];

    final canNext = switch (step) {
      0 => subjectOk,
      1 => problem.text.trim().isNotEmpty && uploading == 0,
      2 => true,
      _ => ack && !sending,
    };

    return Scaffold(
      appBar: AppBar(title: Text(s.newServiceRequest)),
      body: Stepper(
        currentStep: step,
        steps: steps,
        onStepTapped: (i) => setState(() => step = i < step ? i : step),
        controlsBuilder: (context, d) => Padding(
          padding: const EdgeInsets.only(top: 12),
          child: Row(children: [
            // The theme's full-width minimum size has no bound inside a Row, so give the button a finite one
            FilledButton(
              style: FilledButton.styleFrom(minimumSize: const Size(120, 52)),
              onPressed: canNext ? () => step == 3 ? _send(account) : setState(() => step++) : null,
              child: Text(step == 3 ? s.sendRequest : s.nextStep),
            ),
            if (step > 0) TextButton(onPressed: () => setState(() => step--), child: Text(s.prevStep)),
          ]),
        ),
      ),
    );
  }
}

/// تفاصيل طلب الصيانة: الحالة بتسمية العميل، والتقدير، والاستلام وإيصاله، والموعد، والإلغاء
class ServiceDetailScreen extends ConsumerWidget {
  const ServiceDetailScreen({super.key, required this.id});
  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final lang = _lang(context);
    final async = ref.watch(serviceProvider(id));
    return Scaffold(
      appBar: AppBar(title: Text(async.value?['number'] as String? ?? '')),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(serviceProvider(id))),
        data: (r) {
          final t = Theme.of(context).textTheme;
          final estimate = r['estimate'] as Map<String, dynamic>?;
          final quote = estimate?['quotation'] as Map<String, dynamic>?;
          final intake = r['intake'] as Map<String, dynamic>?;
          final orders = (r['work_orders'] as List).cast<Map<String, dynamic>>();
          return RefreshIndicator(
            onRefresh: () => ref.refresh(serviceProvider(id).future),
            child: ListView(padding: screenPadding(context), children: [
              Text(statusOf(s, r, lang), style: t.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
              Text('${(r['equipment'] as Map?)?['name'] ?? s.component(r['component_type'] as String?)}${r['component_details'] != null ? ' · ${r['component_details']}' : ''}'),
              if (r['status'] == 'awaiting_approval' && quote != null)
                Card(child: ListTile(
                  title: Text('${quote['number'] ?? ''} · ${formatMoney(quote['total'] as Map<String, dynamic>?, lang)}'),
                  trailing: FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(88, 52)),
                      onPressed: () => context.push('/requests/${estimate!['rfq_id']}'), child: Text(s.reviewEstimate)),
                )),
              const SizedBox(height: 8),
              Text(s.problemLabel, style: t.titleSmall),
              Text(r['problem_description'] as String? ?? ''),
              const SizedBox(height: 8),
              if (intake != null)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('${s.receivedWith} ${ltr(intake['number'] as String)}'),
                  subtitle: intake['received_at'] == null ? null : Text(formatDateTime(intake['received_at'] as String, lang)),
                  trailing: intake['receipt_url'] == null ? null : TextButton(onPressed: () => openExternal(intake['receipt_url'] as String), child: Text(s.receivingReceipt)),
                )
              else if (r['status'] != 'cancelled' && r['status'] != 'declined')
                Text(s.notReceivedYet, style: t.bodySmall),
              for (final w in orders.where((w) => w['scheduled_start'] != null && w['status'] != 'cancelled'))
                Text(formatDateTime(w['scheduled_start'] as String, lang)),
              if (r['cancel_reason'] != null) Text(r['cancel_reason'] as String, style: t.bodySmall),
              if (r['completion'] is Map)
                ServiceCompletion(id: id, data: (r['completion'] as Map).cast<String, dynamic>(), onChanged: () => ref.invalidate(serviceProvider(id))),
              const SizedBox(height: 16),
              if (r['can_cancel'] == true)
                TextButton(
                  style: TextButton.styleFrom(foregroundColor: Theme.of(context).colorScheme.error),
                  onPressed: () async {
                    if (!await askConfirm(context, s.cancelRequestQ) || !context.mounted) return;
                    if (await runAction(context, () => ref.read(apiProvider).post('/me/service-requests/$id/cancel', {}), done: s.requestCancelled)) {
                      ref.invalidate(serviceProvider(id));
                      ref.invalidate(serviceListProvider);
                    }
                  },
                  child: Text(s.cancelRequest),
                ),
            ]),
          );
        },
      ),
    );
  }
}
