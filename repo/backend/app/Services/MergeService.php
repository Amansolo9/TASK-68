<?php

namespace App\Services;

use App\Models\MergeRequest;
use App\Models\Personnel;
use App\Models\Organization;
use App\Models\DuplicateCandidate;
use Illuminate\Support\Facades\DB;

class MergeService
{
    public function __construct(
        private AuditService $auditService,
        private MasterDataVersionService $versionService,
    ) {}

    /**
     * Create a merge request.
     */
    public function createRequest(string $entityType, array $sourceIds, int $targetId, int $requestedBy, string $reason): MergeRequest
    {
        return MergeRequest::create([
            'entity_type' => $entityType,
            'source_entity_ids' => $sourceIds,
            'target_entity_id' => $targetId,
            'requested_by' => $requestedBy,
            'reason' => $reason,
            'status' => 'proposed',
        ]);
    }

    /**
     * Transition merge request state.
     */
    public function transition(MergeRequest $request, string $targetState, int $actorId, ?string $reason = null): MergeRequest
    {
        if (!$request->canTransitionTo($targetState)) {
            throw new \InvalidArgumentException("Cannot transition from '{$request->status}' to '{$targetState}'.");
        }

        $updates = ['status' => $targetState];

        if ($targetState === 'approved') {
            $updates['approved_by'] = $actorId;
            $updates['approved_at'] = now();
        }
        if ($targetState === 'rejected') {
            $updates['rejection_reason'] = $reason;
        }

        $request->update($updates);

        $this->auditService->log(
            'merge_request', (string) $request->id, "merge_{$targetState}",
            $actorId, null, request()?->ip(),
            null, null, ['entity_type' => $request->entity_type, 'reason' => $reason]
        );

        return $request->fresh();
    }

    /**
     * Execute an approved merge.
     * Sources are retired/linked to target. No physical destruction.
     */
    public function executeMerge(MergeRequest $mergeRequest, int $actorId): MergeRequest
    {
        if ($mergeRequest->status !== 'approved') {
            throw new \InvalidArgumentException('Merge can only be executed when approved.');
        }

        return DB::transaction(function () use ($mergeRequest, $actorId) {
            $entityType = $mergeRequest->entity_type;
            $sourceIds = $mergeRequest->source_entity_ids;
            $targetId = $mergeRequest->target_entity_id;

            foreach ($sourceIds as $sourceId) {
                if ($entityType === 'personnel') {
                    $source = Personnel::findOrFail($sourceId);
                    $beforeSnapshot = $source->toArray();
                    $source->update(['status' => 'retired', 'merged_into_id' => $targetId]);
                    $this->versionService->trackUpdate($source, $beforeSnapshot, 'personnel', $actorId, "Merged into #{$targetId}");
                } elseif ($entityType === 'organization') {
                    $source = Organization::findOrFail($sourceId);
                    $beforeSnapshot = $source->toArray();
                    $source->update(['status' => 'retired', 'merged_into_id' => $targetId]);
                    $this->versionService->trackUpdate($source, $beforeSnapshot, 'organization', $actorId, "Merged into #{$targetId}");
                }

                // Update duplicate candidates
                DuplicateCandidate::where('entity_type', $entityType)
                    ->where(function ($q) use ($sourceId) {
                        $q->where('left_entity_id', $sourceId)->orWhere('right_entity_id', $sourceId);
                    })
                    ->where('status', 'pending')
                    ->update(['status' => 'merged']);
            }

            $mergeRequest->update([
                'status' => 'executed',
                'merge_metadata' => [
                    'executed_by' => $actorId,
                    'executed_at' => now()->toIso8601String(),
                    'sources_retired' => $sourceIds,
                ],
            ]);

            $this->auditService->log(
                'merge_request', (string) $mergeRequest->id, 'merge_executed',
                $actorId, null, request()?->ip(),
                null, null, ['target' => $targetId, 'sources' => $sourceIds]
            );

            return $mergeRequest->fresh();
        });
    }
}
