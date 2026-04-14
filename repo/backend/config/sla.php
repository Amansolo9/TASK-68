<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Business Hours Configuration
    |--------------------------------------------------------------------------
    */
    'business_hours_start' => env('BUSINESS_HOURS_START', '08:00'),
    'business_hours_end' => env('BUSINESS_HOURS_END', '17:00'),

    // Comma-separated ISO day numbers: 1=Mon, 2=Tue, ..., 7=Sun
    'business_days' => env('BUSINESS_DAYS', '1,2,3,4,5'),

    /*
    |--------------------------------------------------------------------------
    | First-Response SLA Targets
    |--------------------------------------------------------------------------
    |
    | High priority: measured in business hours (default 2).
    | Normal priority: measured in business days (default 1).
    |
    */
    'high_priority_hours' => (int) env('SLA_HIGH_PRIORITY_HOURS', 2),
    'normal_priority_days' => (int) env('SLA_NORMAL_PRIORITY_DAYS', 1),
];
