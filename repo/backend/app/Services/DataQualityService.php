<?php

namespace App\Services;

use App\Models\DataQualityRun;
use App\Models\DataQualityMetric;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\DuplicateCandidate;
use Illuminate\Support\Facades\DB;

class DataQualityService
{
    /**
     * Compute nightly data quality metrics.
     * Metrics: completeness, consistency, uniqueness, timeliness.
     * Failed runs do not overwrite prior successful results.
     */
    public function computeNightlyMetrics(): DataQualityRun
    {
        $today = now()->toDateString();

        // Check if a run exists for today (any status)
        $existing = DataQualityRun::whereDate('run_date', $today)->first();
        if ($existing) {
            if ($existing->status === 'completed') {
                return $existing;
            }
            // Re-run a failed/running attempt
            $existing->update(['started_at' => now(), 'status' => 'running', 'error_message' => null]);
            $run = $existing;
        } else {
            $run = DataQualityRun::create([
                'run_date' => $today,
                'started_at' => now(),
                'status' => 'running',
            ]);
        }

        try {
            // Personnel metrics
            $this->computeEntityMetrics($run, 'personnel');

            // Organization metrics
            $this->computeEntityMetrics($run, 'organization');

            $run->update(['completed_at' => now(), 'status' => 'completed']);
        } catch (\Throwable $e) {
            $run->update([
                'completed_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    private function computeEntityMetrics(DataQualityRun $run, string $entityType): void
    {
        if ($entityType === 'personnel') {
            $this->computePersonnelMetrics($run);
        } elseif ($entityType === 'organization') {
            $this->computeOrganizationMetrics($run);
        }
    }

    private function computePersonnelMetrics(DataQualityRun $run): void
    {
        $total = Personnel::where('status', 'active')->count();
        if ($total === 0) {
            $this->storeMetric($run, 'personnel', 'completeness', 1.0, 0, 0);
            $this->storeMetric($run, 'personnel', 'consistency', 1.0, 0, 0);
            $this->storeMetric($run, 'personnel', 'uniqueness', 1.0, 0, 0);
            $this->storeMetric($run, 'personnel', 'timeliness', 1.0, 0, 0);
            return;
        }

        // Completeness: populated required fields / total required fields
        $requiredFields = ['full_name', 'normalized_name'];
        $totalFields = $total * count($requiredFields);
        $populated = 0;
        foreach ($requiredFields as $field) {
            $populated += Personnel::where('status', 'active')->whereNotNull($field)->where($field, '!=', '')->count();
        }
        $this->storeMetric($run, 'personnel', 'completeness', $totalFields > 0 ? $populated / $totalFields : 1.0, $populated, $totalFields);

        // Consistency: records with valid organization reference / total
        $consistent = Personnel::where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('organization_id')
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))->from('organizations')->whereColumn('organizations.id', 'personnel.organization_id');
                    });
            })->count();
        $this->storeMetric($run, 'personnel', 'consistency', $total > 0 ? $consistent / $total : 1.0, $consistent, $total);

        // Uniqueness: non-duplicate surviving / total
        $duplicateCount = DuplicateCandidate::where('entity_type', 'personnel')
            ->whereIn('status', ['pending', 'confirmed'])
            ->count();
        $unique = $total - $duplicateCount;
        $this->storeMetric($run, 'personnel', 'uniqueness', $total > 0 ? max(0, $unique) / $total : 1.0, max(0, $unique), $total);

        // Timeliness: records updated within 90 days
        $timely = Personnel::where('status', 'active')
            ->where('updated_at', '>=', now()->subDays(90))
            ->count();
        $this->storeMetric($run, 'personnel', 'timeliness', $total > 0 ? $timely / $total : 1.0, $timely, $total);
    }

    private function computeOrganizationMetrics(DataQualityRun $run): void
    {
        $total = Organization::where('status', 'active')->count();
        if ($total === 0) {
            $this->storeMetric($run, 'organization', 'completeness', 1.0, 0, 0);
            $this->storeMetric($run, 'organization', 'consistency', 1.0, 0, 0);
            $this->storeMetric($run, 'organization', 'uniqueness', 1.0, 0, 0);
            $this->storeMetric($run, 'organization', 'timeliness', 1.0, 0, 0);
            return;
        }

        $requiredFields = ['name', 'normalized_name', 'code'];
        $totalFields = $total * count($requiredFields);
        $populated = 0;
        foreach ($requiredFields as $field) {
            $populated += Organization::where('status', 'active')->whereNotNull($field)->where($field, '!=', '')->count();
        }
        $this->storeMetric($run, 'organization', 'completeness', $totalFields > 0 ? $populated / $totalFields : 1.0, $populated, $totalFields);

        // Consistency: valid code format (ORG-XXXXXX)
        $consistent = Organization::where('status', 'active')
            ->where('code', 'LIKE', 'ORG-______')
            ->where('code', 'NOT LIKE', 'ORG-%[^0-9]%')
            ->count();
        $this->storeMetric($run, 'organization', 'consistency', $total > 0 ? $consistent / $total : 1.0, $consistent, $total);

        $duplicateCount = DuplicateCandidate::where('entity_type', 'organization')
            ->whereIn('status', ['pending', 'confirmed'])
            ->count();
        $unique = $total - $duplicateCount;
        $this->storeMetric($run, 'organization', 'uniqueness', $total > 0 ? max(0, $unique) / $total : 1.0, max(0, $unique), $total);

        $timely = Organization::where('status', 'active')
            ->where('updated_at', '>=', now()->subDays(90))
            ->count();
        $this->storeMetric($run, 'organization', 'timeliness', $total > 0 ? $timely / $total : 1.0, $timely, $total);
    }

    private function storeMetric(DataQualityRun $run, string $entityType, string $name, float $value, int $numerator, int $denominator): void
    {
        DataQualityMetric::create([
            'run_id' => $run->id,
            'entity_type' => $entityType,
            'metric_name' => $name,
            'metric_value' => round($value, 4),
            'numerator' => $numerator,
            'denominator' => $denominator,
        ]);
    }

    /**
     * Get trend data for a metric.
     */
    public function getTrend(string $entityType, string $metricName, int $days = 30): array
    {
        return DataQualityMetric::join('data_quality_runs', 'data_quality_metrics.run_id', '=', 'data_quality_runs.id')
            ->where('data_quality_runs.status', 'completed')
            ->where('data_quality_metrics.entity_type', $entityType)
            ->where('data_quality_metrics.metric_name', $metricName)
            ->where('data_quality_runs.run_date', '>=', now()->subDays($days))
            ->orderBy('data_quality_runs.run_date')
            ->select('data_quality_runs.run_date', 'data_quality_metrics.metric_value', 'data_quality_metrics.numerator', 'data_quality_metrics.denominator')
            ->get()
            ->toArray();
    }
}
