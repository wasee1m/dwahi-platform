import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/strings.dart';
import '../auth/auth_controller.dart';
import '../auth/error_text.dart';

// مشترك شاشات S5: محدد الحساب (06 §2)، ومزوّدات القوائم، ومعالجة الأخطاء

const personalAccount = 'personal';

/// اختيار "شخصي / الشركة" طوال تشغيل التطبيق
class AccountChoice extends Notifier<String?> {
  @override
  String? build() => null;

  void set(String value) => state = value;
}

final accountChoiceProvider = NotifierProvider<AccountChoice, String?>(AccountChoice.new);

/// الحساب المحدد فعلاً: الاختيار إن بقيت العضوية، وإلا أول شركة، وإلا الشخصي
final selectedAccountProvider = Provider<String>((ref) {
  final orgs = ref.watch(authProvider).value?.organizations ?? const <Map<String, dynamic>>[];
  final chosen = ref.watch(accountChoiceProvider);
  if (chosen == personalAccount || orgs.any((o) => o['id'] == chosen)) return chosen!;
  return orgs.isEmpty ? personalAccount : orgs.first['id'] as String;
});

/// ما يُرسل عند الإنشاء: null = شخصي
String? organizationOf(String account) => account == personalAccount ? null : account;

/// حساب سجل من الـ API
String accountOf(Map<String, dynamic> record) => ((record['account'] as Map?)?['id'] as String?) ?? personalAccount;

/// كل ما يراه المستخدم، وكل شاشة تصفي بالحساب
final addressesProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>(
    (ref) async => (await ref.read(apiProvider).page('/me/addresses')).rows);

final fleetProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  final api = ref.read(apiProvider);
  final rows = <Map<String, dynamic>>[];
  String? cursor;
  do {
    final page = await api.page('/me/fleet', query: {'cursor': ?cursor});
    rows.addAll(page.rows);
    cursor = page.nextCursor;
  } while (cursor != null);
  return rows;
});

final modelsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>(
    (ref) async => (await ref.read(apiProvider).page('/public/models', auth: false)).rows);

class AccountPicker extends ConsumerWidget {
  const AccountPicker({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = S.of(context);
    final orgs = ref.watch(authProvider).value?.organizations ?? const <Map<String, dynamic>>[];
    if (orgs.isEmpty) return const SizedBox.shrink();
    final selected = ref.watch(selectedAccountProvider);
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: DropdownButtonFormField<String>(
        key: ValueKey(selected),
        initialValue: selected,
        isExpanded: true,
        decoration: InputDecoration(labelText: s.account),
        items: [
          for (final o in orgs) DropdownMenuItem(value: o['id'] as String, child: Text(o['name'] as String, overflow: TextOverflow.ellipsis)),
          DropdownMenuItem(value: personalAccount, child: Text(s.personal)),
        ],
        onChanged: (v) => ref.read(accountChoiceProvider.notifier).set(v!),
      ),
    );
  }
}

/// رسائل الحقول من VALIDATION_FAILED. غيرها قيم مفردة (previous، retry_after).
Map<String, String> fieldErrors(Object error) {
  if (error is! ApiException || error.code != 'VALIDATION_FAILED') return const {};
  return {
    for (final e in error.details.entries)
      if (e.value is List && (e.value as List).isNotEmpty) e.key: '${(e.value as List).first}',
  };
}

String failureText(Object error, S s) => fieldErrors(error).values.firstOrNull ?? errorText(error, s);

void toast(BuildContext context, String text) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));

/// ينفذ الإجراء ويعرض خطأه. يعيد true عند النجاح.
Future<bool> runAction(BuildContext context, Future<void> Function() action, {String? done}) async {
  final s = S.of(context);
  try {
    await action();
    if (done != null && context.mounted) toast(context, done);
    return true;
  } on ApiException catch (e) {
    if (context.mounted) toast(context, failureText(e, s));
    return false;
  }
}

/// تأكيد قبل إجراء لا رجعة فيه
Future<bool> askConfirm(BuildContext context, String question) async {
  final s = S.of(context);
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      content: Text(question),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(s.cancel)),
        FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(96, 48)), onPressed: () => Navigator.pop(ctx, true), child: Text(s.confirm)),
      ],
    ),
  );
  return ok == true;
}

/// حقل نصي بمسافة تحته وخطأ الخادم تحته
class Input extends StatelessWidget {
  const Input(this.controller, this.label, {super.key, this.error, this.hint, this.keyboard, this.latin = false, this.lines = 1, this.obscure = false, this.maxLength});

  final TextEditingController controller;
  final String label;
  final String? error;
  final String? hint;
  final TextInputType? keyboard;
  final bool latin;
  final int lines;
  final bool obscure;
  final int? maxLength;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(
          controller: controller,
          keyboardType: keyboard,
          maxLines: lines,
          obscureText: obscure,
          maxLength: maxLength,
          autocorrect: !latin && !obscure,
          textDirection: latin ? TextDirection.ltr : null,
          decoration: InputDecoration(labelText: label, errorText: error, errorMaxLines: 3, helperText: hint, helperMaxLines: 3),
        ),
      );
}

/// قائمة اختيار بنفس التباعد
class Choice extends StatelessWidget {
  const Choice({super.key, required this.label, required this.value, required this.options, required this.onChanged, this.error});

  final String label;
  final String? value;
  final Map<String, String> options;
  final ValueChanged<String?> onChanged;
  final String? error;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: DropdownButtonFormField<String>(
          // المفتاح يعيد البناء حين تتغير الخيارات أو القيمة من الخارج
          key: ValueKey('$label|$value|${options.length}'),
          initialValue: options.containsKey(value) ? value : null,
          isExpanded: true,
          decoration: InputDecoration(labelText: label, errorText: error),
          items: [for (final e in options.entries) DropdownMenuItem(value: e.key, child: Text(e.value, overflow: TextOverflow.ellipsis))],
          onChanged: onChanged,
        ),
      );
}
