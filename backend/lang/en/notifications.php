<?php

return [
    'N-01' => ['title' => 'We received your quote request :number', 'body' => 'We will review your request and send you a quote soon.'],
    'N-01-staff' => ['title' => 'New quote request :number', 'body' => ':customer requested a quote (:type).'],
    'N-02' => ['title' => 'Quote :number', 'body' => 'Your quote for request :rfq is ready: OMR :total, valid until :valid_until.'],
    'N-03' => ['title' => 'Customer replied to quote :number', 'body' => ':customer :action'],
    'N-04' => ['title' => 'Quote :number expires soon', 'body' => 'The quote expires on :valid_until. Review it before then.'],
    'N-17' => ['title' => 'New message on :number', 'body' => ':sender: :preview'],
    'N-19' => [
        'title' => 'Invitation to join :company',
        'body' => ':inviter invited you. Open "Company" in your account to accept. The invitation is valid for 7 days.',
        'sms' => ':inviter invited you. Sign in with this number to accept. The invitation is valid for 7 days.',
    ],
    'N-25' => ['title' => 'We could not quote request :number', 'body' => ':reason'],
    'N-31' => ['title' => 'Quote :number awaits your approval', 'body' => ':member asked you to approve a quote of OMR :total.'],
    'N-33' => ['title' => 'Your data copy is ready', 'body' => 'Download it from "Settings and privacy" in your account within :days days.'],
    'actions' => [
        'accepted' => 'accepted the quote.',
        'rejected' => 'rejected the quote. Reason: :note',
        'revision' => 'asked for changes: :note',
        'renewal' => 'asked to renew the expired quote.',
    ],
    'types' => ['equipment' => 'equipment', 'parts' => 'spare parts', 'service' => 'service'],
];
