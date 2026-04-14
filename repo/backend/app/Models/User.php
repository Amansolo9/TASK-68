<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\HasMaskedFields;

class User extends Authenticatable
{
    use HasMaskedFields;

    protected $fillable = [
        'username',
        'password_hash',
        'full_name',
        'encrypted_date_of_birth',
        'encrypted_government_id',
        'encrypted_institutional_id',
        'email',
        'department_id',
        'status',
        'totp_enabled',
        'failed_login_count',
        'lockout_until',
        'last_login_at',
        'password_changed_at',
        'mfa_verified_this_session',
    ];

    protected $hidden = [
        'password_hash',
        'encrypted_date_of_birth',
        'encrypted_government_id',
        'encrypted_institutional_id',
    ];

    protected $casts = [
        'totp_enabled' => 'boolean',
        'mfa_verified_this_session' => 'boolean',
        'failed_login_count' => 'integer',
        'last_login_at' => 'datetime',
        'lockout_until' => 'datetime',
        'password_changed_at' => 'datetime',
    ];

    protected array $maskedFields = [
        'date_of_birth' => 'encrypted_date_of_birth',
        'government_id' => 'encrypted_government_id',
        'institutional_id' => 'encrypted_institutional_id',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function roleScopes(): HasMany
    {
        return $this->hasMany(UserRoleScope::class);
    }

    public function activeRoleScopes(): HasMany
    {
        return $this->hasMany(UserRoleScope::class)->where('is_active', true);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function mfaSecret(): HasOne
    {
        return $this->hasOne(MfaSecret::class);
    }

    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class, 'username', 'username');
    }

    public function hasRole(string $role): bool
    {
        return $this->activeRoleScopes()->where('role', $role)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->activeRoleScopes()->whereIn('role', $roles)->exists();
    }

    public function getRoles(): array
    {
        return $this->activeRoleScopes()->pluck('role')->unique()->toArray();
    }

    public function hasPermission(string $permission): bool
    {
        $rolePermissions = config('permissions.role_permissions', []);
        $roles = $this->getRoles();

        foreach ($roles as $role) {
            if (isset($rolePermissions[$role]) && in_array($permission, $rolePermissions[$role])) {
                return true;
            }
        }

        // Check section-level permissions from role scopes
        foreach ($this->activeRoleScopes as $scope) {
            $sectionPerms = $scope->section_permissions ?? [];
            if (in_array($permission, $sectionPerms)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has a specific content-level permission.
     * Content permissions are stored per-role-scope and restrict access
     * to specific content types/resources beyond section-level checks.
     */
    public function hasContentPermission(string $contentPermission): bool
    {
        foreach ($this->activeRoleScopes as $scope) {
            $contentPerms = $scope->content_permissions ?? [];
            if (in_array($contentPermission, $contentPerms)) {
                return true;
            }
        }
        // Admin bypasses content-level restrictions
        if ($this->hasRole('admin')) {
            return true;
        }
        return false;
    }

    /**
     * Check if user has content-level access for a specific entity type and action.
     * Returns true if no content permissions are configured (open by default for role),
     * or if the user explicitly has the required content permission.
     */
    public function hasContentAccess(string $entityType, string $action = 'view'): bool
    {
        // Admin always has access
        if ($this->hasRole('admin')) {
            return true;
        }

        $requiredPermission = "{$entityType}.{$action}";

        // Check if any of the user's scopes define content_permissions
        $hasAnyContentRestrictions = false;
        foreach ($this->activeRoleScopes as $scope) {
            $contentPerms = $scope->content_permissions ?? [];
            if (!empty($contentPerms)) {
                $hasAnyContentRestrictions = true;
                if (in_array($requiredPermission, $contentPerms)) {
                    return true;
                }
            }
        }

        // If no content permissions are configured at all, default to role-level access
        if (!$hasAnyContentRestrictions) {
            return true;
        }

        return false;
    }

    public function hasDepartmentScope(string $departmentId): bool
    {
        return $this->activeRoleScopes()
            ->where(function ($q) use ($departmentId) {
                $q->whereNull('department_scope')
                  ->orWhere('department_scope', $departmentId);
            })
            ->exists();
    }

    public function isLockedOut(): bool
    {
        return $this->status === 'locked'
            || ($this->lockout_until && $this->lockout_until->isFuture());
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function requiresMfa(): bool
    {
        if ($this->hasRole('admin') && config('auth.mfa.required_for_admin', true)) {
            return true;
        }
        return $this->totp_enabled;
    }
}
