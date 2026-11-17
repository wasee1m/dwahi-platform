import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// عنوان الـ API: من --dart-define=API_URL، وافتراضياً محاكي Android (10.0.2.2 هو جهاز التطوير).
const apiUrl = String.fromEnvironment('API_URL', defaultValue: 'http://10.0.2.2:8000/api/v1');

/// خطأ الـ API بالرمز الثابت (04 §4.2): الواجهة تعتمد على code لا على message.
class ApiException implements Exception {
  ApiException(this.code, this.status, {this.details = const {}, this.message});
  final String code;
  final int status;
  final Map<String, dynamic> details;
  final String? message;

  int? get retryAfter => details['retry_after'] as int?;
  int? get attemptsLeft => details['attempts_left'] as int?;

  @override
  String toString() => 'ApiException($code, $status)';
}

/// تخزين الرموز (04 §5): رمز الوصول في الذاكرة فقط، ورمز التحديث في Keychain/Keystore.
class TokenStore {
  TokenStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();
  final FlutterSecureStorage _storage;
  static const _refreshKey = 'dw_refresh_token';

  String? accessToken;

  Future<String?> readRefresh() => _storage.read(key: _refreshKey);

  Future<void> save(Map<String, dynamic> tokens) async {
    accessToken = tokens['access_token'] as String;
    await _storage.write(key: _refreshKey, value: tokens['refresh_token'] as String);
  }

  Future<void> clear() async {
    accessToken = null;
    await _storage.delete(key: _refreshKey);
  }
}

final tokenStoreProvider = Provider<TokenStore>((ref) => TokenStore());

/// لغة الواجهة والطلبات: العربية افتراضياً (NFR-14)
class LocaleCode extends Notifier<String> {
  @override
  String build() => 'ar';

  void set(String code) => state = code;
}

final localeCodeProvider = NotifierProvider<LocaleCode, String>(LocaleCode.new);

/// عميل الـ API: اللغة في كل طلب، ورمز الوصول، وتجديده تلقائياً مرة واحدة عند 401.
/// (العميل المولّد من contracts/openapi/openapi.json يحل محل هذه الطبقة الرقيقة في mobile/packages، 04 §13.)
final apiProvider = Provider<Api>((ref) => Api(ref.watch(tokenStoreProvider), () => ref.read(localeCodeProvider)));

class Api {
  Api(this.tokens, this.locale) {
    _dio = Dio(BaseOptions(baseUrl: apiUrl, connectTimeout: const Duration(seconds: 15), receiveTimeout: const Duration(seconds: 30)));
    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) {
        options.headers['Accept'] = 'application/json';
        options.headers['Accept-Language'] = locale();
        final token = tokens.accessToken;
        if (token != null && options.extra['auth'] != false) options.headers['Authorization'] = 'Bearer $token';
        handler.next(options);
      },
      onError: (error, handler) async {
        final retried = error.requestOptions.extra['retried'] == true;
        if (error.response?.statusCode == 401 && !retried && error.requestOptions.extra['auth'] != false && await _refresh()) {
          final options = error.requestOptions..extra['retried'] = true;
          options.headers['Authorization'] = 'Bearer ${tokens.accessToken}';
          try {
            return handler.resolve(await _dio.fetch(options));
          } on DioException catch (e) {
            return handler.next(e);
          }
        }
        handler.next(error);
      },
    ));
  }

  final TokenStore tokens;
  final String Function() locale;
  late final Dio _dio;
  Completer<bool>? _refreshing;

  /// تجديد واحد للطلبات المتزامنة: الثاني ينتظر الأول، وإلا عدّه الخادم إعادة استخدام وأنهى الجلسات
  Future<bool> _refresh() {
    final pending = _refreshing;
    if (pending != null) return pending.future;
    final done = _refreshing = Completer<bool>();

    () async {
      var ok = false;
      try {
        final refresh = await tokens.readRefresh();
        if (refresh != null) {
          final res = await _dio.post('/auth/token/refresh', data: {'refresh_token': refresh}, options: Options(extra: {'auth': false}));
          await tokens.save((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
          ok = true;
        }
      } catch (_) {
        await tokens.clear(); // التجديد فشل أو أُلغيت الجلسة: يعود المستخدم لشاشة الدخول
      }
      _refreshing = null;
      done.complete(ok);
    }();

    return done.future;
  }

  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body, {bool auth = true}) =>
      _call(() => _dio.post(path, data: body, options: Options(extra: {'auth': auth})));

  Future<Map<String, dynamic>> get(String path) => _call(() => _dio.get(path));

  Future<Map<String, dynamic>> _call(Future<Response> Function() send) async {
    try {
      final res = await send();
      final data = res.data;
      return data is Map<String, dynamic> ? (data['data'] as Map<String, dynamic>? ?? data) : <String, dynamic>{};
    } on DioException catch (e) {
      final body = e.response?.data;
      final err = body is Map<String, dynamic> ? body['error'] as Map<String, dynamic>? : null;
      if (err == null) throw ApiException(e.response == null ? 'OFFLINE' : 'INTERNAL_ERROR', e.response?.statusCode ?? 0);
      throw ApiException(err['code'] as String, e.response!.statusCode!,
          details: (err['details'] as Map?)?.cast<String, dynamic>() ?? const {}, message: err['message'] as String?);
    }
  }
}
