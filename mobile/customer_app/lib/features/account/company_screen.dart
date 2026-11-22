import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../auth/auth_controller.dart';
import '../catalog/catalog_screens.dart';
import 'account_common.dart';

// M-22 الشركة والأعضاء (C-03، FR-ACC-02، US-09)

final companiesProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>(
    (ref) async => (await ref.read(apiProvider).page('/me/organizations')).rows);
final invitationsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>(
    (ref) async => (await ref.read(apiProvider).page('/me/invitations')).rows);

/// يزيد مع كل إجراء على الأعضاء، فتُبنى عناصر الاختيار من جديد على القيمة المحفوظة فعلاً
class _Revision extends Notifier<int> {
  @override
  int build() => 0;

  void bump() => state++;
}

final _revisionProvider = NotifierProvider.autoDispose<_Revision, int>(_Revision.new);

class CompanyScreen extends ConsumerWidget {
  const CompanyScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final companies = ref.watch(companiesProvider);
    final invitations = ref.watch(invitationsProvider).value ?? const <Map<String, dynamic>>[];
    final myId = ref.watch(authProvider).value?.json['id'];

    /// كل تغيير في العضوية يغيّر /me، فمحدد الحساب يتبعه
    Future<void> changed() async {
      ref.read(_revisionProvider.notifier).bump();
      ref.invalidate(companiesProvider);
      ref.invalidate(invitationsProvider);
      ref.invalidate(fleetProvider);
      ref.invalidate(addressesProvider);
      await ref.read(authProvider.notifier).reload();
    }

    Future<void> answer(Map<String, dynamic> invitation, bool accept) async {
      await runAction(context, () async {
        final r = await ref.read(apiProvider).post('/me/invitations/${invitation['id']}/${accept ? 'accept' : 'decline'}', {});
        await changed();
        if (accept && r['id'] is String) ref.read(accountChoiceProvider.notifier).set(r['id'] as String);
      });
    }

    Future<void> form([Map<String, dynamic>? company]) async {
      final ok = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => _CompanyForm(company: company));
      if (ok == true) await changed();
    }

    return Scaffold(
      appBar: AppBar(title: Text(s.companyAndMembers)),
      body: companies.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => LoadError(onRetry: () => ref.invalidate(companiesProvider)),
        data: (rows) => ListView(padding: screenPadding(context), children: [
          for (final i in invitations)
            Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(s.invitedTo(i['company'] as String), style: Theme.of(context).textTheme.titleMedium),
              Text('${s.invitedBy(i['invited_by'] as String)} · ${s.roleName(i['role'] as String)}'),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(child: FilledButton(onPressed: () => answer(i, true), child: Text(s.accept))),
                const SizedBox(width: 8),
                TextButton(onPressed: () => answer(i, false), child: Text(s.decline)),
              ]),
            ]))),
          if (rows.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 24), child: Text(s.noCompany)),
          for (final c in rows) _CompanyCard(company: c, myId: myId, changed: changed, edit: () => form(c)),
          const SizedBox(height: 12),
          OutlinedButton.icon(onPressed: () => form(), icon: const Icon(Icons.add), label: Text(s.newCompany)),
        ]),
      ),
    );
  }
}

class _CompanyCard extends ConsumerWidget {
  const _CompanyCard({required this.company, required this.myId, required this.changed, required this.edit});

  final Map<String, dynamic> company;
  final Object? myId;
  final Future<void> Function() changed;
  final VoidCallback edit;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final t = Theme.of(context).textTheme;
    final manage = company['can_manage'] == true;
    final members = (company['members'] as List).cast<Map<String, dynamic>>();
    final pending = (company['invitations'] as List).cast<Map<String, dynamic>>();
    final base = '/me/organizations/${company['id']}';
    final lang = Localizations.localeOf(context).languageCode;
    final revision = ref.watch(_revisionProvider);

    // بعد الفشل أيضاً تُجلب البيانات من جديد، فلا تبقى القوائم على قيمة رفضها الخادم
    Future<void> act(Future<void> Function(Api api) action, {String? done}) async {
      final ok = await runAction(context, () => action(ref.read(apiProvider)), done: done);
      if (ok || context.mounted) await changed();
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Expanded(child: Text(company['name'] as String, style: t.titleMedium?.copyWith(fontWeight: FontWeight.w800))),
            if (manage) IconButton(tooltip: s.edit, icon: const Icon(Icons.edit_outlined), onPressed: edit),
          ]),
          if (company['cr_number'] != null) Text('${s.crNumber}: ${ltr('${company['cr_number']}')}', style: t.bodySmall),
          Text('${s.preapprovalShort}: ${formatMoney(company['preapproval_limit'] as Map<String, dynamic>?, lang)}', style: t.bodySmall),
          const Divider(),
          Text(s.members, style: t.titleSmall),
          for (final m in members) ...[
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text('${m['name']}${m['user_id'] == myId ? ' (${s.you})' : ''}'),
              subtitle: Text([
                s.roleName(m['role'] as String),
                if (m['can_approve_quotes'] == true) s.canApprove,
                if (m['phone'] != null) ltr(formatPhone(m['phone'] as String)),
              ].join(' · ')),
              trailing: manage || m['user_id'] == myId
                  ? IconButton(
                      tooltip: m['user_id'] == myId ? s.leave : s.removeMember,
                      icon: Icon(m['user_id'] == myId ? Icons.exit_to_app : Icons.person_remove_outlined),
                      onPressed: () async {
                        if (await askConfirm(context, m['user_id'] == myId ? s.leaveQuestion : s.removeMemberQuestion) && context.mounted) {
                          await act((api) => api.delete('$base/members/${m['user_id']}'));
                        }
                      },
                    )
                  : null,
            ),
            if (manage)
              Row(key: ValueKey('${m['user_id']}-$revision'), children: [
                Expanded(child: Choice(
                  label: s.role,
                  value: m['role'] as String,
                  options: {'member': s.roleName('member'), 'owner': s.roleName('owner')},
                  onChanged: (role) => act((api) => api.patch('$base/members/${m['user_id']}', {'role': role, 'can_approve_quotes': m['can_approve_quotes'] == true})),
                )),
                // المالك يعتمد دائماً
                if (m['role'] != 'owner')
                  Expanded(child: CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(s.canApprove, style: t.bodySmall),
                    value: m['can_approve_quotes'] == true,
                    onChanged: (v) => act((api) => api.patch('$base/members/${m['user_id']}', {'role': m['role'], 'can_approve_quotes': v})),
                  )),
              ]),
          ],
          if (manage) ...[
            if (pending.isNotEmpty) Text(s.pendingInvitations, style: t.titleSmall),
            for (final i in pending)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(ltr(formatPhone(i['phone'] as String? ?? ''))),
                subtitle: Text(s.roleName(i['role'] as String)),
                trailing: TextButton(onPressed: () => act((api) => api.delete('$base/invitations/${i['id']}')), child: Text(s.cancel)),
              ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              icon: const Icon(Icons.person_add_alt),
              label: Text(s.invite),
              onPressed: () async {
                final ok = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => _InviteForm(base: base));
                if (ok != true) return;
                await changed();
                if (context.mounted) toast(context, s.invited);
              },
            ),
          ],
        ]),
      ),
    );
  }
}

class _CompanyForm extends ConsumerStatefulWidget {
  const _CompanyForm({this.company});
  final Map<String, dynamic>? company;

  @override
  ConsumerState<_CompanyForm> createState() => _CompanyFormState();
}

class _CompanyFormState extends ConsumerState<_CompanyForm> {
  late final c = widget.company;
  late final name = TextEditingController(text: c?['name'] as String?);
  late final cr = TextEditingController(text: c?['cr_number'] as String?);
  late final vat = TextEditingController(text: c?['vat_number'] as String?);
  late final limit = TextEditingController(text: (c?['preapproval_limit'] as Map?)?['amount'] as String?);
  late String? governorate = c?['governorate'] as String?;
  Map<String, String> errors = {};
  bool busy = false;

  @override
  void dispose() {
    for (final x in [name, cr, vat, limit]) {
      x.dispose();
    }
    super.dispose();
  }

  Future<void> save() async {
    setState(() {
      busy = true;
      errors = {};
    });
    final api = ref.read(apiProvider);
    final body = {
      'name': name.text.trim(),
      'governorate': governorate,
      'cr_number': cr.text.trim().isEmpty ? null : cr.text.trim(),
      'vat_number': vat.text.trim().isEmpty ? null : vat.text.trim(),
      if (c != null) 'preapproval_limit': limit.text.trim().isEmpty ? '0' : limit.text.trim(),
    };
    try {
      if (c == null) {
        final r = await api.post('/me/organizations', body);
        if (r['id'] is String) ref.read(accountChoiceProvider.notifier).set(r['id'] as String);
      } else {
        await api.patch('/me/organizations/${c!['id']}', body);
      }
      if (mounted) Navigator.pop(context, true);
      return;
    } on ApiException catch (e) {
      if (mounted) setState(() => errors = fieldErrors(e).isEmpty ? {'name': failureText(e, S.of(context))} : fieldErrors(e));
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.viewInsetsOf(context).bottom + 24),
      child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        Input(name, s.companyName, error: errors['name']),
        Choice(
          label: s.governorate,
          value: governorate,
          error: errors['governorate'],
          options: {for (final g in S.governorates.keys) g: s.governorateName(g)},
          onChanged: (v) => setState(() => governorate = v),
        ),
        Input(cr, s.crNumber, error: errors['cr_number'], latin: true),
        Input(vat, s.vatNumber, error: errors['vat_number'], latin: true),
        if (c != null) Input(limit, s.preapproval, hint: s.preapprovalHint, error: errors['preapproval_limit'], latin: true, keyboard: const TextInputType.numberWithOptions(decimal: true)),
        FilledButton(onPressed: busy ? null : save, child: Text(s.save)),
      ])),
    );
  }
}

class _InviteForm extends ConsumerStatefulWidget {
  const _InviteForm({required this.base});
  final String base;

  @override
  ConsumerState<_InviteForm> createState() => _InviteFormState();
}

class _InviteFormState extends ConsumerState<_InviteForm> {
  final phone = TextEditingController();
  String role = 'member';
  bool approves = false;
  String? error;
  bool busy = false;

  @override
  void dispose() {
    phone.dispose();
    super.dispose();
  }

  Future<void> send() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await ref.read(apiProvider).post('${widget.base}/invitations', {'phone': phone.text.trim(), 'role': role, 'can_approve_quotes': approves});
      if (mounted) Navigator.pop(context, true);
      return;
    } on ApiException catch (e) {
      if (mounted) setState(() => error = failureText(e, S.of(context)));
    }
    if (mounted) setState(() => busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.viewInsetsOf(context).bottom + 24),
      child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
        Input(phone, s.memberPhone, error: error, latin: true, keyboard: TextInputType.phone),
        Choice(label: s.role, value: role, options: {'member': s.roleName('member'), 'owner': s.roleName('owner')}, onChanged: (v) => setState(() => role = v!)),
        if (role == 'member')
          CheckboxListTile(contentPadding: EdgeInsets.zero, value: approves, onChanged: (v) => setState(() => approves = v!), title: Text(s.canApprove)),
        FilledButton(onPressed: busy ? null : send, child: Text(s.send)),
      ])),
    );
  }
}
