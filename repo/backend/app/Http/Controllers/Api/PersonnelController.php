<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Services\AuditService;
use App\Services\EncryptionService;
use App\Services\MasterDataVersionService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PersonnelController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private AuditService $auditService,
        private EncryptionService $encryptionService,
        private MasterDataVersionService $versionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Personnel::with('organization');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('search')) {
            $normalized = Personnel::normalizeName($request->input('search'));
            $query->where('normalized_name', 'like', "%{$normalized}%");
        }
        if ($request->has('organization_id')) {
            $query->where('organization_id', $request->input('organization_id'));
        }
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->input('employee_id'));
        }

        $personnel = $query->orderBy('full_name')->paginate($request->input('per_page', 20));

        // Mask sensitive fields
        $items = collect($personnel->items())->map(fn ($p) => $p->toMaskedArray(Auth::user()));

        return $this->success($items, [
            'pagination' => [
                'current_page' => $personnel->currentPage(),
                'per_page' => $personnel->perPage(),
                'total' => $personnel->total(),
                'last_page' => $personnel->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => 'nullable|string|max:50|unique:personnel,employee_id',
            'full_name' => 'required|string|max:255',
            'date_of_birth' => 'nullable|date',
            'government_id' => 'nullable|string',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'organization_id' => 'nullable|integer|exists:organizations,id',
        ]);

        $personnel = Personnel::create([
            'employee_id' => $request->input('employee_id'),
            'full_name' => $request->input('full_name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'organization_id' => $request->input('organization_id'),
            'encrypted_date_of_birth' => $request->input('date_of_birth')
                ? $this->encryptionService->encrypt($request->input('date_of_birth'))
                : null,
            'encrypted_government_id' => $request->input('government_id')
                ? $this->encryptionService->encrypt($request->input('government_id'))
                : null,
        ]);

        $this->versionService->trackCreate($personnel, 'personnel', Auth::id());

        $this->auditService->log(
            'personnel', (string) $personnel->id, 'personnel_created',
            Auth::id(), null, $request->ip(),
            null,
            $this->auditService->computeEntityHash($personnel->toArray())
        );

        return $this->success($personnel->toMaskedArray(Auth::user()), [], 201);
    }

    public function show(Personnel $personnel): JsonResponse
    {
        $personnel->load('organization');

        $data = $personnel->toMaskedArray(Auth::user());
        $data['version_history'] = $this->versionService->getHistory('personnel', $personnel->id);

        return $this->success($data);
    }

    public function update(Request $request, Personnel $personnel): JsonResponse
    {
        $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'date_of_birth' => 'nullable|date',
            'government_id' => 'nullable|string',
            'change_reason' => 'nullable|string|max:500',
        ]);

        $beforeSnapshot = $personnel->toArray();

        $updates = $request->only(['full_name', 'email', 'phone', 'organization_id']);

        if ($request->has('date_of_birth')) {
            $updates['encrypted_date_of_birth'] = $request->input('date_of_birth')
                ? $this->encryptionService->encrypt($request->input('date_of_birth'))
                : null;
        }
        if ($request->has('government_id')) {
            $updates['encrypted_government_id'] = $request->input('government_id')
                ? $this->encryptionService->encrypt($request->input('government_id'))
                : null;
        }

        $personnel->update($updates);

        $this->versionService->trackUpdate(
            $personnel, $beforeSnapshot, 'personnel',
            Auth::id(), $request->input('change_reason')
        );

        $this->auditService->log(
            'personnel', (string) $personnel->id, 'personnel_updated',
            Auth::id(), null, $request->ip()
        );

        return $this->success($personnel->fresh()->toMaskedArray(Auth::user()));
    }

    public function destroy(Request $request, Personnel $personnel): JsonResponse
    {
        $this->versionService->trackSoftDelete($personnel, 'personnel', Auth::id(), $request->input('reason'));

        $personnel->update(['status' => 'inactive']);
        $personnel->delete();

        $this->auditService->log(
            'personnel', (string) $personnel->id, 'personnel_soft_deleted',
            Auth::id(), null, $request->ip()
        );

        return $this->success(['message' => 'Personnel record soft-deleted.']);
    }
}
