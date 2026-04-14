<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TicketService;

class RecalculateSla extends Command
{
    protected $signature = 'tickets:recalculate-sla';
    protected $description = 'Recalculate SLA overdue flags for open tickets';

    public function handle(TicketService $ticketService): int
    {
        $updated = $ticketService->recalculateOverdueFlags();
        $this->info("Marked {$updated} tickets as overdue.");
        return Command::SUCCESS;
    }
}
