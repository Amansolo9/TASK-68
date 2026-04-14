<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AppointmentService;

class ExpirePendingHolds extends Command
{
    protected $signature = 'appointments:expire-pending-holds';
    protected $description = 'Expire pending appointment holds past their TTL';

    public function handle(AppointmentService $service): int
    {
        $expired = $service->expirePendingHolds();
        $this->info("Expired {$expired} pending holds.");
        return Command::SUCCESS;
    }
}
