<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'actor_user_id',
        'actor_role',
        'entity_type',
        'entity_id',
        'event_type',
        'ip_address',
        'before_hash',
        'after_hash',
        'metadata',
        'chain_hash',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // Audit log entries are immutable - prevent updates and deletes
    public static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Audit log entries are immutable and cannot be deleted.');
        });
    }
}
