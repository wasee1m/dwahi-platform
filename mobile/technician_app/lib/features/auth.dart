import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:dwahi_ui/dwahi_ui.dart';

import '../core/api.dart';
import '../core/outbox.dart';
import '../core/strings.dart';

const appVersion = '1.0.0';

/// الفني الحالي (/tech/me)
class Tech {
  Tech(this.json);
  final Map<String, dynamic> json;

  String get fullName => json['full_name'] as String? ?? '';
  String? get branch => json['branch'] as String?;
  List<String> get skills => (json['skills'] as List? ?? const []).map((e) => '$e').toList();
}

/// الدخول: null = غير مسجل. الفني يعمل دون اتصال، فالجلسة المحفوظة تكفي للتشغيل وآخر بيانات محفوظة تُعرض.
class AuthController extends AsyncNotifier<Tech?> {
  Api get _api => ref.read(apiProvider);
  bool _signingOut = false;

  @override
  Future<Tech?> build() async {
    final ended = _api.sessionEnded.listen((_) {
      if (_signingOut || state.value == null) return;
      state = const AsyncData(null);
    });
    ref.onDispose(ended.cancel);
    final store = ref.read(tokenStoreProvider);
    String? refresh;
    try {
      refresh = await store.readRefresh();
    } catch (_) {
      return null;
    }
    if (refresh == null) return null;
    final cache = ref.read(outboxStoreProvider);
    try {
      final me = await _api.get('/tech/me'); // 401 ← تجديد تلقائي
      await cache.writeCache('me', me);
      return Tech(me);
    } on ApiException catch (e) {
      if (e.status == 401 || e.status == 403) {
        await store.clear();
        return null;
      }
      final saved = await cache.readCache('me'); // لا اتصال: يتابع العمل من الجهاز
      return saved == null ? null : Tech(saved);
    }
  }

  /// T-01: بجهاز مفعّل، أو برمز تفعيل لمرة واحدة يربط هذا الجهاز (FR-AUTH-03)
  Future<void> login(String login, String password, {String? activationCode}) async {
    final store = ref.read(tokenStoreProvider);
    String? device;
    try {
      device = await store.readDevice();
    } catch (_) {
      device = null;
    }
    final data = await _api.post('/auth/technician/login', {
      'login': login.trim(), 'password': password,
      'platform': defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android', 'app_version': appVersion,
      if (activationCode != null && activationCode.trim().isNotEmpty) 'activation_code': activationCode.trim() else 'device_id': device,
    }, auth: false);
    await store.save(data);
    await store.saveDevice(data['device_id'] as String);
    final me = await _api.get('/tech/me');
    await ref.read(outboxStoreProvider).writeCache('me', me);
    state = AsyncData(Tech(me));
  }

  /// T-12: الصندوق والنسخ المحفوظة تُحذف، ومعرّف الجهاز يبقى
  Future<void> logout() async {
    _signingOut = true;
    try {
      await _api.post('/auth/logout', {});
    } catch (_) {
      // دون اتصال: تنتهي الجلسة على الخادم بانتهاء صلاحيتها
    }
    await ref.read(tokenStoreProvider).clear();
    await ref.read(outboxProvider.notifier).clear();
    _signingOut = false;
    state = const AsyncData(null);
  }
}

final authProvider = AsyncNotifierProvider<AuthController, Tech?>(AuthController.new);

/// هل على الجهاز معرّف مفعّل؟ (يخفي حقل رمز التفعيل)
final hasDeviceProvider = FutureProvider<bool>((ref) async {
  try {
    return await ref.read(tokenStoreProvider).readDevice() != null;
  } catch (_) {
    return false;
  }
});

/// T-01
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _login = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  bool _newCode = false;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    for (final c in [_login, _password, _code]) {
      c.addListener(() => setState(() {}));
    }
  }

  @override
  void dispose() {
    _login.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit(bool needCode) async {
    final s = S.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(authProvider.notifier).login(_login.text, _password.text, activationCode: needCode ? _code.text : null);
    } on ApiException catch (e) {
      setState(() {
        _error = switch (e.code) {
          'INVALID_CREDENTIALS' => s.badCredentials,
          'DEVICE_NOT_REGISTERED' => s.deviceNotRegistered,
          'ACTIVATION_CODE_INVALID' => s.codeInvalid,
          'OFFLINE' => s.offline,
          _ => e.message ?? s.genericError,
        };
        if (e.code == 'DEVICE_NOT_REGISTERED') _newCode = true;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final bound = ref.watch(hasDeviceProvider).value ?? false;
    final needCode = !bound || _newCode;
    final ready = _login.text.trim().isNotEmpty && _password.text.isNotEmpty && (!needCode || _code.text.trim().length >= 6);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Center(child: DwLogo(size: 96)),
                  const SizedBox(height: 16),
                  Text(s.loginTitle, textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 24),
                  TextField(key: const Key('login'), controller: _login, keyboardType: TextInputType.emailAddress, textDirection: TextDirection.ltr,
                      decoration: InputDecoration(labelText: s.loginField)),
                  const SizedBox(height: 12),
                  TextField(key: const Key('password'), controller: _password, obscureText: true, decoration: InputDecoration(labelText: s.password)),
                  const SizedBox(height: 12),
                  if (needCode)
                    TextField(key: const Key('code'), controller: _code, textCapitalization: TextCapitalization.characters, textDirection: TextDirection.ltr,
                        decoration: InputDecoration(labelText: s.activationCode, helperText: s.activationHint))
                  else
                    Row(children: [
                      const Icon(Icons.verified_outlined, size: 18),
                      const SizedBox(width: 6),
                      Expanded(child: Text(s.deviceBound)),
                      TextButton(onPressed: () => setState(() => _newCode = true), child: Text(s.useAnotherCode)),
                    ]),
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                  ],
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: ready && !_busy ? () => _submit(needCode) : null,
                    child: _busy ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(s.signIn),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
