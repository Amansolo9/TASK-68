<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdmissionsPlan;
use App\Models\AdmissionsPlanVersion;
use App\Models\AdmissionsPlanProgram;
use App\Models\AdmissionsPlanTrack;
use App\Services\PlanVersionService;
use App\Services\AuditService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdmissionsPlanController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private PlanVersionService $planService,
        private AuditService $auditService,
    ) {}

    // ── Internal plan list (managers/staff only – enforced via route middleware) ──

    public function index(Request $request): JsonResponse
    {
        $query = AdmissionsPlan::with('currentVersion');
        if ($request->has('academic_year')) $query->where('academic_year', $request->input('academic_year'));
        if ($request->has('status')) $query->where('status', $request->input('status'));

        return $this->paginated(
            $query->orderBy('academic_year', 'desc')->paginate($request->input('per_page', 20))
        );
    }

    // ── Published plans (all authenticated users including applicants) ──

    public function publishedPlans(Request $request): JsonResponse
    {
        $query = AdmissionsPlan::whereHas('versions', fn ($q) => $q->where('state', 'published'))
            ->with(['versions' => fn ($q) => $q->where('state', 'published')->with('programs.tracks')]);

        if ($request->has('academic_year')) {
            $query->where('academic_year', $request->input('academic_year'));
        }
        if ($request->has('intake_batch')) {
            $query->where('intake_batch', 'like', '%' . $request->input('intake_batch') . '%');
        }

        $plans = $query->orderBy('academic_year', 'desc')
            ->paginate($request->input('per_page', 20));

        return $this->paginated($plans);
    }

    public function publishedPlanDetail(AdmissionsPlan $admissionsPlan): JsonResponse
    {
        $published = $admissionsPlan->versions()->where('state', 'published')->with('programs.tracks')->first();
        if (!$published) {
            return $this->error('NOT_FOUND', 'No published version found for this plan.', [], 404);
        }

        return $this->success([
            'id' => $admissionsPlan->id,
            'academic_year' => $admissionsPlan->academic_year,
            'intake_batch' => $admissionsPlan->intake_batch,
            'published_version' => $published,
        ]);
    }

    // ── Internal plan detail (staff only) ──

    public function show(AdmissionsPlan $admissionsPlan): JsonResponse
    {
        $admissionsPlan->load(['versions' => fn ($q) => $q->orderBy('version_no', 'desc'), 'currentVersion.programs.tracks']);
        return $this->success($admissionsPlan);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'academic_year' => 'required|string|max:20',
            'intake_batch' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        // Check unique constraint before insert to return 422 not 500
        $exists = AdmissionsPlan::where('academic_year', $request->input('academic_year'))
            ->where('intake_batch', $request->input('intake_batch'))
            ->exists();
        if ($exists) {
            return $this->error('DUPLICATE_PLAN', 'A plan for this academic year and intake batch already exists.', [], 422);
        }

        $plan = DB::transaction(function () use ($request) {
            $plan = AdmissionsPlan::create([
                'academic_year' => $request->input('academic_year'),
                'intake_batch' => $request->input('intake_batch'),
            ]);
            $this->planService->createDraftVersion($plan, Auth::id(), $request->input('description'));
            return $plan->fresh()->load('currentVersion.programs.tracks');
        });

        return $this->success($plan, [], 201);
    }

    // ── Version management ──

    public function showVersion(AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id) {
            return $this->error('NOT_FOUND', 'Version does not belong to this plan.', [], 404);
        }
        $version->load(['programs.tracks', 'stateHistory', 'creator', 'approver', 'publisher']);
        return $this->success($version);
    }

    public function createVersion(Request $request, AdmissionsPlan $admissionsPlan): JsonResponse
    {
        $request->validate([
            'description' => 'nullable|string',
            'derive_from_version_id' => 'nullable|integer|exists:admissions_plan_versions,id',
        ]);

        $version = $this->planService->createDraftVersion(
            $admissionsPlan, Auth::id(),
            $request->input('description'),
            $request->input('derive_from_version_id')
        );
        return $this->success($version->load('programs.tracks'), [], 201);
    }

    public function deriveFromPublished(AdmissionsPlan $admissionsPlan, Request $request): JsonResponse
    {
        $request->validate(['description' => 'nullable|string']);
        try {
            $version = $this->planService->deriveFromPublished($admissionsPlan, Auth::id(), $request->input('description'));
            return $this->success($version->load('programs.tracks'), [], 201);
        } catch (\RuntimeException $e) {
            return $this->error('PLAN_ERROR', $e->getMessage(), [], 400);
        }
    }

    public function updateVersion(Request $request, AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id) {
            return $this->error('NOT_FOUND', 'Version does not belong to this plan.', [], 404);
        }
        if (!$version->isEditable()) {
            return $this->error('VERSION_NOT_EDITABLE', "Version in state '{$version->state}' cannot be edited.", [], 409);
        }

        $request->validate(['effective_date' => 'nullable|date', 'description' => 'nullable|string', 'notes' => 'nullable|string']);

        $beforeHash = $this->auditService->computeEntityHash($version->toArray());
        $version->update($request->only(['effective_date', 'description', 'notes']));
        $this->auditService->log(
            'admissions_plan_version', (string) $version->id, 'version_updated',
            Auth::id(), null, $request->ip(), $beforeHash,
            $this->auditService->computeEntityHash($version->fresh()->toArray())
        );

        return $this->success($version->fresh());
    }

    // ── State Transitions with per-transition permission enforcement (Fix 5) ──

    public function transitionState(Request $request, AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id) {
            return $this->error('NOT_FOUND', 'Version does not belong to this plan.', [], 404);
        }

        $request->validate([
            'target_state' => 'required|string|in:submitted,under_review,approved,published,returned,rejected,archived,draft',
            'reason' => 'nullable|string|max:1000',
        ]);

        $targetState = $request->input('target_state');
        $user = Auth::user();

        // Enforce per-transition permission
        $requiredPermission = config("permissions.plan_transition_permissions.{$targetState}");
        if ($requiredPermission && !$user->hasPermission($requiredPermission)) {
            return $this->error(
                'FORBIDDEN',
                "You lack the '{$requiredPermission}' permission required for the '{$targetState}' transition.",
                [],
                403
            );
        }

        try {
            $version = $this->planService->transitionState(
                $version, $targetState, $user->id,
                implode(',', $user->getRoles()),
                $request->input('reason'), $request->ip()
            );
            return $this->success($version->load('programs.tracks'));
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_TRANSITION', $e->getMessage(), [], 409);
        }
    }

    // ── Programs ──

    public function addProgram(Request $request, AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id) return $this->error('NOT_FOUND', 'Resource not found.', [], 404);
        if (!$version->isEditable()) return $this->error('VERSION_NOT_EDITABLE', "Cannot modify in state '{$version->state}'.", [], 409);

        $request->validate([
            'program_code' => 'required|string|max:50', 'program_name' => 'required|string|max:255',
            'description' => 'nullable|string', 'planned_capacity' => 'nullable|integer|min:0',
            'capacity_notes' => 'nullable|string', 'sort_order' => 'nullable|integer|min:0',
        ]);

        if (AdmissionsPlanProgram::where('version_id', $version->id)->where('program_code', $request->input('program_code'))->exists()) {
            return $this->error('DUPLICATE_PROGRAM', 'Program code already exists in this version.', [], 409);
        }

        $program = AdmissionsPlanProgram::create(array_merge(
            $request->only(['program_code', 'program_name', 'description', 'planned_capacity', 'capacity_notes', 'sort_order']),
            ['version_id' => $version->id]
        ));

        $this->auditService->log('admissions_plan_program', (string) $program->id, 'program_added',
            Auth::id(), null, $request->ip(), null, $this->auditService->computeEntityHash($program->toArray()),
            ['version_id' => $version->id]);

        return $this->success($program->load('tracks'), [], 201);
    }

    public function updateProgram(Request $request, AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version, AdmissionsPlanProgram $program): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id || $program->version_id !== $version->id) return $this->error('NOT_FOUND', 'Resource not found.', [], 404);
        if (!$version->isEditable()) return $this->error('VERSION_NOT_EDITABLE', "Cannot modify in state '{$version->state}'.", [], 409);

        $request->validate(['program_name' => 'sometimes|string|max:255', 'description' => 'nullable|string',
            'planned_capacity' => 'nullable|integer|min:0', 'capacity_notes' => 'nullable|string', 'sort_order' => 'nullable|integer|min:0']);
        $program->update($request->only(['program_name', 'description', 'planned_capacity', 'capacity_notes', 'sort_order']));
        return $this->success($program->fresh()->load('tracks'));
    }

    public function removeProgram(AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version, AdmissionsPlanProgram $program): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id || $program->version_id !== $version->id) return $this->error('NOT_FOUND', 'Resource not found.', [], 404);
        if (!$version->isEditable()) return $this->error('VERSION_NOT_EDITABLE', "Cannot modify in state '{$version->state}'.", [], 409);
        $program->tracks()->delete();
        $program->delete();
        $this->auditService->log('admissions_plan_program', (string) $program->id, 'program_removed', Auth::id(), null, request()->ip());
        return $this->success(['message' => 'Program removed.']);
    }

    // ── Tracks ──

    public function addTrack(Request $request, AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version, AdmissionsPlanProgram $program): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id || $program->version_id !== $version->id) return $this->error('NOT_FOUND', 'Resource not found.', [], 404);
        if (!$version->isEditable()) return $this->error('VERSION_NOT_EDITABLE', "Cannot modify in state '{$version->state}'.", [], 409);

        $request->validate(['track_code' => 'required|string|max:50', 'track_name' => 'required|string|max:255',
            'description' => 'nullable|string', 'planned_capacity' => 'nullable|integer|min:0',
            'capacity_notes' => 'nullable|string', 'admission_criteria' => 'nullable|string|max:500', 'sort_order' => 'nullable|integer|min:0']);

        if (AdmissionsPlanTrack::where('program_id', $program->id)->where('track_code', $request->input('track_code'))->exists()) {
            return $this->error('DUPLICATE_TRACK', 'Track code already exists.', [], 409);
        }

        $track = AdmissionsPlanTrack::create(array_merge(
            $request->only(['track_code', 'track_name', 'description', 'planned_capacity', 'capacity_notes', 'admission_criteria', 'sort_order']),
            ['program_id' => $program->id]
        ));
        return $this->success($track, [], 201);
    }

    public function updateTrack(Request $request, AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version, AdmissionsPlanProgram $program, AdmissionsPlanTrack $track): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id || $program->version_id !== $version->id || $track->program_id !== $program->id)
            return $this->error('NOT_FOUND', 'Resource not found.', [], 404);
        if (!$version->isEditable()) return $this->error('VERSION_NOT_EDITABLE', "Cannot modify in state '{$version->state}'.", [], 409);

        $request->validate(['track_name' => 'sometimes|string|max:255', 'description' => 'nullable|string',
            'planned_capacity' => 'nullable|integer|min:0', 'capacity_notes' => 'nullable|string',
            'admission_criteria' => 'nullable|string|max:500', 'sort_order' => 'nullable|integer|min:0']);
        $track->update($request->only(['track_name', 'description', 'planned_capacity', 'capacity_notes', 'admission_criteria', 'sort_order']));
        return $this->success($track->fresh());
    }

    public function removeTrack(AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version, AdmissionsPlanProgram $program, AdmissionsPlanTrack $track): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id || $program->version_id !== $version->id || $track->program_id !== $program->id)
            return $this->error('NOT_FOUND', 'Resource not found.', [], 404);
        if (!$version->isEditable()) return $this->error('VERSION_NOT_EDITABLE', "Cannot modify in state '{$version->state}'.", [], 409);
        $track->delete();
        return $this->success(['message' => 'Track removed.']);
    }

    // ── Comparison (internal staff only – route-protected) ──

    public function compareVersions(Request $request, AdmissionsPlan $admissionsPlan): JsonResponse
    {
        $request->validate(['left_version_id' => 'required|integer|exists:admissions_plan_versions,id',
            'right_version_id' => 'required|integer|exists:admissions_plan_versions,id']);
        $left = AdmissionsPlanVersion::where('plan_id', $admissionsPlan->id)->findOrFail($request->input('left_version_id'));
        $right = AdmissionsPlanVersion::where('plan_id', $admissionsPlan->id)->findOrFail($request->input('right_version_id'));
        return $this->success($this->planService->compareVersions($left, $right));
    }

    // ── Integrity ──

    public function verifyIntegrity(AdmissionsPlan $admissionsPlan, AdmissionsPlanVersion $version): JsonResponse
    {
        if ($version->plan_id !== $admissionsPlan->id) return $this->error('NOT_FOUND', 'Version does not belong to this plan.', [], 404);
        return $this->success($this->planService->verifyIntegrity($version));
    }
}
