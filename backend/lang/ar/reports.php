<?php

// أعمدة التقارير وقيمها في الملفات المصدَّرة (A-16، FR-RPT-03)
return [
    'yes' => 'نعم',
    'no' => 'لا',
    'columns' => [
        'number' => 'الرقم', 'type' => 'النوع', 'status' => 'الحالة', 'created_at' => 'تاريخ الإنشاء', 'first_sent_at' => 'أول إرسال للعرض',
        'response_hours' => 'زمن الرد (ساعات)', 'lost_reason' => 'سبب الخسارة', 'source' => 'المصدر', 'urgency' => 'الأولوية',
        'sla_due_at' => 'مهلة الفرز', 'sla_breached' => 'تجاوز المهلة', 'age_days' => 'العمر (أيام)', 'part_number' => 'رقم القطعة',
        'name' => 'الاسم', 'quantity' => 'الكمية', 'revenue' => 'الإيراد (ر.ع.)', 'available' => 'المتاح', 'line' => 'النشاط',
        'branch' => 'الفرع', 'invoices' => 'الفواتير', 'total' => 'الإجمالي (ر.ع.)', 'collected' => 'المحصّل (ر.ع.)',
    ],
    'values' => [
        'type' => ['equipment' => 'معدات', 'parts' => 'قطع', 'service' => 'صيانة'],
        'status' => [
            'submitted' => 'مقدَّم', 'in_review' => 'قيد المراجعة', 'quoted' => 'أُرسل العرض', 'won' => 'مقبول', 'lost' => 'خاسر', 'cancelled' => 'ملغى',
            'triaged' => 'مفروز', 'awaiting_approval' => 'بانتظار الموافقة', 'approved' => 'معتمد', 'in_service' => 'في الورشة',
            'completed' => 'جاهز للاستلام', 'closed' => 'مغلق', 'declined' => 'مرفوض',
        ],
        'source' => ['customer' => 'المنصة', 'walk_in' => 'حضور للورشة', 'contract' => 'عقد', 'system' => 'النظام', 'staff' => 'موظف', 'web' => 'الموقع', 'app' => 'التطبيق'],
        'urgency' => ['normal' => 'عادي', 'urgent' => 'عاجل'],
        'line' => ['parts' => 'قطع الغيار', 'service' => 'الصيانة', 'equipment' => 'المعدات', 'other' => 'أخرى'],
    ],
];
