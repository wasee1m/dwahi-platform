import '../../core/api.dart';
import '../../core/strings.dart';

/// رسائل الأخطاء برموز الـ API الثابتة، مع الوقت المتبقي والمحاولات (US-06)
String errorText(Object error, S s) {
  if (error is! ApiException) return s.genericError;
  final minutes = ((error.retryAfter ?? 60) / 60).ceil();
  return switch (error.code) {
    'OTP_INVALID' => s.attemptsLeft(error.attemptsLeft ?? 0),
    'OTP_LOCKED' => s.lockedFor(minutes),
    'OTP_THROTTLED' || 'RATE_LIMITED' => s.throttledFor(minutes),
    'OTP_EXPIRED' => s.expired,
    'INVALID_PHONE' => s.invalidPhone,
    'OFFLINE' => s.offline,
    _ => error.message ?? s.genericError,
  };
}
