<?php

namespace Database\Seeders;

use App\Modules\Platform\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * الورشة الوحيدة اليوم (01 §1.1، Q-08). الهاتف والبريد من منشورات الشركة.
 * الإحداثيات تقريبية (وسط صحار) حتى ترسل الشركة موقع الورشة الدقيق.
 */
class BranchSeeder extends Seeder
{
    public function run(): void
    {
        if (Branch::query()->where('code', 'SOH')->exists()) {
            return;
        }

        $hours = ['open' => '08:00', 'close' => '17:00'];

        Branch::query()->create([
            'code' => 'SOH',
            'name_ar' => 'ورشة صحار',
            'name_en' => 'Sohar workshop',
            'governorate' => 'north_batinah',
            'wilayat' => 'sohar',
            'address_ar' => 'المنطقة الصناعية بالعوهي، صحار، محافظة شمال الباطنة',
            'address_en' => 'Al-Awhi Industrial Area, Sohar, North Al Batinah',
            'location' => Branch::point(24.3470, 56.7090),
            'phone' => '+96895534404',
            'whatsapp_sales' => '+96895534404',
            'whatsapp_service' => '+96895534404',
            'whatsapp_parts' => '+96895534404',
            'email' => 'D.A.B.Mllc@gmail.com',
            'working_hours' => [
                'sun' => $hours, 'mon' => $hours, 'tue' => $hours, 'wed' => $hours, 'thu' => $hours,
                'fri' => null, 'sat' => null,
            ],
            'covered_governorates' => ['north_batinah'],
            'is_active' => true,
        ]);
    }
}
