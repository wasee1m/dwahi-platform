# DWAHI-ALBADIA API

خادم المنصة: Laravel 13 على PHP 8.4، وقاعدة بيانات MySQL 8.4، و Redis للطوابير والذاكرة المؤقتة.

- الوحدات في `app/Modules/<Module>` (المعمارية في 04 §3).
- نقاط النهاية في `routes/api.php` تحت `/api/v1`، والعقد المولّد في `../../openapi/openapi.json`.
- الاختبارات في `tests/Feature` على MySQL حقيقي: `./vendor/bin/pest`.

التشغيل والأوامر في [README الجذر](../../README.md).
