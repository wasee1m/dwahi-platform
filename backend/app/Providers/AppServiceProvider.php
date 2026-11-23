<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Accounts\Console\AccountJobs;
use App\Modules\Accounts\Events\DataExportReady;
use App\Modules\Accounts\Events\MemberInvited;
use App\Modules\Billing\Events\InvoiceIssued;
use App\Modules\Identity\Authorization\Permissions;
use App\Modules\Identity\Sms\LogSmsSender;
use App\Modules\Identity\Sms\SmsSender;
use App\Modules\Messaging\Events\MessagePosted;
use App\Modules\Notifications\Console\MessageDigest;
use App\Modules\Notifications\Listeners\AccountNotifications;
use App\Modules\Notifications\Listeners\MessageNotifications;
use App\Modules\Notifications\Listeners\OrderNotifications;
use App\Modules\Notifications\Listeners\QuotingNotifications;
use App\Modules\Notifications\Listeners\ServiceNotifications;
use App\Modules\Notifications\Push\LogPushSender;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Orders\Console\OrderJobs;
use App\Modules\Orders\Events\OrderEvent;
use App\Modules\Orders\Listeners\QuoteAccepted;
use App\Modules\Quoting\Events\QuotingEvent;
use App\Modules\Service\Console\ServiceJobs;
use App\Modules\Service\Events\ServiceEvent;
use App\Modules\Service\Listeners\EstimateResponses;
use App\Modules\Service\Listeners\ServiceInvoices;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // مزود SMS (FR-INT-02): السجل في التطوير حتى تختار الشركة المزود (Q-04)
        $this->app->singleton(SmsSender::class, fn () => match (config('services.sms.driver')) {
            default => new LogSmsSender,
        });
        // Push (FR-NOT-02): السجل حتى ربط FCM و APNs مع بناء التطبيقات
        $this->app->singleton(PushSender::class, fn () => new LogPushSender);
    }

    public function boot(): void
    {
        // في التطوير: كشف التحميل الكسول (N+1) والحقول غير المعرّفة مبكراً
        Model::shouldBeStrict(! $this->app->isProduction());

        // كلمات المرور (04 §10): 10 أحرف على الأقل، ومنع المسربة في الإنتاج
        Password::defaults(fn () => $this->app->isProduction() ? Password::min(10)->uncompromised() : Password::min(10));

        // الصلاحيات من مكان واحد (Permissions = 01 §3.2)
        foreach (Permissions::all() as $ability) {
            Gate::define($ability, fn (User $user) => $user->hasAbility($ability));
        }
        // رفع الملفات لكل من يدير محتوى يحتاج صوراً
        Gate::define('uploads.create', fn (User $user) => $user->hasAbility('catalog.manage')
            || $user->hasAbility('parts.manage') || $user->hasAbility('content.manage'));
        // طلبات القطع: موظف القطع يديرها، والمحاسب يقرؤها للاسترداد والمطابقة
        Gate::define('orders.view', fn (User $user) => $user->hasAbility('orders.deliver') || $user->hasAbility('refunds.manage') || $user->hasAbility('invoices.manage'));
        Gate::define('staff.uploads', fn (User $user) => $user->hasAbility('quotation.send') || $user->hasAbility('orders.deliver')
            || $user->hasAbility('invoices.manage') || $user->hasAbility('rfq.create_on_behalf') || $user->hasAbility('workshop.handover'));
        // قراءة الماركات والفئات والموديلات: موظف القطع يحتاجها للتوافق (A-04) دون صلاحية تعديلها
        Gate::define('catalog.read', fn (User $user) => $user->hasAbility('catalog.manage') || $user->hasAbility('parts.manage'));

        // أحداث المجال بين الوحدات (04 §3)
        Event::listen(QuotingEvent::class, EstimateResponses::class); // قبل إشعارات العروض: الاعتماد الآلي يمنع N-10
        Event::listen(QuotingEvent::class, QuotingNotifications::class);
        Event::listen(ServiceEvent::class, ServiceNotifications::class);
        Event::listen(MessagePosted::class, MessageNotifications::class);
        Event::listen(MemberInvited::class, [AccountNotifications::class, 'invited']);
        Event::listen(DataExportReady::class, [AccountNotifications::class, 'exportReady']);
        Event::listen(QuotingEvent::class, QuoteAccepted::class);
        Event::listen(OrderEvent::class, OrderNotifications::class);
        Event::listen(InvoiceIssued::class, [OrderNotifications::class, 'invoiceIssued']);
        Event::listen(InvoiceIssued::class, ServiceInvoices::class);
        $this->commands([AccountJobs::class, OrderJobs::class, ServiceJobs::class, MessageDigest::class]);

        // تحديد معدل الطلبات (NFR-09). حدود OTP لكل رقم ودولة في OtpService
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('leads', fn (Request $r) => [
            Limit::perMinute(3)->by('leads:m:'.$r->ip()),
            Limit::perDay(20)->by('leads:d:'.$r->ip()),
        ]);
        RateLimiter::for('auth', fn (Request $r) => [
            Limit::perMinute(10)->by('auth:m:'.$r->ip()),
            // حد عام لكل معرّف دخول. حدود OTP الأدق (5 محاولات ثم قفل برسالة واضحة) في OtpService
            Limit::perMinute(10)->by('auth:login:'.strtolower((string) ($r->input('email') ?? $r->input('login') ?? $r->input('phone') ?? $r->ip()))),
        ]);
        RateLimiter::for('account', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?? $r->ip()));
        // كل دعوة رسالة SMS على حساب الشركة
        RateLimiter::for('invitations', fn (Request $r) => [Limit::perHour(10)->by('inv:h:'.$r->user()?->id), Limit::perDay(30)->by('inv:d:'.$r->user()?->id)]);
        // إتمام الطلب وطلبات الإرجاع لكل مستخدم
        RateLimiter::for('orders', fn (Request $r) => [Limit::perMinute(10)->by('ord:m:'.$r->user()?->id), Limit::perDay(30)->by('ord:d:'.$r->user()?->id)]);
        RateLimiter::for('exports', fn (Request $r) => Limit::perDay(3)->by('export:'.$r->user()?->id));
        RateLimiter::for('uploads', fn (Request $r) => [Limit::perMinute(20)->by('up:m:'.($r->user()?->id ?? $r->ip())), Limit::perDay(300)->by('up:d:'.($r->user()?->id ?? $r->ip()))]);

        // عقد OpenAPI مولّد من الكود (قرار 2026-11-17)
        Scramble::configure()->routes(fn ($route) => str_starts_with($route->uri, 'api/v1'))
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->info->title = 'DWAHI-ALBADIA API';
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }
}
