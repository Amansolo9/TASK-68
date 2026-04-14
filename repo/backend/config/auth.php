<?php

return [
    'defaults' => [
        'guard' => 'api',
    ],
    'guards' => [
        'api' => [
            'driver' => 'signed-session',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],
    'login' => [
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_window_minutes' => (int) env('LOGIN_LOCKOUT_WINDOW_MINUTES', 15),
        'lockout_duration_minutes' => (int) env('LOGIN_LOCKOUT_DURATION_MINUTES', 30),
    ],
    'session' => [
        'token_secret' => env('SESSION_TOKEN_SECRET'),
        'lifetime_minutes' => (int) env('SESSION_LIFETIME', 120),
    ],
    'mfa' => [
        'issuer' => env('MFA_ISSUER', 'Admissions System'),
        'required_for_admin' => (bool) env('MFA_REQUIRED_FOR_ADMIN', true),
    ],
];
