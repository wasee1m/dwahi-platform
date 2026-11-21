<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\Audit;
use App\Modules\Platform\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A-18 الإعدادات (FR-ADM-03، US-40): كل تغيير بالقيمة قبل وبعد في التدقيق */
class SettingsController
{
    public function index(): JsonResponse
    {
        $stored = Setting::query()->leftJoin('users as u', 'u.id', '=', 'settings.updated_by')
            ->get(['settings.key', 'settings.value', 'settings.updated_at', 'u.full_name as updated_by_name'])->keyBy('key');

        return response()->json(['data' => collect(SettingsRegistry::all())->map(function ($def, $key) use ($stored) {
            $row = $stored[$key] ?? null;

            return [
                'key' => $key, 'group' => $def['group'], 'type' => $def['type'], 'rule' => $def['rule'] ?? null,
                'value' => $row?->value ?? $def['default'], 'default' => $def['default'],
                'options' => $def['options'] ?? null, 'readonly' => $def['readonly'] ?? false,
                'updated_at' => $row?->updated_at?->toIso8601ZuluString(), 'updated_by' => $row?->updated_by_name,
            ];
        })->values()]);
    }

    /** PUT /admin/settings {values: {key: value}}: تبويب كامل في طلب واحد، والمحفوظ فقط ما تغيّر */
    public function update(Request $request): JsonResponse
    {
        $registry = SettingsRegistry::all();
        $values = $request->validate(['values' => ['required', 'array', 'min:1']])['values'];

        $errors = [];
        $clean = [];
        foreach ($values as $key => $value) {
            if (! isset($registry[$key]) || ($registry[$key]['readonly'] ?? false)) {
                $errors["values.{$key}"] = [__('validation.prohibited', ['attribute' => $key])];

                continue;
            }
            $rules = SettingsRegistry::rulesFor($key);
            $v = Validator::make(['value' => $value], $rules, [], $this->attributeNames(array_keys($rules)));
            if ($v->fails()) {
                foreach ($v->errors()->messages() as $path => $messages) {
                    $errors['values.'.$key.substr($path, strlen('value'))] = $messages;
                }

                continue;
            }
            $clean[$key] = SettingsRegistry::normalize($key, $value);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($clean, $registry, $request) {
            foreach ($clean as $key => $value) {
                $row = Setting::query()->find($key);
                $before = $row?->value ?? $registry[$key]['default'];
                if ($before === $value) {
                    continue;
                }
                if ($value === null) {
                    $row?->delete(); // يعود للقيمة غير المحددة
                } else {
                    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
                }
                Audit::record('setting.changed', 'setting', null, [$key => $before], [$key => $value]);
            }
        });

        return $this->index();
    }

    /** "value.end" ← "وقت النهاية" في الرسائل، والقيمة نفسها "القيمة" (الحقل معنون في الواجهة) */
    private function attributeNames(array $paths): array
    {
        return collect($paths)->mapWithKeys(function (string $path) {
            $sub = $path === 'value' ? 'value' : substr($path, strlen('value.'));
            $key = "validation.attributes.setting_{$sub}";

            return [$path => __($key) === $key ? $sub : __($key)];
        })->all();
    }
}
