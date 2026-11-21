<?php

use App\Modules\Catalog\Http\Controllers\Admin\CatalogSetupController;
use App\Modules\Catalog\Http\Controllers\Admin\EquipmentAdminController;
use App\Modules\Catalog\Http\Controllers\EquipmentController;
use App\Modules\Content\Http\Controllers\Admin\ContentAdminController;
use App\Modules\Content\Http\Controllers\Admin\LeadAdminController;
use App\Modules\Content\Http\Controllers\LeadController;
use App\Modules\Content\Http\Controllers\PublicContentController;
use App\Modules\Identity\Http\Controllers\Admin\CustomerLookupController;
use App\Modules\Identity\Http\Controllers\Admin\StaffController;
use App\Modules\Identity\Http\Controllers\CustomerAuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Http\Controllers\SessionController;
use App\Modules\Identity\Http\Controllers\StaffAuthController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Parts\Http\Controllers\Admin\PartAdminController;
use App\Modules\Parts\Http\Controllers\Admin\PartImportController;
use App\Modules\Parts\Http\Controllers\PartController;
use App\Modules\Platform\Http\Controllers\Admin\AuditLogController;
use App\Modules\Platform\Http\Controllers\Admin\BranchAdminController;
use App\Modules\Platform\Http\Controllers\Admin\SettingsController;
use App\Modules\Platform\Http\Controllers\Admin\UploadController;
use App\Modules\Platform\Http\Controllers\FileController;
use App\Modules\Quoting\Http\Controllers\Admin\QuotationAdminController;
use App\Modules\Quoting\Http\Controllers\Admin\RfqAdminController;
use App\Modules\Quoting\Http\Controllers\RfqController;
use Illuminate\Support\Facades\Route;

// /api/v1 (04 §4)
Route::prefix('v1')->group(function () {
    // ---------- عام: الموقع والتطبيق دون تسجيل دخول ----------
    Route::middleware('throttle:public')->group(function () {
        Route::get('equipment', [EquipmentController::class, 'index']);
        Route::get('equipment/facets', [EquipmentController::class, 'facets']);
        Route::get('equipment/{slug}', [EquipmentController::class, 'show']);

        Route::get('parts/search', [PartController::class, 'search']);
        Route::get('parts/{brand}/{number}', [PartController::class, 'show']);

        Route::prefix('public')->group(function () {
            Route::get('pages/{slug}', [PublicContentController::class, 'page']);
            Route::get('faqs', [PublicContentController::class, 'faqs']);
            Route::get('banners', [PublicContentController::class, 'banners']);
            Route::get('branches', [PublicContentController::class, 'branches']);
            Route::get('brands', [PublicContentController::class, 'brands']);
            Route::post('leads', [LeadController::class, 'store'])->middleware('throttle:leads');
        });
    });

    // ---------- المصادقة (FR-AUTH-01..05) ----------
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('otp/request', [CustomerAuthController::class, 'requestOtp']);
        Route::post('otp/verify', [CustomerAuthController::class, 'verifyOtp']);
        Route::post('password/login', [CustomerAuthController::class, 'passwordLogin']);
        Route::post('password/forgot', [PasswordController::class, 'forgot']);
        Route::post('password/reset', [PasswordController::class, 'reset']);
        Route::post('staff/login', [StaffAuthController::class, 'login']);
        Route::post('staff/2fa', [StaffAuthController::class, 'verifyTwoFactor']);
        Route::post('technician/login', [StaffAuthController::class, 'technicianLogin']);
        Route::post('token/refresh', [SessionController::class, 'refresh']);

        Route::middleware(['auth:sanctum', 'ensure.session'])->group(function () {
            Route::post('logout', [SessionController::class, 'logout']);
            Route::post('logout-all', [SessionController::class, 'logoutAll']);
        });
    });

    // ---------- المستخدم الحالي (FR-ACC-01، FR-AUTH-04) ----------
    Route::middleware(['auth:sanctum', 'ensure.session', 'throttle:account'])->prefix('me')->group(function () {
        Route::get('/', [MeController::class, 'show']);
        Route::patch('/', [MeController::class, 'update']);
        Route::post('onboarding', [MeController::class, 'onboarding']);
        Route::post('password', [MeController::class, 'setPassword']);
        Route::post('phone/request', [MeController::class, 'requestPhoneChange'])->middleware('throttle:auth');
        Route::post('phone/verify', [MeController::class, 'verifyPhoneChange'])->middleware('throttle:auth');
        Route::get('sessions', [SessionController::class, 'index']);
        Route::delete('sessions/{id}', [SessionController::class, 'destroy']);

        // الملفات الخاصة: صور القطع والسلندر وأمر الشراء ومرفقات الرسائل (NFR-11)
        Route::post('uploads', [FileController::class, 'upload'])->middleware('throttle:uploads');

        // طلبات العروض والعروض (C-07، C-08، C-09)
        Route::get('rfqs', [RfqController::class, 'index']);
        Route::post('rfqs', [RfqController::class, 'store']);
        Route::get('rfqs/{id}', [RfqController::class, 'show']);
        Route::post('rfqs/{id}/cancel', [RfqController::class, 'cancel']);
        Route::post('rfqs/{id}/renew', [RfqController::class, 'renew']);
        Route::get('rfqs/{id}/messages', [RfqController::class, 'messages']);
        Route::post('rfqs/{id}/messages', [RfqController::class, 'postMessage']);
        Route::get('quotations/{id}/pdf', [RfqController::class, 'pdf']);
        Route::post('quotations/{id}/{action}', [RfqController::class, 'respond'])->whereIn('action', ['accept', 'reject', 'revision', 'request-approval']);

        // مركز الإشعارات (C-19)
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/count', [NotificationController::class, 'count']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
    });

    // رابط موقّع للملف الخاص، صالح 15 دقيقة (04 §8.2)
    Route::get('files/{id}', [FileController::class, 'show'])->name('files.show')->middleware(['signed', 'throttle:public']);

    // ---------- لوحة الإدارة: جلسة إدارة (بعد TOTP) + صلاحية (01 §3.2) ----------
    Route::middleware(['auth:sanctum', 'ensure.session:admin', 'throttle:account'])->prefix('admin')->group(function () {
        Route::middleware('can:staff.manage')->group(function () {
            Route::get('staff', [StaffController::class, 'index']);
            Route::post('staff', [StaffController::class, 'store']);
            Route::patch('staff/{id}', [StaffController::class, 'update']);
            Route::post('staff/{id}/suspend', [StaffController::class, 'suspend']);
            Route::post('staff/{id}/activate', [StaffController::class, 'activate']);
            Route::post('staff/{id}/reset-2fa', [StaffController::class, 'resetTwoFactor']);
        });
        Route::middleware('can:devices.manage')->group(function () {
            Route::post('technicians/{id}/activation-code', [StaffController::class, 'issueActivationCode']);
            Route::delete('devices/{id}', [StaffController::class, 'revokeDevice']);
        });
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view');

        // إشعارات الموظف (N-01، N-03، N-17)
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/count', [NotificationController::class, 'count']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read']);

        // A-05 و A-06: طلبات العروض والعروض (مبيعات، منسق، مدير)
        Route::middleware('can:quotation.send')->group(function () {
            Route::get('rfqs', [RfqAdminController::class, 'index']);
            Route::get('rfqs/assignees', [RfqAdminController::class, 'assignees']);
            Route::get('rfqs/{id}', [RfqAdminController::class, 'show']);
            Route::post('rfqs/{id}/assign', [RfqAdminController::class, 'assign']);
            Route::post('rfqs/{id}/lose', [RfqAdminController::class, 'lose']);
            Route::post('rfqs/{id}/reopen', [RfqAdminController::class, 'reopen']);
            Route::post('rfqs/{id}/quotations', [RfqAdminController::class, 'draft']);
            Route::get('rfqs/{id}/messages', [RfqAdminController::class, 'messages']);
            Route::post('rfqs/{id}/messages', [RfqAdminController::class, 'postMessage']);
            Route::get('quote-catalog', [QuotationAdminController::class, 'catalog']);
            Route::get('quotations/{id}', [QuotationAdminController::class, 'show']);
            Route::put('quotations/{id}', [QuotationAdminController::class, 'update']);
            Route::delete('quotations/{id}', [QuotationAdminController::class, 'destroy']);
            Route::post('quotations/{id}/send', [QuotationAdminController::class, 'send']);
            Route::get('quotations/{id}/pdf', [QuotationAdminController::class, 'pdf']);
            Route::post('staff-uploads', [FileController::class, 'upload']); // مرفقات خاصة للموظف (رسائل، طلب نيابة)
        });
        Route::post('rfqs', [RfqAdminController::class, 'store'])->middleware('can:rfq.create_on_behalf');
        Route::middleware('can:customers.view')->group(function () {
            Route::get('customers/lookup', [CustomerLookupController::class, 'lookup']);
            Route::post('customers', [CustomerLookupController::class, 'store']);
        });

        // الملفات (صور الكتالوج، شعارات الماركات، ملفات المواصفات)
        Route::post('uploads', [UploadController::class, 'store'])->middleware('can:uploads.create');

        Route::middleware('can:catalog.read')->group(function () {
            Route::get('brands', [CatalogSetupController::class, 'brands']);
            Route::get('categories', [CatalogSetupController::class, 'categories']);
            Route::get('models', [CatalogSetupController::class, 'models']);
        });

        // A-02 و A-03: الكتالوج (مبيعات، مدير)
        Route::middleware('can:catalog.manage')->group(function () {
            Route::get('equipment', [EquipmentAdminController::class, 'index']);
            Route::post('equipment', [EquipmentAdminController::class, 'store']);
            Route::get('equipment/{id}', [EquipmentAdminController::class, 'show']);
            Route::put('equipment/{id}', [EquipmentAdminController::class, 'update']);
            Route::put('equipment/{id}/media', [EquipmentAdminController::class, 'media']);
            Route::post('equipment/{id}/status', [EquipmentAdminController::class, 'status']);

            Route::post('brands', [CatalogSetupController::class, 'saveBrand']);
            Route::put('brands/{id}', [CatalogSetupController::class, 'saveBrand']);
            Route::post('categories', [CatalogSetupController::class, 'saveCategory']);
            Route::put('categories/{id}', [CatalogSetupController::class, 'saveCategory']);
            Route::post('models', [CatalogSetupController::class, 'saveModel']);
            Route::put('models/{id}', [CatalogSetupController::class, 'saveModel']);
        });

        // A-04: القطع والمخزون (قطع، مدير)
        Route::middleware('can:parts.manage')->group(function () {
            Route::get('parts', [PartAdminController::class, 'index']);
            Route::post('parts', [PartAdminController::class, 'store']);
            Route::get('parts/import/template', [PartImportController::class, 'template']);
            Route::post('parts/import/preview', [PartImportController::class, 'preview']);
            Route::post('parts/import/commit', [PartImportController::class, 'commit']);
            Route::get('parts/{id}', [PartAdminController::class, 'show']);
            Route::put('parts/{id}', [PartAdminController::class, 'update']);
            Route::get('stock-locations', [PartAdminController::class, 'locations']);
            Route::post('stock/receipts', [PartAdminController::class, 'receive']);
            Route::post('stock/adjustments', [PartAdminController::class, 'adjust']);
        });

        // A-14: المحتوى (محرر، مدير)
        Route::middleware('can:content.manage')->group(function () {
            Route::get('pages', [ContentAdminController::class, 'pages']);
            Route::post('pages', [ContentAdminController::class, 'savePage']);
            Route::get('pages/{id}', [ContentAdminController::class, 'page']);
            Route::put('pages/{id}', [ContentAdminController::class, 'savePage']);
            Route::get('faqs', [ContentAdminController::class, 'faqs']);
            Route::post('faqs', [ContentAdminController::class, 'saveFaq']);
            Route::put('faqs/order', [ContentAdminController::class, 'orderFaqs']);
            Route::put('faqs/{id}', [ContentAdminController::class, 'saveFaq']);
            Route::get('banners', [ContentAdminController::class, 'banners']);
            Route::post('banners', [ContentAdminController::class, 'saveBanner']);
            Route::put('banners/{id}', [ContentAdminController::class, 'saveBanner']);
        });

        // A-15: الاستفسارات (مبيعات، منسق، مدير)
        Route::middleware('can:leads.manage')->group(function () {
            Route::get('leads', [LeadAdminController::class, 'index']);
            Route::get('leads/assignees', [LeadAdminController::class, 'assignees']);
            Route::get('leads/{id}', [LeadAdminController::class, 'show']);
            Route::patch('leads/{id}', [LeadAdminController::class, 'update']);
        });

        // A-18: الإعدادات والفروع (مدير)
        Route::middleware('can:settings.manage')->group(function () {
            Route::get('settings', [SettingsController::class, 'index']);
            Route::put('settings', [SettingsController::class, 'update']);
            Route::get('branches', [BranchAdminController::class, 'index']);
            Route::post('branches', [BranchAdminController::class, 'save']);
            Route::put('branches/{id}', [BranchAdminController::class, 'save']);
        });
    });
});
