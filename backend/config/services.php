<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // مزود SMS (FR-INT-02، Q-04). "log" للتطوير فقط
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'sender_id' => env('SMS_SENDER_ID'),
        'log_channel' => env('SMS_LOG_CHANNEL', 'stack'),
    ],

    // فحص البرمجيات الخبيثة للملفات المرفوعة (NFR-11): none في التطوير، و clamav مع خدمة clamd في الإنتاج
    'antivirus' => [
        'driver' => env('ANTIVIRUS_DRIVER', 'none'),
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
