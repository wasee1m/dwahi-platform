<?php

namespace Database\Seeders;

use App\Modules\Platform\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * القيم الافتراضية في 03 §12. سياسات الإلغاء والإرجاع والرسوم تحددها الشركة (Q-07)،
 * فهذه قيم مؤقتة للتطوير تُعدَّل من A-18. لا تُستبدل قيمة موجودة.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'vat.default_rate' => '5.00',
            'pricing.display' => 'excl_vat',
            'quote.validity_days' => 14,
            'parts.availability_thresholds' => ['limited' => 1, 'available' => 5],
            'order.pickup_hold_working_days' => 3,
            'order.max_open_unpaid_per_customer' => 3,
            'order.phone_confirm_above_amount' => '100.000',
            'sla.triage' => ['normal_working_hours' => 4, 'urgent_hours' => 1],
            'business_hours' => ['days' => ['sun', 'mon', 'tue', 'wed', 'thu'], 'start' => '08:00', 'end' => '17:00'],
            'review.window_days' => 30,
            'service.release_requires_payment' => true,
            'service.collection_reminder_days' => 3,
            'returns.window_days' => 14,
            'account.deletion_grace_days' => 30,
            'numbering.prefixes' => ['RFQ', 'QT', 'SO', 'SR', 'WO', 'INV', 'CN', 'RC'],
        ];

        foreach ($defaults as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value, 'updated_at' => now()]);
        }
    }
}
