<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentStateHistory extends Model
{
    public $timestamps = false;
    protected $table = 'appointment_state_history';

    protected $fillable = [
        'appointment_id', 'from_state', 'to_state',
        'actor_user_id', 'ip_address', 'reason', 'metadata', 'transitioned_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'transitioned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('Appointment state history is immutable.'));
        static::deleting(fn () => throw new \RuntimeException('Appointment state history is immutable.'));
    }
}
