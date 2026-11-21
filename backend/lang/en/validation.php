<?php

return [
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute must be text.',
    'email' => 'Enter a valid email address.',
    'max' => ['string' => 'The :attribute may not be longer than :max characters.', 'numeric' => 'The :attribute may not be greater than :max.'],
    'min' => ['string' => 'The :attribute must be at least :min characters.', 'numeric' => 'The :attribute must be at least :min.'],
    'in' => 'The selected :attribute is not valid.',
    'integer' => 'The :attribute must be a whole number.',
    'boolean' => 'The :attribute is not valid.',
    'regex' => 'The :attribute format is not valid.',
    'exists' => 'The selected :attribute does not exist.',
    'required_for_publish' => 'Required to publish: content in both Arabic and English (NFR-14).',

    'custom' => [
        'phone' => ['regex' => 'Enter a valid mobile number, such as 9XXX XXXX or with a country code.'],
    ],

    'attributes' => [
        'branch_code' => 'branch',
        'q' => 'search',
        'setting_value' => 'value',
        'setting_start' => 'start time',
        'setting_end' => 'end time',
        'setting_days' => 'working days',
        'setting_limited' => '"limited" threshold',
        'setting_available' => '"available" threshold',
        'setting_normal_working_hours' => 'normal deadline',
        'setting_urgent_hours' => 'urgent deadline',
        'setting_name_ar' => 'Arabic name',
        'setting_name_en' => 'English name',
    ],
];
