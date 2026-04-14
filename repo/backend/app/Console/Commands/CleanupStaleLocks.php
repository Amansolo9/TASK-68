<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LockService;

class CleanupStaleLocks extends Command
{
    protected $signature = 'locks:cleanup-stale';
    protected $description = 'Clean up expired distributed locks';

    public function handle(LockService $lockService): int
    {
        $cleaned = $lockService->cleanupExpired();
        $this->info("Cleaned up {$cleaned} stale locks.");
        return Command::SUCCESS;
    }
}
