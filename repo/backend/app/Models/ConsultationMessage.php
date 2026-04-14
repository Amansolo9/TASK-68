<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ticket_id', 'sender_user_id', 'message_text', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Messages are append-only
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Consultation messages are append-only and cannot be modified.');
        });
        static::deleting(function () {
            throw new \RuntimeException('Consultation messages cannot be deleted.');
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ConsultationTicket::class, 'ticket_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
