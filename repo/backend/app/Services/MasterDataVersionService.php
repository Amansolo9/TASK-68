<?php

namespace App\Services;

use App\Models\MasterDataVersion;
use Illuminate\Database\Eloquent\Model;

class MasterDataVersionService
{
    public function __construct(private AuditService $auditService) {}

    /**
     * Record a version snapshot for a master data entity change.
     */
    public function recordVersion(
        string $entityType,
        int $entityId,
        ?array $beforeSnapshot,
        array $afterSnapshot,
        int $actorUserId,
        ?string $changeReason = null
    ): MasterDataVersion {
        $lastVersion = MasterDataVersion::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('version_no', 'desc')
            ->first();

        $versionNo = $lastVersion ? $lastVersion->version_no + 1 : 1;
        $beforeHash = $beforeSnapshot ? $this->auditService->computeEntityHash($beforeSnapshot) : null;
        $afterHash = $this->auditService->computeEntityHash($afterSnapshot);

        return MasterDataVersion::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'version_no' => $versionNo,
            'before_snapshot' => $beforeSnapshot,
            'after_snapshot' => $afterSnapshot,
            'before_hash' => $beforeHash,
            'after_hash' => $afterHash,
            'actor_user_id' => $actorUserId,
            'change_reason' => $changeReason,
            'created_at' => now(),
        ]);
    }

    /**
     * Get version history for an entity.
     */
    public function getHistory(string $entityType, int $entityId): \Illuminate\Database\Eloquent\Collection
    {
        return MasterDataVersion::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('version_no', 'asc')
            ->get();
    }

    /**
     * Track a model create operation.
     */
    public function trackCreate(Model $model, string $entityType, int $actorUserId, ?string $reason = null): MasterDataVersion
    {
        return $this->recordVersion(
            $entityType,
            $model->id,
            null,
            $model->toArray(),
            $actorUserId,
            $reason ?? 'Created'
        );
    }

    /**
     * Track a model update operation.
     */
    public function trackUpdate(Model $model, array $beforeSnapshot, string $entityType, int $actorUserId, ?string $reason = null): MasterDataVersion
    {
        return $this->recordVersion(
            $entityType,
            $model->id,
            $beforeSnapshot,
            $model->fresh()->toArray(),
            $actorUserId,
            $reason
        );
    }

    /**
     * Track a soft delete operation.
     */
    public function trackSoftDelete(Model $model, string $entityType, int $actorUserId, ?string $reason = null): MasterDataVersion
    {
        return $this->recordVersion(
            $entityType,
            $model->id,
            $model->toArray(),
            array_merge($model->toArray(), ['deleted_at' => now()->toIso8601String()]),
            $actorUserId,
            $reason ?? 'Soft deleted'
        );
    }
}
