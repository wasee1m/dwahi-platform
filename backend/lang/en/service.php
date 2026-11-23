<?php

return [
    'subject_required' => 'Choose a machine from your fleet, add one, or choose a separate component.',
    'problem_required' => 'Describe the problem or attach a voice note.',
    'cancelled_by_customer' => 'Cancelled by the customer.',
    'estimate_rejected' => 'The customer declined the service estimate.',
    'estimate_expired' => 'The service estimate expired without a reply.',
    'reschedule_reason' => 'Write the reason for changing the time.',
    'not_technicians' => 'Choose active technicians only.',
    'meter_required' => 'Enter the hour meter reading or why it cannot be read.',
    'intake_lower_reading' => 'The receiving reading is lower than the previous one.',
    'tech_lower_reading' => 'The technician reading is lower than the previous one.',
    'handover_amount' => 'The amount due is :due OMR, recorded in full at handover.',
    'hold_reasons' => [
        'awaiting_parts' => 'Awaiting parts', 'awaiting_approval' => 'Awaiting approval', 'awaiting_customer' => 'Awaiting customer',
        'end_of_shift' => 'End of shift', 'other' => 'Other reason',
    ],
    'components' => ['diesel_engine' => 'Diesel engine', 'hydraulic_pump' => 'Hydraulic pump', 'hydraulic_cylinder' => 'Hydraulic cylinder', 'transmission' => 'Transmission', 'other' => 'Other component'],
    'urgency' => ['normal' => 'normal', 'urgent' => 'urgent'],
];
