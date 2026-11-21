<?php

// نصوص الإشعارات بالعربية (01 §8). المعاملات: :number رقم الطلب أو العرض، و:reason السبب، وغيرها.
return [
    'N-01' => ['title' => 'استلمنا طلب عرض السعر :number', 'body' => 'سنراجع طلبك ونرسل لك العرض في أقرب وقت.'],
    'N-01-staff' => ['title' => 'طلب عرض سعر جديد :number', 'body' => ':customer طلب عرض سعر (:type).'],
    'N-02' => ['title' => 'عرض سعر :number', 'body' => 'وصلك عرض السعر لطلب :rfq بإجمالي :total ر.ع.، صالح حتى :valid_until.'],
    'N-03' => ['title' => 'رد العميل على العرض :number', 'body' => ':customer :action'],
    'N-04' => ['title' => 'العرض :number ينتهي قريباً', 'body' => 'تنتهي صلاحية العرض في :valid_until. راجعه قبل انتهائه.'],
    'N-17' => ['title' => 'رسالة جديدة في :number', 'body' => ':sender: :preview'],
    'N-25' => ['title' => 'تعذّر تقديم عرض للطلب :number', 'body' => ':reason'],
    'N-31' => ['title' => 'عرض بانتظار اعتمادك :number', 'body' => ':member طلب اعتماد العرض بإجمالي :total ر.ع.'],
    'actions' => [
        'accepted' => 'قبل العرض.',
        'rejected' => 'رفض العرض. السبب: :note',
        'revision' => 'طلب تعديل العرض: :note',
        'renewal' => 'طلب تجديد العرض المنتهي.',
    ],
    'types' => ['equipment' => 'معدات', 'parts' => 'قطع غيار', 'service' => 'صيانة'],
];
