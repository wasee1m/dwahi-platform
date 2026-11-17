<?php

// رسائل التحقق بالعربية (NFR-14). القواعد المستخدمة حالياً فقط، وتُستكمل مع كل وحدة.
return [
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'حقل :attribute يجب أن يكون نصاً.',
    'email' => 'اكتب بريداً إلكترونياً صحيحاً.',
    'max' => ['string' => 'حقل :attribute يجب ألا يزيد عن :max حرفاً.', 'numeric' => 'حقل :attribute يجب ألا يزيد عن :max.'],
    'min' => ['string' => 'حقل :attribute يجب ألا يقل عن :min أحرف.', 'numeric' => 'حقل :attribute يجب ألا يقل عن :min.'],
    'in' => 'قيمة :attribute غير صحيحة.',
    'integer' => 'حقل :attribute يجب أن يكون رقماً صحيحاً.',
    'boolean' => 'قيمة :attribute غير صحيحة.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'exists' => 'قيمة :attribute غير موجودة.',

    'custom' => [
        'phone' => ['regex' => 'اكتب رقم جوال صحيحاً، مثل 9XXX XXXX أو برمز الدولة.'],
    ],

    'attributes' => [
        'name' => 'الاسم',
        'phone' => 'الجوال',
        'email' => 'البريد الإلكتروني',
        'company' => 'الشركة',
        'topic' => 'الموضوع',
        'message' => 'الرسالة',
        'branch_code' => 'الفرع',
        'q' => 'البحث',
    ],
];
