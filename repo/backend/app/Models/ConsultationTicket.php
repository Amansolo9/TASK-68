<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsultationTicket extends Model
{
    protected $fillable = [
        'local_ticket_no', 'applicant_id', 'category_tag', 'priority',
        'department_id', 'advisor_id', 'status', 'first_response_due_at',
        'overdue_flag', 'first_responded_at', 'closed_at', 'initial_message',
    ];

    protected $casts = [
        'overdue_flag' => 'boolean',
        'first_response_due_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public const STATE_TRANSITIONS = [
        'new'               => ['triaged', 'closed'],
        'triaged'           => ['in_progress', 'reassigned', 'closed'],
        'reassigned'        => ['triaged', 'in_progress'],
        'in_progress'       => ['waiting_applicant', 'resolved', 'closed'],
        'waiting_applicant' => ['in_progress', 'auto_closed', 'closed'],
        'resolved'          => ['closed', 'reopened'],
        'reopened'          => ['triaged', 'in_progress'],
        'auto_closed'       => [],
        'closed'            => [],
    ];

    public function canTransitionTo(string $state): bool
    {
        return in_array($state, self::STATE_TRANSITIONS[$this->status] ?? []);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConsultationMessage::class, 'ticket_id')->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ConsultationAttachment::class, 'ticket_id');
    }

    public function routingHistory(): HasMany
    {
        return $this->hasMany(TicketRoutingHistory::class, 'ticket_id')->orderBy('created_at');
    }

    public function qualityReviews(): HasMany
    {
        return $this->hasMany(TicketQualityReview::class, 'ticket_id');
    }

    public function isTranscriptLocked(): bool
    {
        return $this->isQualityLocked();
    }

    /**
     * Whether this ticket is locked for quality review.
     * Locked tickets cannot have transcript edits, status changes, or reassignments
     * until the review is completed by the reviewing manager.
     */
    public function isQualityLocked(): bool
    {
        return $this->qualityReviews()
            ->whereNotNull('locked_at')
            ->where('review_state', '!=', 'completed')
            ->exists();
    }
}
