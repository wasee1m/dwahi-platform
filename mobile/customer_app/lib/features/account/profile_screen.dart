import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/account_strings.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/media.dart';
import '../../core/strings.dart';
import '../auth/auth_controller.dart';
import 'account_common.dart';

/// M-22 الملف والأمان (C-02): الاسم والبريد وتوثيقه، والجوال بـ OTP، وكلمة المرور
class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key});

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  late final Me? _me = ref.read(authProvider).value;
  late final _name = TextEditingController(text: _me?.fullName);
  late final _email = TextEditingController(text: _me?.json['email'] as String?);
  final _emailCode = TextEditingController();
  final _phone = TextEditingController();
  final _phoneCode = TextEditingController();
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _repeat = TextEditingController();
  Map<String, String> _errors = {};
  String? _emailDebug, _phoneDebug;
  bool _emailSent = false, _phoneSent = false, _busy = false;

  @override
  void dispose() {
    for (final c in [_name, _email, _emailCode, _phone, _phoneCode, _current, _password, _repeat]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _run(Future<void> Function(Api api) action, {String? done}) async {
    setState(() {
      _busy = true;
      _errors = {};
    });
    try {
      await action(ref.read(apiProvider));
      if (mounted && done != null) toast(context, done);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _errors = fieldErrors(e));
        toast(context, failureText(e, S.of(context)));
      }
    }
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final t = Theme.of(context).textTheme;
    final me = ref.watch(authProvider).value;
    final auth = ref.read(authProvider.notifier);
    final email = me?.json['email'] as String?;

    return Scaffold(
      appBar: AppBar(title: Text(s.profile)),
      body: ListView(padding: screenPadding(context), children: [
        Input(_name, s.fullName, error: _errors['full_name']),
        Input(_email, s.email, error: _errors['email'], latin: true, keyboard: TextInputType.emailAddress),
        FilledButton(
          onPressed: _busy ? null : () => _run((api) async {
            auth.apply(await api.patch('/me', {'full_name': _name.text.trim(), 'email': _email.text.trim().isEmpty ? null : _email.text.trim()}));
            _emailSent = false;
          }, done: s.saved),
          child: Text(s.save),
        ),
        if (email != null) ...[
          const SizedBox(height: 24),
          if (me?.json['email_verified'] == true)
            Text(s.emailVerified)
          else ...[
            Text(s.verifyEmail, style: t.titleMedium),
            Text(s.verifyEmailWhy, style: t.bodySmall),
            const SizedBox(height: 8),
            if (_emailDebug != null) Text('DEV: $_emailDebug'),
            if (_emailSent) Input(_emailCode, s.codeTitle, error: _errors['code'], latin: true, keyboard: TextInputType.number),
            OutlinedButton(
              onPressed: _busy ? null : () => _run((api) async {
                if (_emailSent) {
                  auth.apply(await api.post('/me/email/verify', {'code': _emailCode.text.trim()}));
                } else {
                  _emailDebug = (await api.post('/me/email/request', {}))['debug_code'] as String?;
                  _emailSent = true;
                }
              }),
              child: Text(_emailSent ? s.confirm : s.sendEmailCode),
            ),
          ],
        ],
        const Divider(height: 40),
        Text(s.changePhone, style: t.titleMedium),
        Text(ltr(formatPhone(me?.phone ?? ''))),
        const SizedBox(height: 8),
        Input(_phone, s.newPhone, error: _errors['phone'], latin: true, keyboard: TextInputType.phone),
        if (_phoneDebug != null) Text('DEV: $_phoneDebug'),
        if (_phoneSent) Input(_phoneCode, s.codeTitle, error: _errors['code'], latin: true, keyboard: TextInputType.number),
        OutlinedButton(
          onPressed: _busy ? null : () => _run((api) async {
            if (_phoneSent) {
              auth.apply(await api.post('/me/phone/verify', {'phone': _phone.text.trim(), 'code': _phoneCode.text.trim()}));
              _phoneSent = false;
              _phoneDebug = null;
              _phone.clear();
              _phoneCode.clear();
              if (context.mounted) toast(context, s.phoneChanged);
            } else {
              _phoneDebug = (await api.post('/me/phone/request', {'phone': _phone.text.trim()}))['debug_code'] as String?;
              _phoneSent = true;
            }
          }),
          child: Text(_phoneSent ? s.confirm : s.sendCode),
        ),
        const Divider(height: 40),
        Text(s.password, style: t.titleMedium),
        const SizedBox(height: 8),
        if (email == null)
          Text(s.passwordNeedsEmail)
        else ...[
          if (me?.json['has_password'] == true) Input(_current, s.currentPassword, error: _errors['current_password'], latin: true, obscure: true),
          Input(_password, s.newPassword, error: _errors['password'], latin: true, obscure: true),
          Input(_repeat, s.confirmPassword, latin: true, obscure: true),
          OutlinedButton(
            onPressed: _busy ? null : () => _run((api) async {
              await api.post('/me/password', {
                'password': _password.text,
                'password_confirmation': _repeat.text,
                'current_password': _current.text.isEmpty ? null : _current.text,
              });
              for (final c in [_current, _password, _repeat]) {
                c.clear();
              }
              await auth.reload();
            }, done: s.saved),
            child: Text(s.save),
          ),
        ],
      ]),
    );
  }
}
