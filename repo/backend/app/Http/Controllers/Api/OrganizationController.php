<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\AuditService;
use App\Services\MasterDataVersionService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrganizationController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private AuditService $auditService,
        private MasterDataVersionService $versionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Organization::query();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('search')) {
            $normalized = Organization::normalizeName($request->input('search'));
            $query->where('normalized_name', 'like', "%{$normalized}%");
        }
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $orgs = $query->orderBy('name')->paginate($request->input('per_page', 20));

        return $this->paginated($orgs);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:organizations,code', function ($attr, $value, $fail) {
                if (!Organization::validateCode($value)) {
                    $fail('Organization code must follow the format ORG-XXXXXX (e.g., ORG-000123).');
                }
            }],
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'parent_org_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $org = Organization::create($request->only([
            'code', 'name', 'type', 'address', 'phone', 'parent_org_id',
        ]));

        $this->versionService->trackCreate($org, 'organization', Auth::id());

        $this->auditService->log(
            'organization', (string) $org->id, 'organization_created',
            Auth::id(), null, $request->ip(),
            null,
            $this->auditService->computeEntityHash($org->toArray())
        );

        return $this->success($org, [], 201);
    }

    public function show(Organization $organization): JsonResponse
    {
        $organization->load(['parent', 'children']);

        $data = $organization->toArray();
        $data['version_history'] = $this->versionService->getHistory('organization', $organization->id);

        return $this->success($data);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'parent_org_id' => 'nullable|integer|exists:organizations,id',
            'change_reason' => 'nullable|string|max:500',
        ]);

        $beforeSnapshot = $organization->toArray();
        $beforeHash = $this->auditService->computeEntityHash($beforeSnapshot);

        $organization->update($request->only(['name', 'type', 'address', 'phone', 'parent_org_id']));

        $this->versionService->trackUpdate(
            $organization, $beforeSnapshot, 'organization',
            Auth::id(), $request->input('change_reason')
        );

        $this->auditService->log(
            'organization', (string) $organization->id, 'organization_updated',
            Auth::id(), null, $request->ip(),
            $beforeHash,
            $this->auditService->computeEntityHash($organization->fresh()->toArray())
        );

        return $this->success($organization->fresh());
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $this->versionService->trackSoftDelete($organization, 'organization', Auth::id(), $request->input('reason'));

        $organization->update(['status' => 'inactive']);
        $organization->delete(); // Soft delete

        $this->auditService->log(
            'organization', (string) $organization->id, 'organization_soft_deleted',
            Auth::id(), null, $request->ip()
        );

        return $this->success(['message' => 'Organization soft-deleted.']);
    }
}
