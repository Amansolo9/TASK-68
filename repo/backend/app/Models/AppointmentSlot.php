<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentSlot extends Model
{
    protected $fillable = [
        'slot_type', 'department_id', 'advisor_id', 'start_at', 'end_at',
        'capacity', 'available_qty', 'pre_deduct_mode', 'status',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'capacity' => 'integer',
        'available_qty' => 'integer',
        'pre_deduct_mode' => 'boolean',
    ];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'slot_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(SlotReservation::class, 'slot_id');
    }

    public function hasAvailableCapacity(): bool
    {
        return $this->available_qty > 0 && $this->status === 'open';
    }
}
