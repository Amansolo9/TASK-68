<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AdmissionsPlanVersion;
use App\Services\PlanVersionService;

class VerifyArtifactIntegrity extends Command
{
    protected $signature = 'artifacts:verify-integrity';
    protected $description = 'Verify integrity of published artifacts';

    public function handle(PlanVersionService $planService): int
    {
        $published = AdmissionsPlanVersion::whereIn('state', ['published', 'superseded'])
            ->whereNotNull('snapshot_hash')
            ->get();

        $compromised = 0;
        foreach ($published as $version) {
            $result = $planService->verifyIntegrity($version);
            if (!$result['valid']) {
                $this->error("COMPROMISED: Plan version #{$version->id} (v{$version->version_no})");
                $compromised++;
            }
        }

        $this->info("Verified " . count($published) . " artifacts. Compromised: {$compromised}.");
        return $compromised > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
