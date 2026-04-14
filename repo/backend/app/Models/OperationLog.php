<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'correlation_id',
        'user_id',
        'route',
        'method',
        'request_summary',
        'outcome',
        'latency_ms',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'request_summary' => 'array',
        'created_at' => 'datetime',
    ];
}
