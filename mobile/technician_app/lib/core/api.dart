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

/// نتيجة تجديد الجلسة: نجح، أو رفضه الخادم (تنتهي الجلسة)، أو تعذّر الوصول (الجلسة باقية)
enum _Refresh { ok, rejected, unavailable }

/// صفحة من قائمة: الصفوف وبقية الاستجابة
class ApiPage {
  ApiPage(this.rows, this.meta);
  final List<Map<String, dynamic>> rows;
  final Map<String, dynamic> meta;

  String? get nextCursor => meta['next_cursor'] as String?;
}

/// تخزين الرموز (04 §5): رمز الوصول في الذاكرة فقط، ورمز التحديث في Keychain/Keystore.
class TokenStore {
  TokenStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();
  final FlutterSecureStorage _storage;
  static const _refreshKey = 'dw_tech_refresh_token';
  static const _deviceKey = 'dw_tech_device_id';

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

  /// معرّف الجهاز المفعّل (FR-AUTH-03): يبقى بعد الخروج، فالدخول التالي لا يحتاج رمز تفعيل
  Future<String?> readDevice() => _storage.read(key: _deviceKey);

  Future<void> saveDevice(String id) => _storage.write(key: _deviceKey, value: id);

  Future<void> forgetDevice() => _storage.delete(key: _deviceKey);
}

final tokenStoreProvider = Provider<TokenStore>((ref) => TokenStore());

/// لغة الواجهة والطلبات: العربية افتراضياً (NFR-14)
class LocaleCode extends Notifier<String> {
  static const _storage = FlutterSecureStorage();
  static const _key = 'dw_tech_locale';
  bool _chosen = false;

  @override
  String build() {
    _restore();
    return 'ar';
  }

  /// الاختيار محفوظ على الجهاز (M-22). إن تعذّرت قراءته تبقى العربية.
  Future<void> _restore() async {
    try {
      final saved = await _storage.read(key: _key);
      if (ref.mounted && !_chosen && (saved == 'ar' || saved == 'en')) state = saved!;
    } catch (_) {
      // لا تخزين: العربية الافتراضية
    }
  }

  Future<void> set(String code) async {
    _chosen = true;
    state = code;
    try {
      await _storage.write(key: _key, value: code);
    } catch (_) {
      // لا تخزين: يسري الاختيار حتى إغلاق التطبيق
    }
  }
}

final localeCodeProvider = NotifierProvider<LocaleCode, String>(LocaleCode.new);

/// عميل الـ API: اللغة في كل طلب، ورمز الوصول، وتجديده تلقائياً مرة واحدة عند 401.
/// (العميل المولّد من contracts/openapi/openapi.json يحل محل هذه الطبقة الرقيقة في mobile/packages، 04 §13.)
final apiProvider = Provider<Api>((ref) => Api(ref.watch(tokenStoreProvider), () => ref.read(localeCodeProvider)));

class Api {
  Api(this.tokens, this.locale, {HttpClientAdapter? adapter}) {
    _dio = Dio(BaseOptions(baseUrl: apiUrl, connectTimeout: const Duration(seconds: 15), receiveTimeout: const Duration(seconds: 60)));
    if (adapter != null) _dio.httpClientAdapter = adapter;
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
        if (error.response?.statusCode == 401 && !retried && error.requestOptions.extra['auth'] != false) {
          final refreshed = await _refresh();
          if (refreshed == _Refresh.rejected) return handler.next(error);
          if (refreshed == _Refresh.unavailable) {
            // بلا استجابة ← OFFLINE، لا 401: انقطاع الشبكة ليس انتهاء جلسة
            return handler.next(DioException(requestOptions: error.requestOptions, type: DioExceptionType.connectionError));
          }
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
  Completer<_Refresh>? _refreshing;
  final _sessionEnded = StreamController<void>.broadcast();

  /// الخادم رفض التجديد: الجلسة انتهت أثناء الاستخدام
  Stream<void> get sessionEnded => _sessionEnded.stream;

  /// تجديد واحد للطلبات المتزامنة: الثاني ينتظر الأول، وإلا عدّه الخادم إعادة استخدام وأنهى الجلسات
  Future<_Refresh> _refresh() {
    final pending = _refreshing;
    if (pending != null) return pending.future;
    final done = _refreshing = Completer<_Refresh>();

    () async {
      var result = _Refresh.rejected;
      try {
        final refresh = await tokens.readRefresh();
        if (refresh != null) {
          final res = await _dio.post('/auth/token/refresh', data: {'refresh_token': refresh}, options: Options(extra: {'auth': false}));
          await tokens.save((res.data as Map<String, dynamic>)['data'] as Map<String, dynamic>);
          result = _Refresh.ok;
        }
      } on DioException catch (e) {
        // الرفض الصريح وحده ينهي الجلسة. لا شبكة أو مهلة أو 429 أو 5xx: الرمز يبقى
        if (const [400, 401, 403, 422].contains(e.response?.statusCode)) {
          await tokens.clear();
        } else {
          result = _Refresh.unavailable;
        }
      } catch (_) {
        await tokens.clear(); // رمز تالف في التخزين الآمن
      }
      _refreshing = null;
      if (result == _Refresh.rejected) _sessionEnded.add(null);
      done.complete(result);
    }();

    return done.future;
  }

  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body, {bool auth = true}) =>
      _call(() => _dio.post(path, data: body, options: Options(extra: {'auth': auth})));

  Future<Map<String, dynamic>> patch(String path, Map<String, dynamic> body) => _call(() => _dio.patch(path, data: body));

  Future<Map<String, dynamic>> put(String path, Map<String, dynamic> body) => _call(() => _dio.put(path, data: body));

  Future<Map<String, dynamic>> delete(String path) => _call(() => _dio.delete(path));

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _call(() => _dio.get(path, queryParameters: query));

  /// قائمة: data مصفوفة، وبقية الاستجابة (next_cursor، counts، unread_count) في meta
  Future<ApiPage> page(String path, {Map<String, dynamic>? query, bool auth = true}) async {
    final body = await _raw(() => _dio.get(path, queryParameters: query, options: Options(extra: {'auth': auth})));
    final rows = (body['data'] as List? ?? const []).cast<Map<String, dynamic>>();
    return ApiPage(rows, Map.of(body)..remove('data'));
  }

  /// طلب يعيد مصفوفة في data (مثل إرسال رسالة يعيد المحادثة)
  Future<List<Map<String, dynamic>>> postList(String path, Map<String, dynamic> body) async {
    final res = await _raw(() => _dio.post(path, data: body));
    return (res['data'] as List? ?? const []).cast<Map<String, dynamic>>();
  }

  /// رفع صورة العمل إلى /tech/uploads (خاصة، NFR-11)
  Future<Map<String, dynamic>> upload(String filePath, String fileName) => _call(() async => _dio.post('/tech/uploads',
      data: FormData.fromMap({'file': await MultipartFile.fromFile(filePath, filename: fileName)})));

  Future<Map<String, dynamic>> _call(Future<Response> Function() send) async {
    final data = await _raw(send);
    return data['data'] is Map<String, dynamic> ? data['data'] as Map<String, dynamic> : data;
  }

  Future<Map<String, dynamic>> _raw(Future<Response> Function() send) async {
    try {
      final res = await send();
      final data = res.data;
      return data is Map<String, dynamic> ? data : <String, dynamic>{};
    } on DioException catch (e) {
      final body = e.response?.data;
      final err = body is Map<String, dynamic> ? body['error'] as Map<String, dynamic>? : null;
      if (err == null) throw ApiException(e.response == null ? 'OFFLINE' : 'INTERNAL_ERROR', e.response?.statusCode ?? 0);
      throw ApiException(err['code'] as String, e.response!.statusCode!,
          details: (err['details'] as Map?)?.cast<String, dynamic>() ?? const {}, message: err['message'] as String?);
    }
  }
}
