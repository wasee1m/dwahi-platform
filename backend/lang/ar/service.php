<?php

// رسائل وحدة الصيانة (SM-4، SM-5، FR-SRV-08)
return [
    'subject_required' => 'اختر معدة من أسطولك، أو أضف معدة، أو اختر مكوّناً منفصلاً.',
    'problem_required' => 'اكتب وصف العطل أو أرفق تسجيلاً صوتياً.',
    'cancelled_by_customer' => 'ألغاه العميل.',
    'estimate_rejected' => 'رفض العميل تقدير الصيانة.',
    'estimate_expired' => 'انتهت صلاحية تقدير الصيانة دون رد.',
    'reschedule_reason' => 'اكتب سبب تغيير الموعد.',
    'not_technicians' => 'اختر فنيين نشطين فقط.',
    'meter_required' => 'أدخل قراءة العداد أو سبب تعذرها.',
    'intake_lower_reading' => 'قراءة الاستلام أقل من السابقة.',
    'tech_lower_reading' => 'قراءة الفني أقل من السابقة.',
    'hold_reasons' => [
        'awaiting_parts' => 'بانتظار قطع', 'awaiting_approval' => 'بانتظار موافقة', 'awaiting_customer' => 'بانتظار العميل',
        'end_of_shift' => 'نهاية الدوام', 'other' => 'سبب آخر',
    ],
    'components' => ['diesel_engine' => 'محرك ديزل', 'hydraulic_pump' => 'مضخة هيدروليك', 'hydraulic_cylinder' => 'سلندر هيدروليك', 'transmission' => 'ناقل حركة', 'other' => 'مكوّن آخر'],
    'urgency' => ['normal' => 'عادي', 'urgent' => 'عاجل'],
];
