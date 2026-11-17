# DWAHI-ALBADIA Backend

خادم المنصة: Laravel 13 على PHP 8.4، وقاعدة بيانات MySQL 8.4، و Redis للطوابير والذاكرة المؤقتة.

```
backend/
├── app/
│   ├── Modules/          # وحدات المجال (04 §3)، كل وحدة: Models، Http (Controllers، Resources، Requests، Middleware)، Queries، Services، Support
│   │   ├── Catalog/      # المعدات المعروضة والماركات والفئات والموديلات
│   │   ├── Content/      # الصفحات والأسئلة الشائعة والبنرات والاستفسارات
│   │   ├── Identity/     # الدخول والجلسات والموظفون والأدوار والأجهزة
│   │   ├── Parts/        # القطع والمخزون
│   │   └── Platform/     # الفروع والإعدادات والملفات وسجل التدقيق وصيغة الأخطاء
│   ├── Models/User.php   # المستخدم (موقع Laravel القياسي، يستخدمه نظام المصادقة)
│   └── Support/          # أدوات مشتركة: النموذج الأساسي، والمال، وقيود CHECK
├── database/             # migrations و seeders و factories
├── routes/api.php        # كل نقاط النهاية تحت /api/v1
└── tests/Feature/        # اختبارات لكل وحدة على MySQL حقيقي
```

- العقد المولّد في `../contracts/openapi/openapi.json` (`php artisan scramble:export --path=../contracts/openapi/openapi.json`).
- الاختبارات: `./vendor/bin/pest`، ونمط الكود: `./vendor/bin/pint`.

التشغيل في [README الجذر](../README.md).
