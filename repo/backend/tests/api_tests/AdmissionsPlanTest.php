<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\AdmissionsPlan;
use App\Models\AdmissionsPlanVersion;
use App\Models\AdmissionsPlanProgram;
use App\Models\AdmissionsPlanTrack;
use App\Services\PlanVersionService;
use Illuminate\Support\Facades\Hash;

class AdmissionsPlanTest extends TestCase
{
    protected function authenticateAsManager(): array
    {
        $user = User::create([
            'username' => 'manager_' . uniqid(),
            'password_hash' => Hash::make('ManagerPass123!'),
            'full_name' => 'Test Manager',
            'status' => 'active',
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => 'manager', 'is_active' => true]);

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'ManagerPass123!',
        ]);

        $token = $response->json('data.token'); $this->markTokenMfaVerified($token); return [$user, $token];
    }

    protected function authenticateAsApplicant(): array
    {
        $user = User::create([
            'username' => 'applicant_' . uniqid(),
            'password_hash' => Hash::make('ApplicantPass123!'),
            'full_name' => 'Test Applicant',
            'status' => 'active',
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => 'applicant', 'is_active' => true]);

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'ApplicantPass123!',
        ]);

        $token = $response->json('data.token'); $this->markTokenMfaVerified($token); return [$user, $token];
    }

    private function createPlanWithVersion(string $token): array
    {
        $response = $this->postJson('/api/admissions-plans', [
            'academic_year' => '2025-2026',
            'intake_batch' => 'Fall 2025',
            'description' => 'Test plan',
        ], ['Authorization' => "Bearer {$token}"]);

        $plan = AdmissionsPlan::find($response->json('data.id'));
        $version = $plan->currentVersion;

        return [$plan, $version];
    }

    // --- Plan Creation ---

    public function test_manager_can_create_plan(): void
    {
        [, $token] = $this->authenticateAsManager();

        $response = $this->postJson('/api/admissions-plans', [
            'academic_year' => '2025-2026',
            'intake_batch' => 'Fall 2025',
            'description' => 'Initial plan',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('admissions_plans', [
            'academic_year' => '2025-2026',
            'intake_batch' => 'Fall 2025',
        ]);

        // Should auto-create a draft version
        $planId = $response->json('data.id');
        $this->assertDatabaseHas('admissions_plan_versions', [
            'plan_id' => $planId,
            'version_no' => 1,
            'state' => 'draft',
        ]);
    }

    public function test_applicant_cannot_create_plan(): void
    {
        [, $token] = $this->authenticateAsApplicant();

        $response = $this->postJson('/api/admissions-plans', [
            'academic_year' => '2025-2026',
            'intake_batch' => 'Fall 2025',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_duplicate_year_batch_fails(): void
    {
        [, $token] = $this->authenticateAsManager();

        $this->postJson('/api/admissions-plans', [
            'academic_year' => '2025-2026',
            'intake_batch' => 'Fall 2025',
        ], ['Authorization' => "Bearer {$token}"]);

        $response = $this->postJson('/api/admissions-plans', [
            'academic_year' => '2025-2026',
            'intake_batch' => 'Fall 2025',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422);
    }

    // --- State Transitions ---

    public function test_full_lifecycle_draft_to_published(): void
    {
        [$user, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        // Set effective date
        $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}", [
            'effective_date' => '2025-09-01',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        // Add a program
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/programs", [
            'program_code' => 'CS-101',
            'program_name' => 'Computer Science',
            'planned_capacity' => 100,
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

        // draft -> submitted
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", [
            'target_state' => 'submitted',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $version->refresh();
        $this->assertEquals('submitted', $version->state);

        // submitted -> under_review
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", [
            'target_state' => 'under_review',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        // under_review -> approved
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", [
            'target_state' => 'approved',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        // approved -> published
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", [
            'target_state' => 'published',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $version->refresh();
        $this->assertEquals('published', $version->state);
        $this->assertNotNull($version->snapshot_hash);
        $this->assertNotNull($version->published_at);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        // draft -> published (skipping intermediate states)
        $response = $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", [
            'target_state' => 'published',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_TRANSITION');
    }

    public function test_publish_requires_effective_date(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        // Move to approved state without setting effective date
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer {$token}"]);

        // Try to publish without effective date
        $response = $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", [
            'target_state' => 'published',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409);
    }

    // --- Immutability ---

    public function test_published_version_cannot_be_edited(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        // Set effective date and go through workflow
        $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}", ['effective_date' => '2025-09-01'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'published'], ['Authorization' => "Bearer {$token}"]);

        // Try to edit the published version
        $response = $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}", [
            'description' => 'Tampered description',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_NOT_EDITABLE');
    }

    public function test_cannot_add_program_to_published_version(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}", ['effective_date' => '2025-09-01'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'published'], ['Authorization' => "Bearer {$token}"]);

        $response = $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/programs", [
            'program_code' => 'NEW-PRG',
            'program_name' => 'Hacked Program',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409);
    }

    // --- Programs and Tracks ---

    public function test_add_program_and_track_to_draft(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        // Add program
        $programResponse = $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/programs", [
            'program_code' => 'BUS-101',
            'program_name' => 'Business Administration',
            'planned_capacity' => 50,
        ], ['Authorization' => "Bearer {$token}"]);

        $programResponse->assertStatus(201);
        $programId = $programResponse->json('data.id');

        // Add track
        $trackResponse = $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/programs/{$programId}/tracks", [
            'track_code' => 'BUS-FT',
            'track_name' => 'Full-Time Track',
            'planned_capacity' => 30,
            'admission_criteria' => 'GPA >= 3.0',
        ], ['Authorization' => "Bearer {$token}"]);

        $trackResponse->assertStatus(201)
            ->assertJsonPath('data.track_code', 'BUS-FT');
    }

    public function test_duplicate_program_code_in_same_version_fails(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/programs", [
            'program_code' => 'DUP-101',
            'program_name' => 'First',
        ], ['Authorization' => "Bearer {$token}"]);

        $response = $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/programs", [
            'program_code' => 'DUP-101',
            'program_name' => 'Second',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409);
    }

    // --- Version Comparison ---

    public function test_compare_two_versions_shows_differences(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $v1] = $this->createPlanWithVersion($token);

        // Add program to v1
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v1->id}/programs", [
            'program_code' => 'CS-101',
            'program_name' => 'Computer Science',
            'planned_capacity' => 100,
        ], ['Authorization' => "Bearer {$token}"]);

        // Create v2
        $v2Response = $this->postJson("/api/admissions-plans/{$plan->id}/versions", [
            'description' => 'Version 2',
        ], ['Authorization' => "Bearer {$token}"]);

        $v2 = AdmissionsPlanVersion::find($v2Response->json('data.id'));

        // Add different program to v2
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v2->id}/programs", [
            'program_code' => 'CS-101',
            'program_name' => 'Computer Science (Updated)',
            'planned_capacity' => 150,
        ], ['Authorization' => "Bearer {$token}"]);

        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v2->id}/programs", [
            'program_code' => 'BUS-101',
            'program_name' => 'Business Admin',
        ], ['Authorization' => "Bearer {$token}"]);

        // Compare
        $response = $this->postJson("/api/admissions-plans/{$plan->id}/compare", [
            'left_version_id' => $v1->id,
            'right_version_id' => $v2->id,
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data['program_changes']);
        $this->assertGreaterThan(0, $data['summary']['programs_added'] + $data['summary']['programs_modified']);
    }

    // --- Integrity ---

    public function test_integrity_verification_passes_for_valid_published_version(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}", ['effective_date' => '2025-09-01'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'published'], ['Authorization' => "Bearer {$token}"]);

        $response = $this->getJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/integrity", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.valid', true);
    }

    // --- Derive from Published ---

    public function test_derive_new_version_from_published(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        // Add program and publish
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/programs", [
            'program_code' => 'CS-101',
            'program_name' => 'Computer Science',
            'planned_capacity' => 100,
        ], ['Authorization' => "Bearer {$token}"]);

        $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}", ['effective_date' => '2025-09-01'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'published'], ['Authorization' => "Bearer {$token}"]);

        // Derive new version
        $response = $this->postJson("/api/admissions-plans/{$plan->id}/derive-from-published", [
            'description' => 'Updated version',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $newVersion = $response->json('data');

        $this->assertEquals(2, $newVersion['version_no']);
        $this->assertEquals('draft', $newVersion['state']);
        // Should have copied programs
        $this->assertNotEmpty($newVersion['programs']);
        $this->assertEquals('CS-101', $newVersion['programs'][0]['program_code']);
    }

    // --- State History ---

    public function test_state_transitions_create_history_entries(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}", ['effective_date' => '2025-09-01'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);

        // Check state history
        $this->assertDatabaseHas('plan_state_history', [
            'version_id' => $version->id,
            'to_state' => 'draft',
        ]);
        $this->assertDatabaseHas('plan_state_history', [
            'version_id' => $version->id,
            'from_state' => 'draft',
            'to_state' => 'submitted',
        ]);
    }

    public function test_state_history_is_immutable(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        $history = \App\Models\PlanStateHistory::where('version_id', $version->id)->first();

        $this->expectException(\RuntimeException::class);
        $history->update(['to_state' => 'tampered']);
    }

    // --- Audit ---

    public function test_plan_operations_create_audit_entries(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $version] = $this->createPlanWithVersion($token);

        $this->assertDatabaseHas('audit_log', [
            'entity_type' => 'admissions_plan_version',
            'event_type' => 'version_created',
        ]);

        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", [
            'target_state' => 'submitted',
        ], ['Authorization' => "Bearer {$token}"]);

        $this->assertDatabaseHas('audit_log', [
            'entity_type' => 'admissions_plan_version',
            'event_type' => 'state_transition_submitted',
        ]);
    }

    // --- Only one published version ---

    public function test_publishing_supersedes_previous_published_version(): void
    {
        [, $token] = $this->authenticateAsManager();
        [$plan, $v1] = $this->createPlanWithVersion($token);

        // Publish v1
        $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$v1->id}", ['effective_date' => '2025-09-01'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v1->id}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v1->id}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v1->id}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v1->id}/transition", ['target_state' => 'published'], ['Authorization' => "Bearer {$token}"]);

        // Create and publish v2
        $v2Response = $this->postJson("/api/admissions-plans/{$plan->id}/versions", ['description' => 'v2'], ['Authorization' => "Bearer {$token}"]);
        $v2 = AdmissionsPlanVersion::find($v2Response->json('data.id'));
        $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$v2->id}", ['effective_date' => '2025-10-01'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v2->id}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v2->id}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v2->id}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$v2->id}/transition", ['target_state' => 'published'], ['Authorization' => "Bearer {$token}"]);

        // v1 should be superseded
        $v1->refresh();
        $this->assertEquals('superseded', $v1->state);

        // v2 should be published
        $v2->refresh();
        $this->assertEquals('published', $v2->state);

        // Only one published version should exist
        $publishedCount = AdmissionsPlanVersion::where('plan_id', $plan->id)->where('state', 'published')->count();
        $this->assertEquals(1, $publishedCount);
    }
}
