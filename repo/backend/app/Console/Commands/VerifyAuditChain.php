<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AuditService;

class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify-chain {--from=0 : Start verification from this audit log ID}';
    protected $description = 'Verify the tamper-evident audit log chain integrity';

    public function handle(AuditService $auditService): int
    {
        $fromId = (int) $this->option('from');
        $this->info("Verifying audit chain from ID {$fromId}…");

        $result = $auditService->verifyChain($fromId);

        if ($result['valid']) {
            $this->info("VALID — {$result['checked']} entries verified, chain intact.");
            return Command::SUCCESS;
        }

        $this->error("BROKEN at entry #{$result['broken_at']} — {$result['checked']} entries checked before failure.");
        return Command::FAILURE;
    }
}
