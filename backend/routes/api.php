<?php

use App\Modules\Catalog\Http\Controllers\Admin\CatalogSetupController;
use App\Modules\Catalog\Http\Controllers\Admin\EquipmentAdminController;
use App\Modules\Catalog\Http\Controllers\EquipmentController;
use App\Modules\Content\Http\Controllers\LeadController;
use App\Modules\Content\Http\Controllers\PublicContentController;
use App\Modules\Identity\Http\Controllers\Admin\StaffController;
use App\Modules\Identity\Http\Controllers\CustomerAuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Http\Controllers\SessionController;
use App\Modules\Identity\Http\Controllers\StaffAuthController;
use App\Modules\Parts\Http\Controllers\Admin\PartAdminController;
use App\Modules\Parts\Http\Controllers\Admin\PartImportController;
use App\Modules\Parts\Http\Controllers\PartController;
use App\Modules\Platform\Http\Controllers\Admin\AuditLogController;
use App\Modules\Platform\Http\Controllers\Admin\UploadController;
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
    });

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
    });
});
