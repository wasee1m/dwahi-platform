<?php

namespace App\Modules\Platform\Models;

use App\Support\Model;
use Illuminate\Support\Facades\Cache;

/**
 * مفاتيح الإعدادات في 03 §12 (FR-ADM-03). القيم تحددها الشركة من A-18 دون تطوير.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;

    protected $casts = ['value' => 'array'];

    public function uniqueIds(): array
    {
        return []; // المفتاح نصي وليس UUID
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember("setting:{$key}", 300, fn () => static::query()->find($key)?->value);

        return $value ?? $default;
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $s) => Cache::forget("setting:{$s->key}"));
    }
}
