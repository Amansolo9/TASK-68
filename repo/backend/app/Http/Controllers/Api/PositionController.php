<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Services\AuditService;
use App\Services\MasterDataVersionService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PositionController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private AuditService $auditService,
        private MasterDataVersionService $versionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Position::with('organization');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('department')) {
            $query->where('department', $request->input('department'));
        }
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return $this->paginated($query->orderBy('title')->paginate($request->input('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50|unique:positions,code',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'department' => 'nullable|string|max:100',
        ]);

        $position = Position::create($request->only(['code', 'title', 'description', 'organization_id', 'department']));

        $this->versionService->trackCreate($position, 'position', Auth::id());

        $this->auditService->log(
            'position', (string) $position->id, 'position_created',
            Auth::id(), null, $request->ip(),
            null, $this->auditService->computeEntityHash($position->toArray())
        );

        return $this->success($position, [], 201);
    }

    public function show(Position $position): JsonResponse
    {
        $position->load('organization');
        $data = $position->toArray();
        $data['version_history'] = $this->versionService->getHistory('position', $position->id);
        return $this->success($data);
    }

    public function update(Request $request, Position $position): JsonResponse
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'department' => 'nullable|string|max:100',
            'change_reason' => 'nullable|string|max:500',
        ]);

        $beforeSnapshot = $position->toArray();
        $position->update($request->only(['title', 'description', 'organization_id', 'department']));

        $this->versionService->trackUpdate($position, $beforeSnapshot, 'position', Auth::id(), $request->input('change_reason'));

        $this->auditService->log(
            'position', (string) $position->id, 'position_updated',
            Auth::id(), null, $request->ip()
        );

        return $this->success($position->fresh());
    }

    public function destroy(Request $request, Position $position): JsonResponse
    {
        $this->versionService->trackSoftDelete($position, 'position', Auth::id(), $request->input('reason'));
        $position->update(['status' => 'inactive']);
        $position->delete();

        $this->auditService->log(
            'position', (string) $position->id, 'position_soft_deleted',
            Auth::id(), null, $request->ip()
        );

        return $this->success(['message' => 'Position soft-deleted.']);
    }
}
