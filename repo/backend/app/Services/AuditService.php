<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class AuditService
{
    /**
     * Write an append-only audit log entry with chained hashing.
     */
    public function log(
        string $entityType,
        ?string $entityId,
        string $eventType,
        ?int $actorUserId = null,
        ?string $actorRole = null,
        ?string $ipAddress = null,
        ?string $beforeHash = null,
        ?string $afterHash = null,
        ?array $metadata = null
    ): AuditLog {
        return DB::transaction(function () use (
            $entityType, $entityId, $eventType, $actorUserId,
            $actorRole, $ipAddress, $beforeHash, $afterHash, $metadata
        ) {
            // Get the previous chain hash for tamper-evident chaining
            $previousEntry = AuditLog::orderBy('id', 'desc')->first();
            $previousChainHash = $previousEntry ? $previousEntry->chain_hash : 'genesis';

            // Compute chain hash: H(previous_chain_hash | current_entry_data)
            $chainInput = implode('|', [
                $previousChainHash,
                $actorUserId ?? '',
                $actorRole ?? '',
                $entityType,
                $entityId ?? '',
                $eventType,
                $ipAddress ?? '',
                $beforeHash ?? '',
                $afterHash ?? '',
                json_encode($metadata ?? []),
                now()->toIso8601String(),
            ]);
            $chainHash = hash('sha256', $chainInput);

            return AuditLog::create([
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'event_type' => $eventType,
                'ip_address' => $ipAddress,
                'before_hash' => $beforeHash,
                'after_hash' => $afterHash,
                'metadata' => $metadata,
                'chain_hash' => $chainHash,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Verify the integrity of the audit chain.
     * Returns ['valid' => bool, 'broken_at' => ?int]
     */
    public function verifyChain(int $fromId = 0, int $batchSize = 1000): array
    {
        $previousChainHash = 'genesis';

        if ($fromId > 0) {
            $prev = AuditLog::where('id', '<', $fromId)->orderBy('id', 'desc')->first();
            if ($prev) {
                $previousChainHash = $prev->chain_hash;
            }
        }

        $query = AuditLog::where('id', '>=', $fromId)->orderBy('id', 'asc');
        $checked = 0;

        foreach ($query->lazy($batchSize) as $entry) {
            $chainInput = implode('|', [
                $previousChainHash,
                $entry->actor_user_id ?? '',
                $entry->actor_role ?? '',
                $entry->entity_type,
                $entry->entity_id ?? '',
                $entry->event_type,
                $entry->ip_address ?? '',
                $entry->before_hash ?? '',
                $entry->after_hash ?? '',
                json_encode($entry->metadata ?? []),
                $entry->created_at->toIso8601String(),
            ]);
            $expectedHash = hash('sha256', $chainInput);

            if (!hash_equals($expectedHash, $entry->chain_hash)) {
                return [
                    'valid' => false,
                    'broken_at' => $entry->id,
                    'checked' => $checked,
                ];
            }

            $previousChainHash = $entry->chain_hash;
            $checked++;
        }

        return [
            'valid' => true,
            'broken_at' => null,
            'checked' => $checked,
        ];
    }

    /**
     * Compute a SHA-256 hash of entity data for before/after tracking.
     */
    public function computeEntityHash($data): string
    {
        if (is_array($data) || is_object($data)) {
            $data = json_encode($this->recursiveKsort((array) $data));
        }
        return hash('sha256', $data);
    }

    private function recursiveKsort(array $array): array
    {
        ksort($array);
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->recursiveKsort($value);
            }
        }
        return $array;
    }
}
