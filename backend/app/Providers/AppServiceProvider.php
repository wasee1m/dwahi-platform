<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Identity\Authorization\Permissions;
use App\Modules\Identity\Sms\LogSmsSender;
use App\Modules\Identity\Sms\SmsSender;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
        // قراءة الماركات والفئات والموديلات: موظف القطع يحتاجها للتوافق (A-04) دون صلاحية تعديلها
        Gate::define('catalog.read', fn (User $user) => $user->hasAbility('catalog.manage') || $user->hasAbility('parts.manage'));

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

        // عقد OpenAPI مولّد من الكود (قرار 2026-11-17)
        Scramble::configure()->routes(fn ($route) => str_starts_with($route->uri, 'api/v1'))
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->info->title = 'DWAHI-ALBADIA API';
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }
}
