<?php

namespace App\Services;

use App\Models\DuplicateCandidate;
use App\Models\Personnel;
use App\Models\Organization;

class DuplicateDetectionService
{
    public function __construct(private EncryptionService $encryptionService) {}

    /**
     * Detect duplicate personnel by:
     *   1. normalized name + DOB (decrypted for comparison)
     *   2. employee ID exact match
     *   3. normalized name alone (lower confidence)
     */
    public function detectPersonnelDuplicates(): array
    {
        $candidates = [];

        // Load active personnel with encrypted DOB for comparison
        $active = Personnel::where('status', 'active')->get();

        // Build a lookup of decrypted DOBs (keyed by personnel id)
        $dobMap = [];
        foreach ($active as $person) {
            if ($person->encrypted_date_of_birth) {
                try {
                    $dobMap[$person->id] = $this->encryptionService->decrypt($person->encrypted_date_of_birth);
                } catch (\Throwable $e) {
                    // Skip records with corrupted DOB
                }
            }
        }

        // Method 1: Normalized name + DOB match (highest confidence for name-based)
        $nameGroups = $active->groupBy('normalized_name');
        foreach ($nameGroups as $name => $group) {
            if ($group->count() < 2) continue;

            $records = $group->values();
            for ($i = 0; $i < count($records); $i++) {
                for ($j = $i + 1; $j < count($records); $j++) {
                    $leftId = $records[$i]->id;
                    $rightId = $records[$j]->id;
                    $leftDob = $dobMap[$leftId] ?? null;
                    $rightDob = $dobMap[$rightId] ?? null;

                    if ($leftDob && $rightDob && $leftDob === $rightDob) {
                        // Name + DOB match: high confidence
                        $candidates[] = $this->createCandidate(
                            'personnel', $leftId, $rightId,
                            'normalized_name_and_dob_match', 0.95
                        );
                    } else {
                        // Name match only (no DOB or DOB differs): lower confidence
                        $candidates[] = $this->createCandidate(
                            'personnel', $leftId, $rightId,
                            'normalized_name_match', 0.70
                        );
                    }
                }
            }
        }

        // Method 2: Employee ID exact match
        $empIdGroups = $active->filter(fn ($p) => $p->employee_id !== null)->groupBy('employee_id');
        foreach ($empIdGroups as $empId => $group) {
            if ($group->count() < 2) continue;
            $records = $group->values();
            for ($i = 0; $i < count($records); $i++) {
                for ($j = $i + 1; $j < count($records); $j++) {
                    $candidates[] = $this->createCandidate(
                        'personnel', $records[$i]->id, $records[$j]->id,
                        'employee_id_exact_match', 0.99
                    );
                }
            }
        }

        return $candidates;
    }

    /**
     * Detect duplicate organizations by normalized name.
     */
    public function detectOrganizationDuplicates(): array
    {
        $candidates = [];
        $nameGroups = Organization::where('status', 'active')
            ->select('normalized_name')
            ->groupBy('normalized_name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('normalized_name');

        foreach ($nameGroups as $name) {
            $records = Organization::where('normalized_name', $name)->where('status', 'active')->get();
            for ($i = 0; $i < count($records); $i++) {
                for ($j = $i + 1; $j < count($records); $j++) {
                    $candidates[] = $this->createCandidate(
                        'organization', $records[$i]->id, $records[$j]->id,
                        'normalized_name_match', 0.85
                    );
                }
            }
        }

        return $candidates;
    }

    private function createCandidate(string $type, int $leftId, int $rightId, string $basis, float $confidence): DuplicateCandidate
    {
        [$l, $r] = $leftId < $rightId ? [$leftId, $rightId] : [$rightId, $leftId];

        // If a candidate already exists, update confidence if the new basis is higher
        $existing = DuplicateCandidate::where('entity_type', $type)
            ->where('left_entity_id', $l)
            ->where('right_entity_id', $r)
            ->first();

        if ($existing) {
            if ($confidence > $existing->confidence) {
                $existing->update(['detection_basis' => $basis, 'confidence' => $confidence]);
            }
            return $existing;
        }

        return DuplicateCandidate::create([
            'entity_type' => $type,
            'left_entity_id' => $l,
            'right_entity_id' => $r,
            'detection_basis' => $basis,
            'confidence' => $confidence,
            'status' => 'pending',
        ]);
    }
}
