<?php

namespace App\Services;

use App\Models\AdmissionsPlan;
use App\Models\AdmissionsPlanVersion;
use App\Models\AdmissionsPlanProgram;
use App\Models\AdmissionsPlanTrack;
use App\Models\PlanStateHistory;
use App\Models\PublishedArtifactIntegrityCheck;
use Illuminate\Support\Facades\DB;

class PlanVersionService
{
    public function __construct(
        private AuditService $auditService,
    ) {}

    /**
     * Create a new draft version for a plan.
     */
    public function createDraftVersion(
        AdmissionsPlan $plan,
        int $actorUserId,
        ?string $description = null,
        ?int $deriveFromVersionId = null
    ): AdmissionsPlanVersion {
        return DB::transaction(function () use ($plan, $actorUserId, $description, $deriveFromVersionId) {
            $versionNo = $plan->getNextVersionNumber();

            $version = AdmissionsPlanVersion::create([
                'plan_id' => $plan->id,
                'version_no' => $versionNo,
                'state' => 'draft',
                'description' => $description,
                'created_by' => $actorUserId,
            ]);

            // If deriving from a published version, copy programs and tracks
            if ($deriveFromVersionId) {
                $sourceVersion = AdmissionsPlanVersion::with('programs.tracks')
                    ->findOrFail($deriveFromVersionId);
                $this->copyProgramsAndTracks($sourceVersion, $version);
            }

            // Record state history
            $this->recordStateTransition($version, null, 'draft', $actorUserId, null, 'Version created');

            // Update plan's current version pointer
            $plan->update(['current_version_id' => $version->id]);

            // Audit
            $this->auditService->log(
                'admissions_plan_version',
                (string) $version->id,
                'version_created',
                $actorUserId,
                null,
                request()?->ip(),
                null,
                $this->computeVersionHash($version),
                ['plan_id' => $plan->id, 'version_no' => $versionNo, 'derived_from' => $deriveFromVersionId]
            );

            return $version;
        });
    }

    /**
     * Transition a version to a new state with full validation.
     */
    public function transitionState(
        AdmissionsPlanVersion $version,
        string $targetState,
        int $actorUserId,
        ?string $actorRole = null,
        ?string $reason = null,
        ?string $ipAddress = null
    ): AdmissionsPlanVersion {
        return DB::transaction(function () use ($version, $targetState, $actorUserId, $actorRole, $reason, $ipAddress) {
            $fromState = $version->state;

            // Validate transition
            if (!$version->canTransitionTo($targetState)) {
                throw new \InvalidArgumentException(
                    "Cannot transition from '{$fromState}' to '{$targetState}'. " .
                    "Allowed transitions: " . implode(', ', AdmissionsPlanVersion::STATE_TRANSITIONS[$fromState] ?? [])
                );
            }

            // State-specific validations
            $this->validateTransitionRequirements($version, $targetState);

            $beforeHash = $this->computeVersionHash($version);

            // Apply transition
            $updates = ['state' => $targetState];

            switch ($targetState) {
                case 'submitted':
                    $updates['submitted_by'] = $actorUserId;
                    break;
                case 'approved':
                    $updates['approved_by'] = $actorUserId;
                    $updates['approved_at'] = now();
                    break;
                case 'published':
                    $updates['published_by'] = $actorUserId;
                    $updates['published_at'] = now();
                    // Compute and store snapshot
                    $snapshot = $this->buildSnapshot($version);
                    $updates['snapshot_data'] = $snapshot;
                    $updates['snapshot_hash'] = $this->auditService->computeEntityHash($snapshot);
                    $updates['artifact_hash'] = $updates['snapshot_hash'];
                    break;
                case 'draft':
                    // Returned to draft — clear submission/approval
                    $updates['submitted_by'] = null;
                    $updates['approved_by'] = null;
                    $updates['approved_at'] = null;
                    break;
            }

            $version->update($updates);

            // If publishing, handle superseding and integrity
            if ($targetState === 'published') {
                $this->handlePublish($version);
            }

            $afterHash = $this->computeVersionHash($version->fresh());

            // Record state history (append-only)
            $this->recordStateTransition(
                $version, $fromState, $targetState,
                $actorUserId, $actorRole, $reason,
                $beforeHash, $afterHash, $ipAddress
            );

            // Audit log
            $this->auditService->log(
                'admissions_plan_version',
                (string) $version->id,
                "state_transition_{$targetState}",
                $actorUserId,
                $actorRole,
                $ipAddress,
                $beforeHash,
                $afterHash,
                [
                    'plan_id' => $version->plan_id,
                    'from_state' => $fromState,
                    'to_state' => $targetState,
                    'reason' => $reason,
                ]
            );

            return $version->fresh();
        });
    }

    /**
     * Compare two versions and return field-level differences.
     */
    public function compareVersions(
        AdmissionsPlanVersion $left,
        AdmissionsPlanVersion $right
    ): array {
        $leftSnapshot = $this->buildSnapshot($left);
        $rightSnapshot = $this->buildSnapshot($right);

        $differences = [
            'left_version' => ['id' => $left->id, 'version_no' => $left->version_no, 'state' => $left->state],
            'right_version' => ['id' => $right->id, 'version_no' => $right->version_no, 'state' => $right->state],
            'metadata_changes' => [],
            'program_changes' => [],
            'summary' => ['programs_added' => 0, 'programs_removed' => 0, 'programs_modified' => 0, 'tracks_added' => 0, 'tracks_removed' => 0, 'tracks_modified' => 0],
        ];

        // Compare metadata fields
        foreach (['description', 'effective_date', 'notes'] as $field) {
            $leftVal = $left->$field;
            $rightVal = $right->$field;
            if ($leftVal != $rightVal) {
                $differences['metadata_changes'][] = [
                    'field' => $field,
                    'left' => $leftVal,
                    'right' => $rightVal,
                ];
            }
        }

        // Compare programs
        $leftPrograms = collect($leftSnapshot['programs'] ?? [])->keyBy('program_code');
        $rightPrograms = collect($rightSnapshot['programs'] ?? [])->keyBy('program_code');

        $allCodes = $leftPrograms->keys()->merge($rightPrograms->keys())->unique();

        foreach ($allCodes as $code) {
            $leftProg = $leftPrograms->get($code);
            $rightProg = $rightPrograms->get($code);

            if (!$leftProg) {
                $differences['program_changes'][] = ['type' => 'added', 'program_code' => $code, 'right' => $rightProg];
                $differences['summary']['programs_added']++;
                $differences['summary']['tracks_added'] += count($rightProg['tracks'] ?? []);
                continue;
            }

            if (!$rightProg) {
                $differences['program_changes'][] = ['type' => 'removed', 'program_code' => $code, 'left' => $leftProg];
                $differences['summary']['programs_removed']++;
                $differences['summary']['tracks_removed'] += count($leftProg['tracks'] ?? []);
                continue;
            }

            // Compare program fields
            $fieldDiffs = [];
            foreach (['program_name', 'description', 'planned_capacity', 'capacity_notes'] as $field) {
                if (($leftProg[$field] ?? null) != ($rightProg[$field] ?? null)) {
                    $fieldDiffs[] = ['field' => $field, 'left' => $leftProg[$field] ?? null, 'right' => $rightProg[$field] ?? null];
                }
            }

            // Compare tracks within program
            $trackChanges = $this->compareTracksForProgram($leftProg['tracks'] ?? [], $rightProg['tracks'] ?? []);

            if (!empty($fieldDiffs) || !empty($trackChanges['changes'])) {
                $differences['program_changes'][] = [
                    'type' => 'modified',
                    'program_code' => $code,
                    'field_changes' => $fieldDiffs,
                    'track_changes' => $trackChanges['changes'],
                ];
                if (!empty($fieldDiffs)) $differences['summary']['programs_modified']++;
                $differences['summary']['tracks_added'] += $trackChanges['added'];
                $differences['summary']['tracks_removed'] += $trackChanges['removed'];
                $differences['summary']['tracks_modified'] += $trackChanges['modified'];
            }
        }

        return $differences;
    }

    /**
     * Verify integrity of a published version's artifact hash.
     */
    public function verifyIntegrity(AdmissionsPlanVersion $version): array
    {
        if (!$version->isPublished() && $version->state !== 'superseded') {
            return ['valid' => false, 'error' => 'Version is not published.'];
        }

        $currentSnapshot = $this->buildSnapshot($version);
        $currentHash = $this->auditService->computeEntityHash($currentSnapshot);

        $isValid = hash_equals($version->snapshot_hash ?? '', $currentHash);

        // Update or create integrity check record
        PublishedArtifactIntegrityCheck::updateOrCreate(
            ['artifact_type' => 'admissions_plan_version', 'artifact_id' => $version->id],
            [
                'expected_hash' => $version->snapshot_hash,
                'last_verified_hash' => $currentHash,
                'verified_at' => now(),
                'status' => $isValid ? 'verified' : 'compromised',
            ]
        );

        if (!$isValid) {
            $this->auditService->log(
                'admissions_plan_version',
                (string) $version->id,
                'integrity_check_failed',
                null, null, null,
                $version->snapshot_hash,
                $currentHash,
                ['alert' => 'Published artifact hash mismatch']
            );
        }

        return [
            'valid' => $isValid,
            'expected_hash' => $version->snapshot_hash,
            'computed_hash' => $currentHash,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Derive a new draft from the last published version.
     */
    public function deriveFromPublished(AdmissionsPlan $plan, int $actorUserId, ?string $description = null): AdmissionsPlanVersion
    {
        $published = $plan->publishedVersion();
        if (!$published) {
            throw new \RuntimeException('No published version exists to derive from.');
        }

        return $this->createDraftVersion($plan, $actorUserId, $description, $published->id);
    }

    // --- Private helpers ---

    private function validateTransitionRequirements(AdmissionsPlanVersion $version, string $targetState): void
    {
        if ($targetState === 'published') {
            if (empty($version->effective_date)) {
                throw new \InvalidArgumentException('Effective date is required before publishing.');
            }
            if ($version->state !== 'approved') {
                throw new \InvalidArgumentException('Only approved versions may be published.');
            }
            // handlePublish() will auto-supersede any existing published version
        }
    }

    private function handlePublish(AdmissionsPlanVersion $version): void
    {
        // Supersede any currently published version for the same plan
        $ipAddress = request()?->ip();
        AdmissionsPlanVersion::where('plan_id', $version->plan_id)
            ->where('state', 'published')
            ->where('id', '!=', $version->id)
            ->each(function ($existing) use ($version, $ipAddress) {
                $beforeHash = $this->computeVersionHash($existing);
                $existing->update(['state' => 'superseded']);
                $afterHash = $this->computeVersionHash($existing->fresh());

                // Full audit-compliant supersede transition (Fix 6)
                $this->recordStateTransition(
                    $existing, 'published', 'superseded',
                    $version->published_by, null,
                    "Superseded by version #{$version->version_no}",
                    $beforeHash, $afterHash, $ipAddress
                );

                $this->auditService->log(
                    'admissions_plan_version', (string) $existing->id,
                    'state_transition_superseded',
                    $version->published_by, null, $ipAddress,
                    $beforeHash, $afterHash,
                    ['superseded_by' => $version->id]
                );
            });

        // Update plan's current version pointer
        $version->plan->update(['current_version_id' => $version->id]);

        // Create integrity check record
        PublishedArtifactIntegrityCheck::create([
            'artifact_type' => 'admissions_plan_version',
            'artifact_id' => $version->id,
            'expected_hash' => $version->snapshot_hash,
            'status' => 'pending',
        ]);
    }

    private function buildSnapshot(AdmissionsPlanVersion $version): array
    {
        $version->load('programs.tracks');

        return [
            'version_id' => $version->id,
            'version_no' => $version->version_no,
            'effective_date' => $version->effective_date?->toDateString(),
            'description' => $version->description,
            'notes' => $version->notes,
            'programs' => $version->programs->map(function ($program) {
                return [
                    'program_code' => $program->program_code,
                    'program_name' => $program->program_name,
                    'description' => $program->description,
                    'planned_capacity' => $program->planned_capacity,
                    'capacity_notes' => $program->capacity_notes,
                    'tracks' => $program->tracks->map(function ($track) {
                        return [
                            'track_code' => $track->track_code,
                            'track_name' => $track->track_name,
                            'description' => $track->description,
                            'planned_capacity' => $track->planned_capacity,
                            'capacity_notes' => $track->capacity_notes,
                            'admission_criteria' => $track->admission_criteria,
                        ];
                    })->toArray(),
                ];
            })->toArray(),
        ];
    }

    private function copyProgramsAndTracks(AdmissionsPlanVersion $source, AdmissionsPlanVersion $target): void
    {
        foreach ($source->programs as $program) {
            $newProgram = AdmissionsPlanProgram::create([
                'version_id' => $target->id,
                'program_code' => $program->program_code,
                'program_name' => $program->program_name,
                'description' => $program->description,
                'planned_capacity' => $program->planned_capacity,
                'capacity_notes' => $program->capacity_notes,
                'sort_order' => $program->sort_order,
            ]);

            foreach ($program->tracks as $track) {
                AdmissionsPlanTrack::create([
                    'program_id' => $newProgram->id,
                    'track_code' => $track->track_code,
                    'track_name' => $track->track_name,
                    'description' => $track->description,
                    'planned_capacity' => $track->planned_capacity,
                    'capacity_notes' => $track->capacity_notes,
                    'admission_criteria' => $track->admission_criteria,
                    'sort_order' => $track->sort_order,
                ]);
            }
        }
    }

    private function compareTracksForProgram(array $leftTracks, array $rightTracks): array
    {
        $leftByCode = collect($leftTracks)->keyBy('track_code');
        $rightByCode = collect($rightTracks)->keyBy('track_code');
        $allCodes = $leftByCode->keys()->merge($rightByCode->keys())->unique();

        $changes = [];
        $added = 0;
        $removed = 0;
        $modified = 0;

        foreach ($allCodes as $code) {
            $left = $leftByCode->get($code);
            $right = $rightByCode->get($code);

            if (!$left) {
                $changes[] = ['type' => 'added', 'track_code' => $code, 'right' => $right];
                $added++;
            } elseif (!$right) {
                $changes[] = ['type' => 'removed', 'track_code' => $code, 'left' => $left];
                $removed++;
            } else {
                $fieldDiffs = [];
                foreach (['track_name', 'description', 'planned_capacity', 'capacity_notes', 'admission_criteria'] as $field) {
                    if (($left[$field] ?? null) != ($right[$field] ?? null)) {
                        $fieldDiffs[] = ['field' => $field, 'left' => $left[$field] ?? null, 'right' => $right[$field] ?? null];
                    }
                }
                if (!empty($fieldDiffs)) {
                    $changes[] = ['type' => 'modified', 'track_code' => $code, 'field_changes' => $fieldDiffs];
                    $modified++;
                }
            }
        }

        return ['changes' => $changes, 'added' => $added, 'removed' => $removed, 'modified' => $modified];
    }

    private function recordStateTransition(
        AdmissionsPlanVersion $version,
        ?string $fromState,
        string $toState,
        int $actorUserId,
        ?string $actorRole = null,
        ?string $reason = null,
        ?string $beforeHash = null,
        ?string $afterHash = null,
        ?string $ipAddress = null
    ): void {
        PlanStateHistory::create([
            'version_id' => $version->id,
            'from_state' => $fromState,
            'to_state' => $toState,
            'actor_user_id' => $actorUserId,
            'actor_role' => $actorRole,
            'ip_address' => $ipAddress ?? request()?->ip(),
            'before_hash' => $beforeHash,
            'after_hash' => $afterHash,
            'reason' => $reason,
            'transitioned_at' => now(),
        ]);
    }

    private function computeVersionHash(AdmissionsPlanVersion $version): string
    {
        return $this->auditService->computeEntityHash($version->toArray());
    }
}
