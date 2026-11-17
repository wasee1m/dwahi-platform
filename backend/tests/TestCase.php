<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** يزرع DatabaseSeeder (الإعدادات والفرع والمحتوى والكتالوج التجريبي) مرة واحدة بعد الترحيل */
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        // عميل الاختبار في Symfony يرسل "en" افتراضياً، والجمهور الأساسي عربي
        $this->withHeader('Accept-Language', 'ar');
    }
}
