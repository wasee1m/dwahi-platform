import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:dwahi_ui/dwahi_ui.dart';

import '../../core/strings.dart';
import 'auth_controller.dart';
import 'error_text.dart';

/// M-02 الترحيب
class WelcomeScreen extends StatelessWidget {
  const WelcomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Spacer(),
              const Center(child: DwLogo(size: 96)),
              const SizedBox(height: 32),
              Text(s.welcomeTitle, textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 12),
              Text(s.welcomeText, textAlign: TextAlign.center, style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant)),
              const Spacer(),
              FilledButton(onPressed: () => context.go('/login'), child: Text(s.start)),
              const SizedBox(height: 8),
              TextButton(onPressed: () => context.go('/home'), child: Text(s.browseAsGuest)), // M-02
            ],
          ),
        ),
      ),
    );
  }
}

/// M-03 الدخول بالجوال
class PhoneScreen extends ConsumerStatefulWidget {
  const PhoneScreen({super.key});

  @override
  ConsumerState<PhoneScreen> createState() => _PhoneScreenState();
}

class _PhoneScreenState extends ConsumerState<PhoneScreen> {
  final _phone = TextEditingController();
  bool _busy = false;
  String? _error;

  Future<void> _submit() async {
    final s = S.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final debug = await ref.read(authProvider.notifier).requestCode(_phone.text.trim());
      if (mounted) context.push('/login/verify', extra: (phone: _phone.text.trim(), debug: debug));
    } catch (e) {
      setState(() => _error = errorText(e, s));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Scaffold(
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text(s.phoneTitle, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          Text(s.phoneIntro),
          const SizedBox(height: 24),
          Directionality(
            textDirection: TextDirection.ltr, // الأرقام من اليسار (NFR-21)
            child: TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              autofillHints: const [AutofillHints.telephoneNumber],
              autofocus: true,
              decoration: InputDecoration(prefixText: '+968  ', hintText: s.phoneHint, errorText: _error),
              onSubmitted: (_) => _submit(),
            ),
          ),
          const SizedBox(height: 24),
          FilledButton(onPressed: _busy ? null : _submit, child: Text(s.sendCode)),
        ],
      ),
    );
  }
}

/// M-04 التحقق OTP: 6 أرقام، عداد إعادة الإرسال، رسائل القفل
class OtpScreen extends ConsumerStatefulWidget {
  const OtpScreen({super.key, required this.phone, this.debugCode});
  final String phone;
  final String? debugCode;

  @override
  ConsumerState<OtpScreen> createState() => _OtpScreenState();
}

class _OtpScreenState extends ConsumerState<OtpScreen> {
  final _code = TextEditingController();
  bool _busy = false;
  String? _error;
  String? _debug;
  int _wait = 60;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _debug = widget.debugCode;
    _startTimer();
  }

  void _startTimer() {
    _timer?.cancel();
    setState(() => _wait = 60);
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_wait <= 1) t.cancel();
      if (mounted) setState(() => _wait--);
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _verify() async {
    final s = S.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final me = await ref.read(authProvider.notifier).verify(widget.phone, _code.text.trim());
      if (mounted) context.go(me.needsOnboarding ? '/onboarding' : '/home');
    } catch (e) {
      setState(() => _error = errorText(e, s));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    final s = S.of(context);
    try {
      _debug = await ref.read(authProvider.notifier).requestCode(widget.phone);
      _startTimer();
    } catch (e) {
      setState(() => _error = errorText(e, s));
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Scaffold(
      appBar: AppBar(),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text(s.codeTitle, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          Text(s.codeSentTo(widget.phone)),
          if (_debug != null) ...[
            const SizedBox(height: 12),
            Text('DEV: $_debug', style: const TextStyle(color: DwColors.night, fontWeight: FontWeight.w700)),
          ],
          const SizedBox(height: 24),
          Directionality(
            textDirection: TextDirection.ltr,
            child: TextField(
              controller: _code,
              keyboardType: TextInputType.number,
              autofillHints: const [AutofillHints.oneTimeCode],
              inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(6)],
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 28, letterSpacing: 12),
              autofocus: true,
              decoration: InputDecoration(errorText: _error, errorMaxLines: 3),
              onChanged: (v) => v.length == 6 ? _verify() : null,
            ),
          ),
          const SizedBox(height: 24),
          FilledButton(onPressed: _busy ? null : _verify, child: Text(s.verify)),
          const SizedBox(height: 12),
          TextButton(onPressed: _wait > 0 ? null : _resend, child: Text(_wait > 0 ? s.resendIn(_wait) : s.resend)),
        ],
      ),
    );
  }
}
