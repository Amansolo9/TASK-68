<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionsPlanVersion extends Model
{
    protected $fillable = [
        'plan_id',
        'version_no',
        'state',
        'effective_date',
        'description',
        'notes',
        'created_by',
        'submitted_by',
        'approved_by',
        'approved_at',
        'published_by',
        'published_at',
        'snapshot_hash',
        'artifact_hash',
        'snapshot_data',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'snapshot_data' => 'array',
    ];

    /**
     * Valid state transitions.
     */
    public const STATE_TRANSITIONS = [
        'draft'        => ['submitted', 'archived'],
        'submitted'    => ['under_review', 'returned'],
        'under_review' => ['approved', 'returned', 'rejected'],
        'returned'     => ['draft', 'archived'],
        'approved'     => ['published', 'archived'],
        'published'    => ['superseded'],
        'rejected'     => ['archived', 'draft'],
        'archived'     => [],
        'superseded'   => [],
    ];

    /**
     * States that are immutable (cannot edit programs/tracks).
     */
    public const IMMUTABLE_STATES = ['published', 'superseded', 'archived'];

    /**
     * States where content editing is not allowed.
     */
    public const NON_EDITABLE_STATES = [
        'submitted', 'under_review', 'approved', 'published', 'archived', 'superseded',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AdmissionsPlan::class, 'plan_id');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(AdmissionsPlanProgram::class, 'version_id')->orderBy('sort_order');
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PlanStateHistory::class, 'version_id')->orderBy('transitioned_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function canTransitionTo(string $targetState): bool
    {
        $allowed = self::STATE_TRANSITIONS[$this->state] ?? [];
        return in_array($targetState, $allowed);
    }

    public function isEditable(): bool
    {
        return !in_array($this->state, self::NON_EDITABLE_STATES);
    }

    public function isImmutable(): bool
    {
        return in_array($this->state, self::IMMUTABLE_STATES);
    }

    public function isPublished(): bool
    {
        return $this->state === 'published';
    }
}
