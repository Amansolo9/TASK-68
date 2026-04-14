<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotReservation extends Model
{
    protected $fillable = [
        'slot_id', 'appointment_id', 'correlation_key',
        'reserved_qty', 'expires_at', 'status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'reserved_qty' => 'integer',
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'slot_id');
    }
}
