<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketQualityReview extends Model
{
    protected $fillable = [
        'sampled_week', 'advisor_id', 'ticket_id',
        'reviewer_manager_id', 'locked_at', 'review_state', 'score', 'notes',
    ];

    protected $casts = [
        'locked_at' => 'datetime',
        'score' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ConsultationTicket::class, 'ticket_id');
    }

    public function isLocked(): bool
    {
        return !is_null($this->locked_at);
    }
}
