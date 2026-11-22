import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api.dart';

/// المستخدم الحالي (MeResource في العقد contracts/openapi/openapi.json)
class Me {
  Me(this.json);
  final Map<String, dynamic> json;

  String get fullName => json['full_name'] as String? ?? '';
  String? get phone => json['phone'] as String?;
  bool get needsOnboarding => json['needs_onboarding'] == true;
  List<Map<String, dynamic>> get organizations =>
      (json['organizations'] as List? ?? const []).cast<Map<String, dynamic>>();
}

/// حالة الدخول: null = غير مسجل. عند التشغيل تُستعاد الجلسة من رمز التحديث المحفوظ.
class AuthController extends AsyncNotifier<Me?> {
  Api get _api => ref.read(apiProvider);

  /// انتهت الجلسة دون أن يطلب العميل الخروج. يبقى حتى الدخول التالي.
  bool get sessionExpired => _expired;
  bool _expired = false;
  bool _signingOut = false;

  @override
  Future<Me?> build() async {
    final ended = _api.sessionEnded.listen((_) {
      if (_signingOut || state.value == null) return; // خروج مطلوب، أو زائر، أو عند التشغيل
      _expired = true;
      state = const AsyncData(null);
    });
    ref.onDispose(ended.cancel);
    final store = ref.read(tokenStoreProvider);
    if (await store.readRefresh() == null) return null;
    try {
      return Me(await _api.get('/me')); // 401 ← تجديد تلقائي في Api
    } on ApiException catch (e) {
      if (e.status != 401 && e.status != 403) rethrow; // لا اتصال: الجلسة باقية، والبداية تعرض إعادة المحاولة
      await store.clear();
      return null;
    }
  }

  /// M-03: يرسل الرمز. يعيد رمز التطوير إن وُجد (بيئة local فقط).
  Future<String?> requestCode(String phone) async {
    final data = await _api.post('/auth/otp/request', {'phone': phone}, auth: false);
    return data['debug_code'] as String?;
  }

  /// M-04: يتحقق ويحفظ الجلسة
  Future<Me> verify(String phone, String code) async {
    final data = await _api.post('/auth/otp/verify', {'phone': phone, 'code': code, 'client': 'customer_app'}, auth: false);
    await ref.read(tokenStoreProvider).save(data);
    final me = Me(data['user'] as Map<String, dynamic>);
    _expired = false;
    state = AsyncData(me);
    return me;
  }

  /// M-05: إكمال الملف (US-06)
  Future<void> completeOnboarding(Map<String, dynamic> body) async {
    state = AsyncData(Me(await _api.post('/me/onboarding', body)));
  }

  /// بعد تعديل الملف: الاستجابة هي المستخدم الجديد
  void apply(Map<String, dynamic> user) {
    if (state.value != null) state = AsyncData(Me(user));
  }

  /// بعد تغيير العضوية أو كلمة المرور. لا تمر بحالة التحميل، فلا تظهر شاشة البداية.
  Future<void> reload() async {
    try {
      apply(await _api.get('/me'));
    } on ApiException {
      // التغيير نجح في الخادم، ويظهر عند أول تحديث
    }
  }

  /// الخادم أنهى الجلسات (حذف الحساب، أو الخروج من كل الأجهزة)
  Future<void> endLocally() async {
    _expired = false;
    await ref.read(tokenStoreProvider).clear();
    state = const AsyncData(null);
  }

  Future<void> logout() async {
    _signingOut = true;
    _expired = false;
    try {
      await _api.post('/auth/logout', {});
    } on ApiException {
      // الجلسة منتهية أصلاً
    }
    await ref.read(tokenStoreProvider).clear();
    state = const AsyncData(null);
    _signingOut = false;
  }
}

final authProvider = AsyncNotifierProvider<AuthController, Me?>(AuthController.new);
