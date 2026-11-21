<?php

namespace App\Modules\Platform\Http;

use App\Support\ApiProblem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * صيغة الخطأ الموحدة (04 §4.2): العميل يعتمد على code لا على message.
 */
final class ApiErrorRenderer
{
    private const MESSAGES = [
        'VALIDATION_FAILED' => ['ar' => 'بعض الحقول غير صحيحة.', 'en' => 'Some fields are not valid.'],
        'UNAUTHENTICATED' => ['ar' => 'سجّل الدخول للمتابعة.', 'en' => 'Please sign in to continue.'],
        'FORBIDDEN' => ['ar' => 'لا تملك صلاحية لهذا الإجراء.', 'en' => 'You do not have permission for this action.'],
        'NOT_FOUND' => ['ar' => 'غير موجود.', 'en' => 'Not found.'],
        'RATE_LIMITED' => ['ar' => 'طلبات كثيرة. حاول بعد قليل.', 'en' => 'Too many requests. Please try again shortly.'],
        'INTERNAL_ERROR' => ['ar' => 'حدث خطأ غير متوقع.', 'en' => 'Something went wrong.'],
        // المصادقة (FR-AUTH-01..05)
        'OTP_INVALID' => ['ar' => 'الرمز غير صحيح.', 'en' => 'The code is not correct.'],
        'OTP_EXPIRED' => ['ar' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.', 'en' => 'The code has expired. Request a new one.'],
        'OTP_LOCKED' => ['ar' => 'محاولات خاطئة كثيرة. حاول بعد قليل.', 'en' => 'Too many wrong attempts. Try again later.'],
        'OTP_THROTTLED' => ['ar' => 'طلبت رموزاً كثيرة. انتظر قبل طلب رمز جديد.', 'en' => 'Too many codes requested. Wait before asking again.'],
        'INVALID_CREDENTIALS' => ['ar' => 'بيانات الدخول غير صحيحة.', 'en' => 'The sign-in details are not correct.'],
        'TWO_FACTOR_INVALID' => ['ar' => 'رمز التحقق الثنائي غير صحيح.', 'en' => 'The two-factor code is not correct.'],
        'MFA_EXPIRED' => ['ar' => 'انتهت مهلة الدخول. ابدأ من جديد.', 'en' => 'The sign-in step expired. Start again.'],
        'DEVICE_NOT_REGISTERED' => ['ar' => 'هذا الجهاز غير مربوط بحسابك. اطلب رمز تفعيل من المنسق.', 'en' => 'This device is not linked to your account. Ask your coordinator for an activation code.'],
        'ACTIVATION_CODE_INVALID' => ['ar' => 'رمز التفعيل غير صحيح أو منتهٍ.', 'en' => 'The activation code is not valid or has expired.'],
        'ACCOUNT_SUSPENDED' => ['ar' => 'الحساب موقوف. تواصل معنا.', 'en' => 'This account is suspended. Please contact us.'],
        'REFRESH_INVALID' => ['ar' => 'انتهت الجلسة. سجّل الدخول مجدداً.', 'en' => 'Your session has ended. Please sign in again.'],
        'REFRESH_REUSED' => ['ar' => 'انتهت الجلسة لأسباب أمنية. سجّل الدخول مجدداً.', 'en' => 'Your session was ended for security reasons. Please sign in again.'],
        'REFRESH_IN_PROGRESS' => ['ar' => 'جارٍ تجديد الجلسة. أعد المحاولة.', 'en' => 'The session is being renewed. Try again.'],
        'SESSION_REVOKED' => ['ar' => 'انتهت الجلسة. سجّل الدخول مجدداً.', 'en' => 'Your session has ended. Please sign in again.'],
        'SESSION_IDLE' => ['ar' => 'انتهت الجلسة لعدم النشاط.', 'en' => 'Your session ended after inactivity.'],
        'WRONG_CLIENT' => ['ar' => 'لا يمكن استخدام هذه الجلسة هنا.', 'en' => 'This session cannot be used here.'],
        'PHONE_TAKEN' => ['ar' => 'هذا الرقم مسجل لحساب آخر.', 'en' => 'This number belongs to another account.'],
        'INVALID_PHONE' => ['ar' => 'اكتب رقم جوال صحيحاً.', 'en' => 'Enter a valid mobile number.'],
        'INVALID_TRANSITION' => ['ar' => 'لا يمكن الانتقال لهذه الحالة الآن.', 'en' => 'This status change is not allowed now.'],
        'STOCK_INSUFFICIENT' => ['ar' => 'الكمية تتجاوز المتاح في المخزن.', 'en' => 'The quantity exceeds what is in stock.'],
        'IMPORT_EXPIRED' => ['ar' => 'انتهت مهلة المعاينة. ارفع الملف من جديد.', 'en' => 'The preview expired. Upload the file again.'],
        'IMPORT_HAS_ERRORS' => ['ar' => 'صحّح أخطاء الملف قبل التطبيق.', 'en' => 'Fix the errors in the file before applying it.'],
        'ONBOARDING_REQUIRED' => ['ar' => 'أكمل ملفك أولاً.', 'en' => 'Complete your profile first.'],
        'QUOTATION_LOCKED' => ['ar' => 'لا يمكن تعديل عرض بعد إرساله. أصدر نسخة جديدة.', 'en' => 'A sent quote cannot be edited. Issue a new version.'],
        'QUOTATION_EXPIRED' => ['ar' => 'انتهت صلاحية العرض. اطلب تجديده.', 'en' => 'The quote has expired. Ask for a renewal.'],
        'LISTING_UNAVAILABLE' => ['ar' => 'المعدة محجوزة أو مباعة لعميل آخر.', 'en' => 'The machine is reserved or sold to another customer.'],
        'APPROVAL_REQUIRED' => ['ar' => 'قبول العروض لمالك الحساب أو عضو بصلاحية الاعتماد. أرسله للاعتماد.', 'en' => 'Only the account owner or a member with approval rights can accept. Send it for approval.'],
    ];

    public static function render(Throwable $e): JsonResponse
    {
        [$status, $code, $details] = match (true) {
            $e instanceof ApiProblem => [$e->status, $e->errorCode,
                ($e->retryAfter !== null ? ['retry_after' => $e->retryAfter] : []) + $e->details ?: null],
            $e instanceof ValidationException => [422, 'VALIDATION_FAILED', $e->errors()],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', null],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'FORBIDDEN', null],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', null],
            $e instanceof ThrottleRequestsException => [429, 'RATE_LIMITED', null],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getStatusCode() >= 500 ? 'INTERNAL_ERROR' : 'HTTP_'.$e->getStatusCode(), null],
            default => [500, 'INTERNAL_ERROR', null],
        };

        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $message = self::MESSAGES[$code][$locale] ?? self::MESSAGES['INTERNAL_ERROR'][$locale];
        if ($status === 500 && config('app.debug')) {
            $details = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
        if ($e instanceof ApiProblem && $e->retryAfter !== null) {
            $headers['Retry-After'] = (string) $e->retryAfter;
        }

        return new JsonResponse([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'trace_id' => Context::get('trace_id'),
            ], fn ($v) => $v !== null),
        ], $status, $headers);
    }
}
