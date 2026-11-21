<?php

namespace Database\Seeders;

use App\Modules\Platform\Models\Setting;
use App\Modules\Platform\Support\SettingsRegistry;
use Illuminate\Database\Seeder;

/**
 * القيم الافتراضية في 03 §12 من SettingsRegistry. سياسات الإلغاء والإرجاع والرسوم تحددها الشركة (Q-07)،
 * فهذه قيم مؤقتة للتطوير تُعدَّل من A-18. لا تُستبدل قيمة موجودة، والقيمة غير المحددة (null) لا تُحفظ.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SettingsRegistry::all() as $key => $def) {
            if ($def['default'] !== null) {
                Setting::query()->firstOrCreate(['key' => $key], ['value' => $def['default'], 'updated_at' => now()]);
            }
        }
    }
}
