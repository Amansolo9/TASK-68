<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanStateHistory extends Model
{
    public $timestamps = false;

    protected $table = 'plan_state_history';

    protected $fillable = [
        'version_id',
        'from_state',
        'to_state',
        'actor_user_id',
        'actor_role',
        'ip_address',
        'before_hash',
        'after_hash',
        'reason',
        'metadata',
        'transitioned_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'transitioned_at' => 'datetime',
    ];

    // Append-only — prevent updates and deletes
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Plan state history entries are immutable.');
        });
        static::deleting(function () {
            throw new \RuntimeException('Plan state history entries are immutable.');
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AdmissionsPlanVersion::class, 'version_id');
    }
}
