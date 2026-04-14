<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],
        'attachments' => [
            'driver' => 'local',
            'root' => storage_path('app/attachments'),
        ],
        'artifacts' => [
            'driver' => 'local',
            'root' => storage_path('app/artifacts'),
        ],
        'quarantine' => [
            'driver' => 'local',
            'root' => storage_path('app/quarantine'),
        ],
        'exports' => [
            'driver' => 'local',
            'root' => storage_path('app/exports'),
        ],
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
        ],
    ],
];
