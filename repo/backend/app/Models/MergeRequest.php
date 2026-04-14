<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MergeRequest extends Model
{
    protected $fillable = [
        'entity_type', 'source_entity_ids', 'target_entity_id',
        'requested_by', 'approved_by', 'approved_at', 'status',
        'reason', 'rejection_reason', 'merge_metadata',
    ];

    protected $casts = [
        'source_entity_ids' => 'array',
        'merge_metadata' => 'array',
        'approved_at' => 'datetime',
    ];

    public const STATE_TRANSITIONS = [
        'proposed'     => ['under_review', 'cancelled'],
        'under_review' => ['approved', 'rejected'],
        'approved'     => ['executed'],
        'rejected'     => [],
        'cancelled'    => [],
        'executed'     => [],
    ];

    public function canTransitionTo(string $state): bool
    {
        return in_array($state, self::STATE_TRANSITIONS[$this->status] ?? []);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
