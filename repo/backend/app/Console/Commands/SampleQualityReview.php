<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TicketService;

class SampleQualityReview extends Command
{
    protected $signature = 'tickets:sample-quality-review';
    protected $description = 'Sample 5% of closed tickets per advisor for weekly quality review';

    public function handle(TicketService $ticketService): int
    {
        $weekId = now()->subWeek()->format('Y-W');
        $sampled = $ticketService->sampleQualityReviews($weekId);
        $this->info("Sampled " . count($sampled) . " tickets for quality review (week {$weekId}).");
        return Command::SUCCESS;
    }
}
