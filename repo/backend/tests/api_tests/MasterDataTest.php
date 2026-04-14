<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\Organization;
use Illuminate\Support\Facades\Hash;

class MasterDataTest extends TestCase
{
    protected function authenticateAsSteward(): string
    {
        $user = User::create([
            'username' => 'steward_' . uniqid(),
            'password_hash' => Hash::make('StewardPassword123!'),
            'full_name' => 'Data Steward',
            'status' => 'active',
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => 'steward', 'is_active' => true]);

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'StewardPassword123!',
        ]);

        $token = $response->json('data.token');
        $this->markTokenMfaVerified($token);
        return $token;
    }

    public function test_create_organization_with_valid_code(): void
    {
        $token = $this->authenticateAsSteward();

        $response = $this->postJson('/api/organizations', [
            'code' => 'ORG-000100',
            'name' => 'Test Organization',
            'type' => 'DEPARTMENT',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'ORG-000100')
            ->assertJsonPath('data.name', 'Test Organization');
    }

    public function test_create_organization_with_invalid_code_fails(): void
    {
        $token = $this->authenticateAsSteward();

        $response = $this->postJson('/api/organizations', [
            'code' => 'INVALID-CODE',
            'name' => 'Bad Org',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422);
    }

    public function test_create_organization_with_duplicate_code_fails(): void
    {
        $token = $this->authenticateAsSteward();

        $this->postJson('/api/organizations', [
            'code' => 'ORG-000200',
            'name' => 'First Org',
        ], ['Authorization' => "Bearer {$token}"]);

        $response = $this->postJson('/api/organizations', [
            'code' => 'ORG-000200',
            'name' => 'Duplicate Org',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422);
    }

    public function test_organization_soft_delete_preserves_record(): void
    {
        $token = $this->authenticateAsSteward();

        $createResponse = $this->postJson('/api/organizations', [
            'code' => 'ORG-000300',
            'name' => 'Deletable Org',
        ], ['Authorization' => "Bearer {$token}"]);

        $orgId = $createResponse->json('data.id');

        $this->deleteJson("/api/organizations/{$orgId}", [], [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(200);

        // Record should still exist in DB (soft deleted)
        $this->assertDatabaseHas('organizations', ['id' => $orgId]);
        $this->assertNotNull(Organization::withTrashed()->find($orgId)->deleted_at);
    }

    public function test_organization_update_creates_version_history(): void
    {
        $token = $this->authenticateAsSteward();

        $createResponse = $this->postJson('/api/organizations', [
            'code' => 'ORG-000400',
            'name' => 'Original Name',
        ], ['Authorization' => "Bearer {$token}"]);

        $orgId = $createResponse->json('data.id');

        $this->putJson("/api/organizations/{$orgId}", [
            'name' => 'Updated Name',
            'change_reason' => 'Corrected name',
        ], ['Authorization' => "Bearer {$token}"]);

        // Check version history
        $this->assertDatabaseHas('master_data_versions', [
            'entity_type' => 'organization',
            'entity_id' => $orgId,
            'version_no' => 1,
        ]);
        $this->assertDatabaseHas('master_data_versions', [
            'entity_type' => 'organization',
            'entity_id' => $orgId,
            'version_no' => 2,
        ]);
    }

    public function test_organization_normalized_name_is_set(): void
    {
        $token = $this->authenticateAsSteward();

        $this->postJson('/api/organizations', [
            'code' => 'ORG-000500',
            'name' => '  Test  Organization  NAME  ',
        ], ['Authorization' => "Bearer {$token}"]);

        $this->assertDatabaseHas('organizations', [
            'code' => 'ORG-000500',
            'normalized_name' => 'test organization name',
        ]);
    }

    public function test_create_personnel_with_encrypted_fields(): void
    {
        $token = $this->authenticateAsSteward();

        $response = $this->postJson('/api/personnel', [
            'employee_id' => 'EMP-001',
            'full_name' => 'Jane Doe',
            'date_of_birth' => '1990-05-15',
            'email' => 'jane@test.com',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);

        // DOB should be encrypted in database
        $personnel = \App\Models\Personnel::find($response->json('data.id'));
        $this->assertNotEquals('1990-05-15', $personnel->encrypted_date_of_birth);
        $this->assertNotNull($personnel->encrypted_date_of_birth);
    }

    public function test_dictionary_crud_operations(): void
    {
        $token = $this->authenticateAsSteward();

        // Create
        $response = $this->postJson('/api/dictionaries', [
            'dictionary_type' => 'test_type',
            'code' => 'TEST_CODE',
            'label' => 'Test Label',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $id = $response->json('data.id');

        // Read
        $this->getJson("/api/dictionaries/{$id}", [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(200)->assertJsonPath('data.label', 'Test Label');

        // Update
        $this->putJson("/api/dictionaries/{$id}", [
            'label' => 'Updated Label',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        // Verify update
        $this->getJson("/api/dictionaries/{$id}", [
            'Authorization' => "Bearer {$token}",
        ])->assertJsonPath('data.label', 'Updated Label');

        // Soft delete
        $this->deleteJson("/api/dictionaries/{$id}", [], [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(200);
    }

    public function test_dictionary_lookup_returns_active_only(): void
    {
        $token = $this->authenticateAsSteward();

        $this->postJson('/api/dictionaries', [
            'dictionary_type' => 'lookup_test',
            'code' => 'ACTIVE_ONE',
            'label' => 'Active Entry',
        ], ['Authorization' => "Bearer {$token}"]);

        $this->postJson('/api/dictionaries', [
            'dictionary_type' => 'lookup_test',
            'code' => 'INACTIVE_ONE',
            'label' => 'Inactive Entry',
            'is_active' => false,
        ], ['Authorization' => "Bearer {$token}"]);

        // Lookup should only return active entries
        // Use any authenticated user for lookup
        $response = $this->getJson('/api/lookup/dictionaries/lookup_test', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $codes = array_column($data, 'code');
        $this->assertContains('ACTIVE_ONE', $codes);
    }

    public function test_audit_log_created_for_organization_operations(): void
    {
        $token = $this->authenticateAsSteward();

        $this->postJson('/api/organizations', [
            'code' => 'ORG-000600',
            'name' => 'Audited Org',
        ], ['Authorization' => "Bearer {$token}"]);

        $this->assertDatabaseHas('audit_log', [
            'entity_type' => 'organization',
            'event_type' => 'organization_created',
        ]);
    }
}
