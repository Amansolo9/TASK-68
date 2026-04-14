<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DuplicateCandidate;
use App\Models\MergeRequest;
use App\Services\DuplicateDetectionService;
use App\Services\MergeService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MergeController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private DuplicateDetectionService $duplicateService,
        private MergeService $mergeService,
    ) {}

    // --- Duplicates ---

    public function duplicates(Request $request): JsonResponse
    {
        $query = DuplicateCandidate::query();
        if ($request->has('entity_type')) $query->where('entity_type', $request->input('entity_type'));
        if ($request->has('status')) $query->where('status', $request->input('status'));

        return $this->paginated($query->orderByDesc('confidence')->paginate($request->input('per_page', 20)));
    }

    public function runDetection(Request $request): JsonResponse
    {
        $request->validate(['entity_type' => 'required|in:personnel,organization']);

        $candidates = $request->input('entity_type') === 'personnel'
            ? $this->duplicateService->detectPersonnelDuplicates()
            : $this->duplicateService->detectOrganizationDuplicates();

        return $this->success(['detected' => count($candidates)]);
    }

    public function updateDuplicate(Request $request, DuplicateCandidate $duplicate): JsonResponse
    {
        $request->validate(['status' => 'required|in:confirmed,rejected']);
        $duplicate->update(['status' => $request->input('status')]);
        return $this->success($duplicate->fresh());
    }

    // --- Merge Requests ---

    public function mergeRequests(Request $request): JsonResponse
    {
        $query = MergeRequest::with(['requester:id,full_name', 'approver:id,full_name']);
        if ($request->has('entity_type')) $query->where('entity_type', $request->input('entity_type'));
        if ($request->has('status')) $query->where('status', $request->input('status'));

        return $this->paginated($query->orderByDesc('created_at')->paginate($request->input('per_page', 20)));
    }

    public function createMergeRequest(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => 'required|in:personnel,organization',
            'source_entity_ids' => 'required|array|min:1',
            'source_entity_ids.*' => 'integer',
            'target_entity_id' => 'required|integer',
            'reason' => 'required|string',
        ]);

        $merge = $this->mergeService->createRequest(
            $request->input('entity_type'),
            $request->input('source_entity_ids'),
            $request->input('target_entity_id'),
            Auth::id(),
            $request->input('reason')
        );

        return $this->success($merge, [], 201);
    }

    public function transitionMerge(Request $request, MergeRequest $mergeRequest): JsonResponse
    {
        $request->validate([
            'target_state' => 'required|in:under_review,approved,rejected,cancelled',
            'reason' => 'nullable|string',
        ]);

        try {
            $merge = $this->mergeService->transition(
                $mergeRequest,
                $request->input('target_state'),
                Auth::id(),
                $request->input('reason')
            );
            return $this->success($merge);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_TRANSITION', $e->getMessage(), [], 409);
        }
    }

    public function executeMerge(MergeRequest $mergeRequest): JsonResponse
    {
        try {
            $merge = $this->mergeService->executeMerge($mergeRequest, Auth::id());
            return $this->success($merge);
        } catch (\InvalidArgumentException $e) {
            return $this->error('MERGE_ERROR', $e->getMessage(), [], 409);
        }
    }
}
