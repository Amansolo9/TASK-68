<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\AuditService;
use App\Services\EncryptionService;
use App\Services\SessionTokenService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private AuditService $auditService,
        private EncryptionService $encryptionService,
        private SessionTokenService $sessionTokenService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = User::with('activeRoleScopes');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('role')) {
            $query->whereHas('activeRoleScopes', fn ($q) => $q->where('role', $request->input('role')));
        }
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate($request->input('per_page', 20));

        return $this->paginated($users);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string|max:100|unique:users,username',
            'password' => 'required|string|min:12',
            'full_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'department_id' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'government_id' => 'nullable|string',
            'institutional_id' => 'nullable|string',
            'roles' => 'required|array|min:1',
            'roles.*.role' => 'required|in:applicant,advisor,manager,steward,admin',
            'roles.*.department_scope' => 'nullable|string',
        ]);

        $user = User::create([
            'username' => $request->input('username'),
            'password_hash' => Hash::make($request->input('password')),
            'full_name' => $request->input('full_name'),
            'email' => $request->input('email'),
            'department_id' => $request->input('department_id'),
            'encrypted_date_of_birth' => $request->input('date_of_birth')
                ? $this->encryptionService->encrypt($request->input('date_of_birth'))
                : null,
            'encrypted_government_id' => $request->input('government_id')
                ? $this->encryptionService->encrypt($request->input('government_id'))
                : null,
            'encrypted_institutional_id' => $request->input('institutional_id')
                ? $this->encryptionService->encrypt($request->input('institutional_id'))
                : null,
            'status' => 'active',
        ]);

        foreach ($request->input('roles') as $roleData) {
            UserRoleScope::create([
                'user_id' => $user->id,
                'role' => $roleData['role'],
                'department_scope' => $roleData['department_scope'] ?? null,
                'is_active' => true,
            ]);
        }

        $this->auditService->log(
            'user', (string) $user->id, 'user_created',
            Auth::id(), 'admin', $request->ip(),
            null,
            $this->auditService->computeEntityHash($user->toArray())
        );

        return $this->success(
            $user->load('activeRoleScopes')->toMaskedArray(Auth::user()),
            [],
            201
        );
    }

    public function show(User $user): JsonResponse
    {
        $user->load('activeRoleScopes');
        return $this->success($user->toMaskedArray(Auth::user()));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255',
            'department_id' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'government_id' => 'nullable|string',
            'institutional_id' => 'nullable|string',
        ]);

        $beforeHash = $this->auditService->computeEntityHash($user->toArray());

        $updates = $request->only(['full_name', 'email', 'department_id']);

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
        if ($request->has('institutional_id')) {
            $updates['encrypted_institutional_id'] = $request->input('institutional_id')
                ? $this->encryptionService->encrypt($request->input('institutional_id'))
                : null;
        }

        $user->update($updates);

        $this->auditService->log(
            'user', (string) $user->id, 'user_updated',
            Auth::id(), 'admin', $request->ip(),
            $beforeHash,
            $this->auditService->computeEntityHash($user->fresh()->toArray())
        );

        return $this->success($user->fresh()->load('activeRoleScopes')->toMaskedArray(Auth::user()));
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $user->update(['status' => 'inactive']);
        $this->sessionTokenService->revokeAllForUser($user->id);

        $this->auditService->log(
            'user', (string) $user->id, 'user_deactivated',
            Auth::id(), 'admin', $request->ip()
        );

        return $this->success(['message' => 'User deactivated.']);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $user->update(['status' => 'active', 'failed_login_count' => 0, 'lockout_until' => null]);

        $this->auditService->log(
            'user', (string) $user->id, 'user_activated',
            Auth::id(), 'admin', $request->ip()
        );

        return $this->success(['message' => 'User activated.']);
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $user->update([
            'status' => 'active',
            'failed_login_count' => 0,
            'lockout_until' => null,
        ]);

        $this->auditService->log(
            'user', (string) $user->id, 'user_unlocked',
            Auth::id(), 'admin', $request->ip()
        );

        return $this->success(['message' => 'User unlocked.']);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'new_password' => 'required|string|min:12',
        ]);

        $user->update([
            'password_hash' => Hash::make($request->input('new_password')),
            'password_changed_at' => now(),
        ]);

        $this->sessionTokenService->revokeAllForUser($user->id);

        $this->auditService->log(
            'user', (string) $user->id, 'password_reset',
            Auth::id(), 'admin', $request->ip()
        );

        return $this->success(['message' => 'Password reset successfully.']);
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'roles' => 'required|array|min:1',
            'roles.*.role' => 'required|in:applicant,advisor,manager,steward,admin',
            'roles.*.department_scope' => 'nullable|string',
            'roles.*.section_permissions' => 'nullable|array',
            'roles.*.content_permissions' => 'nullable|array',
        ]);

        $beforeHash = $this->auditService->computeEntityHash($user->activeRoleScopes->toArray());

        // Deactivate all current roles
        $user->roleScopes()->update(['is_active' => false]);

        // Create new role assignments
        foreach ($request->input('roles') as $roleData) {
            UserRoleScope::create([
                'user_id' => $user->id,
                'role' => $roleData['role'],
                'department_scope' => $roleData['department_scope'] ?? null,
                'section_permissions' => $roleData['section_permissions'] ?? null,
                'content_permissions' => $roleData['content_permissions'] ?? null,
                'is_active' => true,
            ]);
        }

        $this->auditService->log(
            'user', (string) $user->id, 'roles_updated',
            Auth::id(), 'admin', $request->ip(),
            $beforeHash,
            $this->auditService->computeEntityHash($user->fresh()->activeRoleScopes->toArray())
        );

        return $this->success($user->fresh()->load('activeRoleScopes'));
    }
}
