<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Support\Facades\Hash;

class RbacAuthorizationTest extends TestCase
{
    protected function authAs(string $role, array $userOverrides = []): array
    {
        $user = User::create(array_merge([
            'username' => 'user_' . uniqid(),
            'password_hash' => Hash::make('TestPassword123!'),
            'full_name' => 'Test ' . ucfirst($role),
            'status' => 'active',
        ], $userOverrides));

        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => $role,
            'is_active' => true,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'TestPassword123!',
        ]);

        $token = $loginResponse->json('data.token'); $this->markTokenMfaVerified($token); return [$user, $token];
    }

    public function test_admin_can_access_user_management(): void
    {
        [, $token] = $this->authAs('admin');

        $response = $this->getJson('/api/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_applicant_cannot_access_user_management(): void
    {
        [, $token] = $this->authAs('applicant');

        $response = $this->getJson('/api/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_advisor_cannot_access_user_management(): void
    {
        [, $token] = $this->authAs('advisor');

        $response = $this->getJson('/api/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_access_audit_logs(): void
    {
        [, $token] = $this->authAs('admin');

        $response = $this->getJson('/api/audit-logs', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_applicant_cannot_access_audit_logs(): void
    {
        [, $token] = $this->authAs('applicant');

        $response = $this->getJson('/api/audit-logs', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_steward_can_access_dictionaries(): void
    {
        [, $token] = $this->authAs('steward');

        $response = $this->getJson('/api/dictionaries', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_applicant_cannot_access_dictionaries(): void
    {
        [, $token] = $this->authAs('applicant');

        $response = $this->getJson('/api/dictionaries', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_all_authenticated_users_can_read_organizations(): void
    {
        [, $token] = $this->authAs('applicant');

        $response = $this->getJson('/api/organizations', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_applicant_cannot_create_organization(): void
    {
        [, $token] = $this->authAs('applicant');

        $response = $this->postJson('/api/organizations', [
            'code' => 'ORG-000999',
            'name' => 'Test Org',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_steward_can_create_organization(): void
    {
        [, $token] = $this->authAs('steward');

        $response = $this->postJson('/api/organizations', [
            'code' => 'ORG-000999',
            'name' => 'Test Org',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201);
    }

    public function test_user_has_permission_check(): void
    {
        [$user, ] = $this->authAs('admin');

        $this->assertTrue($user->hasPermission('security.manage'));
        $this->assertTrue($user->hasPermission('audit.view'));
        $this->assertTrue($user->hasPermission('masterdata.edit'));
    }

    public function test_applicant_has_limited_permissions(): void
    {
        [$user, ] = $this->authAs('applicant');

        $this->assertTrue($user->hasPermission('plans.view_published'));
        $this->assertTrue($user->hasPermission('tickets.create'));
        $this->assertFalse($user->hasPermission('security.manage'));
        $this->assertFalse($user->hasPermission('masterdata.edit'));
    }

    public function test_user_role_scope_department_filtering(): void
    {
        $user = User::create([
            'username' => 'scoped_user',
            'password_hash' => Hash::make('password'),
            'full_name' => 'Scoped User',
            'status' => 'active',
        ]);

        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => 'advisor',
            'department_scope' => 'DEPT-001',
            'is_active' => true,
        ]);

        $this->assertTrue($user->hasDepartmentScope('DEPT-001'));
        $this->assertFalse($user->hasDepartmentScope('DEPT-999'));
    }
}
