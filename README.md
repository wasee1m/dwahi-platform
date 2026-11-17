# منصة ضواحي البادية (DWAHI-ALBADIA)

الكود المصدري للمنصة. الوثائق (المتطلبات، والمعمارية، والخطة) في مجلد المشروع:
`OneDrive\Desktop\project\oman_heavy_equipment_docs\docs` (المرجع: 01_srs.md و 04_architecture.md و 09_implementation_plan.md).

| المجلد | المحتوى | التقنية (ADR-02) |
|--------|---------|-------------------|
| `apps/api` | الخادم: REST API `/api/v1` | Laravel 13، PHP 8.4، MySQL 8.4، Redis |
| `apps/web` | الموقع العام، وبوابة العميل `/account`، ولوحة الإدارة `/admin` | Next.js 16 (TypeScript، Tailwind 4) |
| `apps/customer_app` | تطبيق العملاء | Flutter 3.47 (Riverpod، go_router، dio) |
| `apps/technician_app` | تطبيق الفنيين (هيكل فقط حتى S8) | Flutter 3.47 |
| `openapi/openapi.json` | عقد الـ API، **مولّد من الكود** (Scramble) | OpenAPI 3.1 |
| `infra/docker` | صورة PHP للتطوير وتهيئة MySQL | Docker |
| `.github/workflows/ci.yml` | بوابات الجودة لكل تعديل (04 §9) | GitHub Actions |

## التشغيل المحلي

المتطلبات: Docker Desktop و Node.js 22+ و Flutter 3.47.

```bash
cp .env.example .env            # ثم ضع كلمات مرور عشوائية
docker compose up -d mysql redis
docker compose build api
docker compose run --rm api composer install
cp apps/api/.env.example apps/api/.env   # DB_PASSWORD = نفس قيمة .env في الجذر
docker compose run --rm api php artisan key:generate
docker compose run --rm api php artisan migrate --seed
docker compose up -d api        # http://localhost:8000/api/v1

# أول مدير: يصله بريد تفعيل (في التطوير يُكتب في storage/logs/laravel.log)
docker compose exec api php artisan staff:create-admin admin@example.com "اسم المدير"

cd apps/web
cp .env.example .env.local
npm install
npm run dev                     # http://localhost:3000  (الإدارة: /ar/admin)

cd apps/customer_app
flutter run --dart-define=API_URL=http://10.0.2.2:8000/api/v1   # محاكي Android
```

العامل (الطوابير) عند الحاجة: `docker compose --profile worker up -d worker`.

## عقد الـ API

العقد يُولَّد من كود Laravel ولا يُكتب يدوياً (قرار 2026-11-17). بعد أي تعديل على الـ API:

```bash
docker compose exec api php artisan scramble:export --path=/var/www/openapi/openapi.json
cd apps/web && npm run gen:api      # يولّد src/lib/api-schema.d.ts
```

CI يرفض العقد غير المحدّث، ويرفض أي تغيير كاسر للتطبيقات القديمة (04 §4.1).

## الاختبارات

```bash
docker compose exec api ./vendor/bin/pest        # 54 اختباراً على قاعدة dwahi_test في MySQL، لا SQLite (04 §11)
docker compose exec api ./vendor/bin/pint --test # نمط الكود
cd apps/web && npx tsc --noEmit && npx eslint src
cd apps/customer_app && flutter analyze && flutter test
```

## المصادقة (S1، 04 §5)

- **العملاء:** الجوال + رمز OTP (التسجيل والدخول مسار واحد)، أو البريد + كلمة مرور إن أنشأها. في التطوير مع `SMS_DRIVER=log` يظهر الرمز في الصفحة ولا يُرسل.
- **الموظفون:** البريد + كلمة المرور + TOTP إلزامي (يُعدّ عند أول دخول). الفني: كلمة المرور + جهاز مسجل برمز تفعيل لمرة واحدة.
- **الويب:** خادم Next.js يحمل الرموز في كوكيز HttpOnly، والمتصفح لا يرى أي رمز. `proxy.ts` يجدد رمز الوصول قبل عرض الصفحة.
- **الطوارئ:** `php artisan staff:reset-2fa <email>` لمدير وحيد فقد جواله.

## ملاحظات

- **البيانات التجريبية** (`DemoCatalogSeeder`): المعدات والقطع والأسعار والكميات والأرقام البديلة مختلَقة، وتُزرع في `local` و `testing` فقط.
- **محتوى مبدئي** (`ContentSeeder`): يحتاج مراجعة الشركة قبل الإطلاق، والصفحات القانونية مسودات.
- **موقع الورشة** في `BranchSeeder` تقريبي حتى ترسل الشركة الموقع الدقيق.
- **`vendor` في Volume داخلي** (`api-vendor`)، لذلك `composer` يُشغَّل داخل الحاوية دائماً.
- **العربية في سطر أوامر MySQL**: `mysql --default-character-set=utf8mb4`، وإلا تظهر نتائج بحث FULLTEXT خاطئة.
- **`TRUSTED_PROXIES`**: عناوين الخوادم التي يُقبل منها عنوان المستخدم الحقيقي (خادم Next.js والموازن). لا `*` في الإنتاج.
- **سجل التدقيق** إلحاقي بـ Triggers، ويتطلب `log_bin_trust_function_creators=1` في MySQL (مضبوط في docker-compose و CI).

## ما تم

| السبرنت | الحالة |
|---------|--------|
| S0 الأساس | البيئة، وهيكل الوحدات، وصيغة الأخطاء والتتبع، وعقد OpenAPI المولّد والأنواع المولّدة للويب، و CI، وهيكلا التطبيقين |
| S1 الهوية | الـ API كاملاً (OTP، الجلسات والتدوير، TOTP، أجهزة الفنيين، الموظفون والأدوار، سجل التدقيق)، وشاشات الويب (W-14، W-15، W-16، C-01، C-02، A-21، A-17، A-19)، وشاشات تطبيق العملاء (M-01 إلى M-05، M-22) |
| S2 و S3 | الجانب العام: الكتالوج والقطع والمحتوى والموقع العام |

**لم يُبنَ بعد:** شاشات الإدارة للكتالوج والمحتوى، والعروض، والطلبات، والصيانة، وتطبيق الفني، والرموز الاحتياطية للتحقق الثنائي، وتوليد عميل Dart من العقد (التطبيق يستخدم الآن طبقة dio رقيقة).
