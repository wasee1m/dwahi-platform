<?php

namespace App\Modules\Platform\Security;

/** فاحص البرمجيات الخبيثة للملفات المرفوعة (NFR-11) */
interface VirusScanner
{
    /**
     * @return string|null اسم التوقيع إن وُجد فيروس، أو null إن كان الملف سليماً
     *
     * @throws \RuntimeException إن تعذر الوصول للفاحص (يُعاد المحاولة لاحقاً)
     */
    public function scan(string $bytes): ?string;
}
