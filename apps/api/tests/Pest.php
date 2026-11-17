<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// اختبارات التكامل على MySQL 8.4 حقيقي (قاعدة dwahi_test)، لا SQLite (04 §11).
// البيانات الأساسية تُزرع مرة واحدة وتُلتزم (FULLTEXT في InnoDB لا يرى إلا الملتزم)،
// وكل اختبار يعمل داخل معاملة تُلغى بعده.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function api(): string
{
    return '/api/v1';
}
