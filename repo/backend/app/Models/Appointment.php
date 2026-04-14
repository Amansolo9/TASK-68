<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    protected $fillable = [
        'applicant_id', 'slot_id', 'booking_type', 'state',
        'request_key', 'request_key_expires_at', 'booked_at',
        'reschedule_reason', 'cancellation_reason', 'no_show_marked_at',
        'override_reason',
    ];

    protected $casts = [
        'request_key_expires_at' => 'datetime',
        'booked_at' => 'datetime',
        'no_show_marked_at' => 'datetime',
    ];

    public const STATE_TRANSITIONS = [
        'pending'     => ['booked', 'expired', 'cancelled'],
        'booked'      => ['rescheduled', 'cancelled', 'completed', 'no_show'],
        'rescheduled' => ['booked', 'cancelled', 'completed', 'no_show'],
        'cancelled'   => [],
        'completed'   => [],
        'no_show'     => [],
        'expired'     => [],
    ];

    public function canTransitionTo(string $state): bool
    {
        return in_array($state, self::STATE_TRANSITIONS[$this->state] ?? []);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'slot_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(AppointmentStateHistory::class, 'appointment_id');
    }
}
