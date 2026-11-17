<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * قيود CHECK في MySQL 8.4 (03 §1). Schema Builder في Laravel لا يدعمها، فتُضاف بعد إنشاء الجدول.
 */
final class Check
{
    public static function add(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    /** @param list<string> $values */
    public static function in(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(',', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $values));
        $expr = "`{$column}` IN ({$list})";
        self::add($table, self::name($table, $column), $nullable ? "`{$column}` IS NULL OR {$expr}" : $expr);
    }

    /** "واحد فقط من هذه الأعمدة غير فارغ" بديل num_nonnulls في PostgreSQL (03 §1) */
    public static function exactlyOne(string $table, string $name, array $columns, string $op = '='): void
    {
        $sum = implode(' + ', array_map(fn ($c) => "(`{$c}` IS NOT NULL)", $columns));
        self::add($table, $name, "({$sum}) {$op} 1");
    }

    private static function name(string $table, string $column): string
    {
        return substr("{$table}_{$column}_chk", 0, 64);
    }
}
