<?php

use App\Modules\Accounts\Http\Controllers\AddressController;
use App\Modules\Accounts\Http\Controllers\Admin\CustomerAdminController;
use App\Modules\Accounts\Http\Controllers\FleetController;
use App\Modules\Accounts\Http\Controllers\OrganizationController;
use App\Modules\Accounts\Http\Controllers\PrivacyController;
use App\Modules\Accounts\Http\Middleware\EnsureCustomer;
use App\Modules\Billing\Http\Controllers\Admin\FinanceController;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Catalog\Http\Controllers\Admin\CatalogSetupController;
use App\Modules\Catalog\Http\Controllers\Admin\EquipmentAdminController;
use App\Modules\Catalog\Http\Controllers\EquipmentController;
use App\Modules\Catalog\Http\Controllers\ModelLookupController;
use App\Modules\Content\Http\Controllers\Admin\ContentAdminController;
use App\Modules\Content\Http\Controllers\Admin\LeadAdminController;
use App\Modules\Content\Http\Controllers\LeadController;
use App\Modules\Content\Http\Controllers\PublicContentController;
use App\Modules\Identity\Http\Controllers\Admin\CustomerLookupController;
use App\Modules\Identity\Http\Controllers\Admin\StaffController;
use App\Modules\Identity\Http\Controllers\CustomerAuthController;
use App\Modules\Identity\Http\Controllers\EmailController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Http\Controllers\SessionController;
use App\Modules\Identity\Http\Controllers\StaffAuthController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\PreferenceController;
use App\Modules\Orders\Http\Controllers\Admin\OrderAdminController;
use App\Modules\Orders\Http\Controllers\CartController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Parts\Http\Controllers\Admin\PartAdminController;
use App\Modules\Parts\Http\Controllers\Admin\PartImportController;
use App\Modules\Parts\Http\Controllers\CompatiblePartsController;
use App\Modules\Parts\Http\Controllers\PartController;
use App\Modules\Platform\Http\Controllers\Admin\AuditLogController;
use App\Modules\Platform\Http\Controllers\Admin\BranchAdminController;
use App\Modules\Platform\Http\Controllers\Admin\SettingsController;
use App\Modules\Platform\Http\Controllers\Admin\UploadController;
use App\Modules\Platform\Http\Controllers\FileController;
use App\Modules\Quoting\Http\Controllers\Admin\QuotationAdminController;
use App\Modules\Quoting\Http\Controllers\Admin\RfqAdminController;
use App\Modules\Quoting\Http\Controllers\RfqController;
use App\Modules\Service\Http\Controllers\Admin\ServiceAdminController;
use App\Modules\Service\Http\Controllers\Admin\WorkOrderAdminController;
use App\Modules\Service\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

// /api/v1 (04 §4)
Route::prefix('v1')->group(function () {
    // ---------- عام: الموقع والتطبيق دون تسجيل دخول ----------
    Route::middleware('throttle:public')->group(function () {
        Route::get('equipment', [EquipmentController::class, 'index']);
        Route::get('equipment/facets', [EquipmentController::class, 'facets']);
        Route::get('equipment/{slug}', [EquipmentController::class, 'show']);

        Route::get('parts/search', [PartController::class, 'search']);
        Route::get('parts/compatible', [CompatiblePartsController::class, 'index']);
        Route::get('parts/{brand}/{number}', [PartController::class, 'show']);

        Route::prefix('public')->group(function () {
            Route::get('pages/{slug}', [PublicContentController::class, 'page']);
            Route::get('faqs', [PublicContentController::class, 'faqs']);
            Route::get('banners', [PublicContentController::class, 'banners']);
            Route::get('branches', [PublicContentController::class, 'branches']);
            Route::get('brands', [PublicContentController::class, 'brands']);
            Route::get('models', [ModelLookupController::class, 'index']);
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
        Route::post('email/request', [EmailController::class, 'request'])->middleware('throttle:auth');
        Route::post('email/verify', [EmailController::class, 'verify'])->middleware('throttle:auth');
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

        // الحساب: الشركة (C-03)، والعناوين (C-04)، والأسطول (C-05، C-06)
        Route::middleware(EnsureCustomer::class)->group(function () {
            Route::get('organizations', [OrganizationController::class, 'index']);
            Route::post('organizations', [OrganizationController::class, 'store']);
            Route::get('organizations/{id}', [OrganizationController::class, 'show']);
            Route::patch('organizations/{id}', [OrganizationController::class, 'update']);
            Route::post('organizations/{id}/invitations', [OrganizationController::class, 'invite'])->middleware('throttle:invitations');
            Route::delete('organizations/{id}/invitations/{invitationId}', [OrganizationController::class, 'revokeInvitation']);
            Route::patch('organizations/{id}/members/{userId}', [OrganizationController::class, 'updateMember']);
            Route::delete('organizations/{id}/members/{userId}', [OrganizationController::class, 'removeMember']);
            Route::get('invitations', [OrganizationController::class, 'myInvitations']);
            Route::post('invitations/{id}/accept', [OrganizationController::class, 'accept']);
            Route::post('invitations/{id}/decline', [OrganizationController::class, 'decline']);

            Route::get('addresses', [AddressController::class, 'index']);
            Route::post('addresses', [AddressController::class, 'store']);
            Route::patch('addresses/{id}', [AddressController::class, 'update']);
            Route::delete('addresses/{id}', [AddressController::class, 'archive']);

            Route::get('fleet', [FleetController::class, 'index']);
            Route::post('fleet', [FleetController::class, 'store']);
            Route::get('fleet/{id}', [FleetController::class, 'show']);
            Route::patch('fleet/{id}', [FleetController::class, 'update']);
            Route::delete('fleet/{id}', [FleetController::class, 'archive']);
            Route::post('fleet/{id}/hour-readings', [FleetController::class, 'addReading']);
            Route::get('fleet/{id}/history', [FleetController::class, 'history']);

            // السلة وإتمام الطلب (C-10، C-11، M-11)، والطلبات (C-13، C-14، M-19)، والفواتير (C-18، M-23)
            Route::get('cart', [CartController::class, 'show']);
            Route::put('cart/items/{partId}', [CartController::class, 'setItem']);
            Route::delete('cart/items/{partId}', [CartController::class, 'removeItem']);
            Route::post('cart/acknowledge', [CartController::class, 'acknowledge']);
            Route::get('checkout', [CartController::class, 'checkout']);
            Route::post('orders', [CartController::class, 'place'])->middleware('throttle:orders');
            Route::get('orders', [OrderController::class, 'index']);
            Route::get('orders/{id}', [OrderController::class, 'show']);
            Route::post('orders/{id}/cancel', [OrderController::class, 'cancel']);
            Route::post('orders/{id}/returns', [OrderController::class, 'requestReturn'])->middleware('throttle:orders');
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::get('invoices/{id}/pdf', [InvoiceController::class, 'pdf']);

            // طلبات الصيانة (C-15، C-16، M-15، M-16)
            Route::get('service-requests/meta', [ServiceController::class, 'meta']);
            Route::get('service-requests', [ServiceController::class, 'index']);
            Route::post('service-requests', [ServiceController::class, 'store'])->middleware('throttle:orders');
            Route::get('service-requests/{id}', [ServiceController::class, 'show']);
            Route::post('service-requests/{id}/cancel', [ServiceController::class, 'cancel']);
        });

        // C-20: حقوق البيانات لا تنتظر إكمال الملف
        Route::middleware(EnsureCustomer::class.':any')->group(function () {
            Route::get('notification-preferences', [PreferenceController::class, 'show']);
            Route::put('notification-preferences', [PreferenceController::class, 'update']);
            Route::put('marketing-consent', [PreferenceController::class, 'marketing']);
            Route::get('data-export', [PrivacyController::class, 'exportStatus']);
            Route::post('data-export', [PrivacyController::class, 'requestExport'])->middleware('throttle:exports');
            Route::get('deletion', [PrivacyController::class, 'deletion']);
            Route::post('deletion/request', [PrivacyController::class, 'deletionCode'])->middleware('throttle:auth');
            Route::post('deletion', [PrivacyController::class, 'delete'])->middleware('throttle:auth');
        });

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
        });
        // مرفقات خاصة للموظف: الرسائل، والطلب نيابة، وإثبات التسليم، وإيصال التحويل
        Route::post('staff-uploads', [FileController::class, 'upload'])->middleware(['can:staff.uploads', 'throttle:uploads']);

        // A-07: طلبات القطع والإرجاع (قطع، مدير، ويقرأ المحاسب)
        Route::middleware('can:orders.view')->group(function () {
            Route::get('orders', [OrderAdminController::class, 'index']);
            Route::get('orders/{id}', [OrderAdminController::class, 'show']);
            Route::get('returns', [OrderAdminController::class, 'returnsIndex']);
            Route::post('returns/{id}/{action}', [OrderAdminController::class, 'returnAct'])->whereIn('action', ['approve', 'reject', 'receive', 'refund']);
        });
        Route::middleware('can:orders.deliver')->group(function () {
            Route::post('orders/{id}/deliver', [OrderAdminController::class, 'deliver']);
            Route::post('orders/{id}/{action}', [OrderAdminController::class, 'act'])->whereIn('action', ['process', 'ready', 'dispatch', 'delivery-failed', 'cancel', 'phone']);
            Route::post('rfqs/{id}/sales-order', [OrderAdminController::class, 'fromQuote']);
        });

        // A-08 الفرز، A-09 أوامر العمل، A-10 التوزيع (منسق، مدير)
        Route::middleware('can:service.triage')->group(function () {
            Route::get('service-requests', [ServiceAdminController::class, 'index']);
            Route::get('service-requests/{id}', [ServiceAdminController::class, 'show']);
            Route::post('service-requests/{id}/triage', [ServiceAdminController::class, 'triage']);
            Route::post('service-requests/{id}/decline', [ServiceAdminController::class, 'decline']);
            Route::post('service-requests/{id}/estimate', [ServiceAdminController::class, 'estimate']);
            Route::post('service-requests/{id}/repair', [ServiceAdminController::class, 'repair']);
            Route::get('work-orders', [WorkOrderAdminController::class, 'index']);
            Route::get('work-orders/{id}', [WorkOrderAdminController::class, 'show']);
            Route::post('work-orders/{id}/schedule', [WorkOrderAdminController::class, 'schedule']);
            Route::post('work-orders/{id}/cancel', [WorkOrderAdminController::class, 'cancel']);
            Route::get('dispatch', [WorkOrderAdminController::class, 'board']);
        });
        // A-22 الاستلام في الورشة (منسق، مدير)
        Route::middleware('can:workshop.handover')->group(function () {
            Route::post('service-requests/walk-in', [ServiceAdminController::class, 'walkIn']);
            Route::post('service-requests/{id}/intake', [ServiceAdminController::class, 'intake']);
            Route::get('service-requests/{id}/receipt', [ServiceAdminController::class, 'receipt']);
            Route::get('intakes/{number}', [ServiceAdminController::class, 'byCard']);
        });

        // A-13: المالية (محاسب، مدير)
        Route::middleware('can:invoices.manage')->group(function () {
            Route::get('invoices', [FinanceController::class, 'invoices']);
            Route::get('invoices/{id}', [FinanceController::class, 'show']);
            Route::get('invoices/{id}/pdf', [FinanceController::class, 'pdf']);
            Route::post('invoices/{id}/issue', [FinanceController::class, 'issue']);
            Route::post('invoices/{id}/void', [FinanceController::class, 'void']);
            Route::post('invoices/{id}/payments', [FinanceController::class, 'recordPayment']);
            Route::get('payments', [FinanceController::class, 'payments']);
            Route::get('finance/collection', [FinanceController::class, 'collection']);
            Route::get('credit-notes', [FinanceController::class, 'creditNotes']);
        });
        Route::middleware('can:refunds.manage')->group(function () {
            Route::get('refunds', [FinanceController::class, 'refunds']);
            Route::post('payments/{id}/refunds', [FinanceController::class, 'refund']);
        });
        // الطلب نيابة عن عميل: العثور عليه أو إنشاء حسابه (مبيعات، منسق، مدير)
        Route::middleware('can:rfq.create_on_behalf')->group(function () {
            Route::post('rfqs', [RfqAdminController::class, 'store']);
            Route::get('customers/lookup', [CustomerLookupController::class, 'lookup']);
            Route::post('customers', [CustomerLookupController::class, 'store']);
        });

        // A-12: العملاء والشركات (مبيعات، منسق، محاسب، مدير)
        Route::middleware('can:customers.view')->group(function () {
            Route::get('customers', [CustomerAdminController::class, 'index']);
            Route::get('customers/{id}', [CustomerAdminController::class, 'show']);
            Route::post('customers/{id}/notes', [CustomerAdminController::class, 'addNote']);
            Route::get('organizations', [CustomerAdminController::class, 'organizationIndex']);
            Route::get('organizations/{id}', [CustomerAdminController::class, 'organization']);
            Route::post('organizations/{id}/notes', [CustomerAdminController::class, 'addOrganizationNote']);
        });
        Route::middleware('can:customers.suspend')->group(function () {
            Route::post('customers/{id}/suspend', [CustomerAdminController::class, 'suspend']);
            Route::post('customers/{id}/activate', [CustomerAdminController::class, 'activate']);
        });
        Route::middleware('can:customers.members')->group(function () {
            Route::post('organizations/{id}/invitations', [CustomerAdminController::class, 'invite']);
            Route::delete('organizations/{id}/invitations/{invitationId}', [CustomerAdminController::class, 'revokeInvitation']);
            Route::patch('organizations/{id}/members/{userId}', [CustomerAdminController::class, 'updateMember']);
            Route::delete('organizations/{id}/members/{userId}', [CustomerAdminController::class, 'removeMember']);
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
