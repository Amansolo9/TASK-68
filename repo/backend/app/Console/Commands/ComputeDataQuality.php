<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DataQualityService;

class ComputeDataQuality extends Command
{
    protected $signature = 'metrics:compute-data-quality';
    protected $description = 'Compute nightly data quality metrics';

    public function handle(DataQualityService $service): int
    {
        $run = $service->computeNightlyMetrics();

        if ($run->status === 'completed') {
            $this->info("Data quality metrics computed successfully for {$run->run_date->toDateString()}.");
            $this->info("Metrics: " . $run->metrics()->count());
        } else {
            $this->error("Data quality run failed: {$run->error_message}");
        }

        return $run->status === 'completed' ? Command::SUCCESS : Command::FAILURE;
    }
}
