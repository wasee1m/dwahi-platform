<?php

namespace App\Support;

/**
 * النصوص ثنائية اللغة في عمودين _ar و _en (03 §1). تعيد نص لغة الطلب، وإلا العربية.
 */
trait Localized
{
    public function localized(string $attribute): ?string
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $value = $this->getAttribute("{$attribute}_{$locale}");

        return ($value === null || $value === '') ? $this->getAttribute("{$attribute}_ar") : $value;
    }
}
