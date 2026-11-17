# منصة ضواحي البادية (DWAHI-ALBADIA)

منصة بيع المعدات الثقيلة وقطع الغيار وصيانتها في الورشة: موقع عام، وبوابة عميل، ولوحة إدارة، وتطبيق للعملاء، وتطبيق للفنيين.

## هيكل المستودع

مستودع واحد مقسم حسب الطبقة. كل طبقة مشروع مستقل بأدواته واختباراته، ويربط بينها عقد الـ API.

```
dwahi-platform/
├── backend/              # الخادم: Laravel 13، PHP 8.4، MySQL 8.4، Redis   ← backend/README.md
├── frontend/             # الويب: Next.js 16، TypeScript، Tailwind 4      ← الموقع /، الحساب /account، الإدارة /admin
├── mobile/
│   ├── customer_app/     # تطبيق العملاء: Flutter 3.47 (Riverpod، go_router، dio)
│   ├── technician_app/   # تطبيق الفنيين (هيكل حتى S8)
│   └── packages/
│       └── dwahi_ui/     # نظام التصميم المشترك للتطبيقين
├── contracts/openapi/    # عقد الـ API، مولّد من كود backend (Scramble)
├── infra/docker/         # صورة PHP للتطوير وتهيئة MySQL
├── docs/                 # المتطلبات والمعمارية والخطة (00 إلى 09) والشعار
├── .github/workflows/    # CI: backend و frontend و mobile
└── docker-compose.yml    # بيئة التطوير المحلية
```

الوثائق مرجع كل قرار: [docs/00_README.md](docs/00_README.md) (الفهرس)، و [01_srs.md](docs/01_srs.md) (المتطلبات)، و [04_architecture.md](docs/04_architecture.md) (المعمارية)، و [09_implementation_plan.md](docs/09_implementation_plan.md) (الخطة).

## التشغيل المحلي

المتطلبات: Docker Desktop و Node.js 22+ و Flutter 3.47.

### backend

```bash
cp .env.example .env                          # ثم ضع كلمات مرور عشوائية
docker compose up -d mysql redis
docker compose build api
docker compose run --rm api composer install
cp backend/.env.example backend/.env          # DB_PASSWORD = نفس قيمة .env في الجذر
docker compose run --rm api php artisan key:generate
docker compose run --rm api php artisan migrate --seed
docker compose up -d api                      # http://localhost:8000/api/v1

# أول مدير: يصله بريد تفعيل (في التطوير يُكتب في backend/storage/logs/laravel.log)
docker compose exec api php artisan staff:create-admin admin@example.com "اسم المدير"
```

العامل (الطوابير) عند الحاجة: `docker compose --profile worker up -d worker`.

### frontend

```bash
cd frontend
cp .env.example .env.local
npm install
npm run dev                                   # http://localhost:3000  (الإدارة: /ar/admin)
```

### mobile

```bash
cd mobile/customer_app
flutter pub get
flutter run --dart-define=API_URL=http://10.0.2.2:8000/api/v1   # محاكي Android
```

## عقد الـ API

العقد يُولَّد من كود backend ولا يُكتب يدوياً. بعد أي تعديل على الـ API:

```bash
docker compose exec api php artisan scramble:export --path=../contracts/openapi/openapi.json
cd frontend && npm run gen:api                # يولّد src/lib/api-schema.d.ts
```

CI يرفض العقد غير المحدّث، ويرفض أي تغيير كاسر للتطبيقات القديمة (04 §4.1).

## الاختبارات

```bash
docker compose exec api ./vendor/bin/pest           # backend: على قاعدة dwahi_test في MySQL، لا SQLite (04 §11)
docker compose exec api ./vendor/bin/pint --test    # backend: نمط الكود
cd frontend && npx tsc --noEmit && npx eslint src   # frontend
cd mobile/customer_app && flutter analyze && flutter test   # mobile (وكذلك technician_app و packages/dwahi_ui)
python docs/tools/trace_check.py docs --write        # اتساق الوثائق بعد تعديل المتطلبات أو القصص أو الشاشات
```

## المصادقة (04 §5)

- **العملاء:** الجوال + رمز OTP (التسجيل والدخول مسار واحد)، أو البريد + كلمة مرور إن أنشأها. في التطوير مع `SMS_DRIVER=log` يظهر الرمز في الصفحة ولا يُرسل.
- **الموظفون:** البريد + كلمة المرور + TOTP إلزامي (يُعدّ عند أول دخول). الفني: كلمة المرور + جهاز مسجل برمز تفعيل لمرة واحدة.
- **الويب:** خادم Next.js يحمل الرموز في كوكيز HttpOnly، والمتصفح لا يرى أي رمز.
- **الطوارئ:** `php artisan staff:reset-2fa <email>` لمدير وحيد فقد جواله.

## ملاحظات

- **البيانات التجريبية** (`DemoCatalogSeeder`): المعدات والقطع والأسعار والكميات والأرقام البديلة مختلَقة، وتُزرع في `local` و `testing` فقط.
- **محتوى مبدئي** (`ContentSeeder`): يحتاج مراجعة الشركة قبل الإطلاق، والصفحات القانونية مسودات.
- **موقع الورشة** في `BranchSeeder` تقريبي حتى ترسل الشركة الموقع الدقيق.
- **`vendor` في Volume داخلي** (`api-vendor`)، لذلك `composer` يُشغَّل داخل الحاوية دائماً.
- **العربية في سطر أوامر MySQL**: `mysql --default-character-set=utf8mb4`، وإلا تظهر نتائج بحث FULLTEXT خاطئة.
- **`TRUSTED_PROXIES`**: عناوين الخوادم التي يُقبل منها عنوان المستخدم الحقيقي. لا `*` في الإنتاج.
- **سجل التدقيق** إلحاقي بـ Triggers، ويتطلب `log_bin_trust_function_creators=1` في MySQL (مضبوط في docker-compose و CI).

## ما تم

| السبرنت | الحالة |
|---------|--------|
| S0 الأساس | البيئة، وهيكل الوحدات، وصيغة الأخطاء والتتبع، والعقد المولّد وأنواع الويب، و CI، وهيكلا التطبيقين ونظام التصميم المشترك |
| S1 الهوية | الـ API كاملاً، وشاشات الويب (W-14 إلى W-16، C-01، C-02، A-21، A-17، A-19)، وشاشات تطبيق العملاء (M-01 إلى M-05، M-22) |
| S2 و S3 | الجانب العام: الكتالوج والقطع والمحتوى والموقع العام |

**لم يُبنَ بعد:** شاشات الإدارة للكتالوج والمحتوى، والعروض، والطلبات، والصيانة، وتطبيق الفني، والرموز الاحتياطية للتحقق الثنائي، وتوليد عميل Dart من العقد.
