<?php

return [
    'encryption_key' => env('ENCRYPTION_KEY'),

    'sensitive_fields' => [
        'date_of_birth',
        'government_id',
        'institutional_id',
        'totp_secret',
    ],

    'masking_rules' => [
        'date_of_birth' => [
            'default' => '**/**/****',
            'full_access_permissions' => ['attachments.view_sensitive'],
        ],
        'government_id' => [
            'default' => '***-**-{{last4}}',
            'full_access_permissions' => ['attachments.view_sensitive'],
        ],
        'institutional_id' => [
            'default' => '****{{last4}}',
            'full_access_permissions' => ['attachments.view_sensitive'],
        ],
    ],

    'captcha' => [
        'enabled' => (bool) env('CAPTCHA_ENABLED', true),
        'length' => 6,
        'width' => 200,
        'height' => 60,
        'expiry_minutes' => 5,
    ],

    'retention' => [
        'audit_months' => (int) env('AUDIT_RETENTION_MONTHS', 24),
    ],
];
