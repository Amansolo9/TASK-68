<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsultationTicket;
use App\Models\Appointment;
use App\Models\AdmissionsPlanVersion;
use App\Models\DataQualityRun;
use App\Models\MergeRequest;
use App\Services\DataQualityService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportingController extends Controller
{
    use HasApiResponse;

    public function __construct(private DataQualityService $dqService) {}

    public function ticketStats(Request $request): JsonResponse
    {
        $stats = [
            'total' => ConsultationTicket::count(),
            'by_status' => ConsultationTicket::select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->pluck('count', 'status'),
            'by_priority' => ConsultationTicket::select('priority', DB::raw('COUNT(*) as count'))->groupBy('priority')->pluck('count', 'priority'),
            'by_category' => ConsultationTicket::select('category_tag', DB::raw('COUNT(*) as count'))->groupBy('category_tag')->pluck('count', 'category_tag'),
            'overdue_count' => ConsultationTicket::where('overdue_flag', true)->whereNotIn('status', ['closed', 'auto_closed'])->count(),
            'sla_attainment' => $this->computeSlaAttainment(),
        ];

        return $this->success($stats);
    }

    public function appointmentStats(Request $request): JsonResponse
    {
        $total = Appointment::count();
        $booked = Appointment::where('state', 'booked')->count();
        $completed = Appointment::where('state', 'completed')->count();
        $noShow = Appointment::where('state', 'no_show')->count();
        $cancelled = Appointment::where('state', 'cancelled')->count();

        return $this->success([
            'total' => $total,
            'booked' => $booked,
            'completed' => $completed,
            'no_show' => $noShow,
            'cancelled' => $cancelled,
            'no_show_rate' => $total > 0 ? round($noShow / max(1, $booked + $completed + $noShow), 4) : 0,
        ]);
    }

    public function planStats(Request $request): JsonResponse
    {
        return $this->success([
            'total_versions' => AdmissionsPlanVersion::count(),
            'by_state' => AdmissionsPlanVersion::select('state', DB::raw('COUNT(*) as count'))->groupBy('state')->pluck('count', 'state'),
            'published_count' => AdmissionsPlanVersion::where('state', 'published')->count(),
        ]);
    }

    public function dataQualityMetrics(Request $request): JsonResponse
    {
        $latestRun = DataQualityRun::where('status', 'completed')->orderByDesc('run_date')->first();

        if (!$latestRun) {
            return $this->success(['message' => 'No completed quality runs found.', 'metrics' => []]);
        }

        return $this->success([
            'run_date' => $latestRun->run_date->toDateString(),
            'metrics' => $latestRun->metrics()->get(),
        ]);
    }

    public function dataQualityTrend(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => 'required|string',
            'metric_name' => 'required|string',
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        $trend = $this->dqService->getTrend(
            $request->input('entity_type'),
            $request->input('metric_name'),
            $request->input('days', 30)
        );

        return $this->success($trend);
    }

    public function mergeStats(Request $request): JsonResponse
    {
        return $this->success([
            'total' => MergeRequest::count(),
            'by_status' => MergeRequest::select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->pluck('count', 'status'),
        ]);
    }

    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate([
            'report_type' => 'required|in:tickets,appointments,plans,data_quality',
        ]);

        $user = Auth::user();
        $reportType = $request->input('report_type');

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$reportType}_export_" . date('Ymd_His') . ".csv",
        ];

        $callback = function () use ($reportType, $user) {
            $handle = fopen('php://output', 'w');

            switch ($reportType) {
                case 'tickets':
                    fputcsv($handle, ['Ticket #', 'Category', 'Priority', 'Status', 'Overdue', 'Created']);
                    ConsultationTicket::orderBy('created_at', 'desc')->chunk(500, function ($tickets) use ($handle) {
                        foreach ($tickets as $t) {
                            fputcsv($handle, [$t->local_ticket_no, $t->category_tag, $t->priority, $t->status, $t->overdue_flag ? 'Yes' : 'No', $t->created_at]);
                        }
                    });
                    break;

                case 'appointments':
                    fputcsv($handle, ['ID', 'Applicant ID', 'Slot ID', 'Type', 'State', 'Booked At']);
                    Appointment::orderBy('created_at', 'desc')->chunk(500, function ($appts) use ($handle) {
                        foreach ($appts as $a) {
                            fputcsv($handle, [$a->id, $a->applicant_id, $a->slot_id, $a->booking_type, $a->state, $a->booked_at]);
                        }
                    });
                    break;

                case 'plans':
                    fputcsv($handle, ['Plan ID', 'Academic Year', 'Intake Batch', 'Version', 'State', 'Effective Date', 'Updated At']);
                    AdmissionsPlanVersion::with('plan')->orderByDesc('updated_at')->chunk(500, function ($versions) use ($handle) {
                        foreach ($versions as $v) {
                            fputcsv($handle, [
                                $v->plan_id,
                                $v->plan?->academic_year ?? '',
                                $v->plan?->intake_batch ?? '',
                                $v->version_number ?? $v->id,
                                $v->state,
                                $v->effective_date ?? '',
                                $v->updated_at,
                            ]);
                        }
                    });
                    break;

                case 'data_quality':
                    fputcsv($handle, ['Run Date', 'Entity', 'Metric', 'Value', 'Numerator', 'Denominator']);
                    DataQualityRun::where('status', 'completed')->with('metrics')->orderByDesc('run_date')
                        ->limit(30)->get()->each(function ($run) use ($handle) {
                            foreach ($run->metrics as $m) {
                                fputcsv($handle, [$run->run_date->toDateString(), $m->entity_type, $m->metric_name, $m->metric_value, $m->numerator, $m->denominator]);
                            }
                        });
                    break;
            }

            fclose($handle);
        };

        // Log export
        app(\App\Services\AuditService::class)->log(
            'export', $reportType, 'report_exported',
            $user->id, null, $request->ip(),
            null, null, ['report_type' => $reportType]
        );

        return response()->stream($callback, 200, $headers);
    }

    private function computeSlaAttainment(): float
    {
        $requiresSla = ConsultationTicket::whereNotNull('first_response_due_at')->count();
        if ($requiresSla === 0) return 1.0;

        $met = ConsultationTicket::whereNotNull('first_response_due_at')
            ->whereNotNull('first_responded_at')
            ->whereColumn('first_responded_at', '<=', 'first_response_due_at')
            ->count();

        return round($met / $requiresSla, 4);
    }
}
