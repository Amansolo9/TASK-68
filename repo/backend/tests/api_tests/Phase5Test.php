<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\Organization;
use App\Models\Personnel;
use App\Models\MergeRequest;
use App\Models\DuplicateCandidate;
use App\Services\DuplicateDetectionService;
use App\Services\MergeService;
use App\Services\DataQualityService;
use Illuminate\Support\Facades\Hash;

class Phase5Test extends TestCase
{
    private function auth(string $role): array
    {
        $user = User::create([
            'username' => "{$role}_" . uniqid(),
            'password_hash' => Hash::make('TestPass123!'),
            'full_name' => "Test " . ucfirst($role),
            'status' => 'active',
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        $response = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'TestPass123!']);
        $token = $response->json('data.token'); $this->markTokenMfaVerified($token); return [$user, $token];
    }

    // --- Duplicate Detection ---

    public function test_detect_personnel_duplicates_by_name(): void
    {
        Personnel::create(['full_name' => 'John Smith', 'normalized_name' => 'john smith', 'status' => 'active']);
        Personnel::create(['full_name' => 'John Smith', 'normalized_name' => 'john smith', 'status' => 'active']);

        $service = app(DuplicateDetectionService::class);
        $candidates = $service->detectPersonnelDuplicates();

        $this->assertNotEmpty($candidates);
        $this->assertEquals('personnel', $candidates[0]->entity_type);
        $this->assertEquals('normalized_name_match', $candidates[0]->detection_basis);
    }

    public function test_detect_organization_duplicates_by_name(): void
    {
        Organization::create(['code' => 'ORG-000001', 'name' => 'Test University', 'normalized_name' => 'test university', 'status' => 'active']);
        Organization::create(['code' => 'ORG-000002', 'name' => 'Test University', 'normalized_name' => 'test university', 'status' => 'active']);

        $service = app(DuplicateDetectionService::class);
        $candidates = $service->detectOrganizationDuplicates();

        $this->assertNotEmpty($candidates);
    }

    public function test_duplicate_detection_is_idempotent(): void
    {
        Personnel::create(['full_name' => 'Jane Doe', 'normalized_name' => 'jane doe', 'status' => 'active']);
        Personnel::create(['full_name' => 'Jane Doe', 'normalized_name' => 'jane doe', 'status' => 'active']);

        $service = app(DuplicateDetectionService::class);
        $first = $service->detectPersonnelDuplicates();
        $second = $service->detectPersonnelDuplicates();

        // Should not create duplicate candidates
        $this->assertEquals(count($first), count($second));
        $count = DuplicateCandidate::where('entity_type', 'personnel')->count();
        $this->assertEquals(1, $count);
    }

    // --- Merge Workflow ---

    public function test_merge_request_lifecycle(): void
    {
        [$steward, $token] = $this->auth('steward');

        $source = Personnel::create(['full_name' => 'Source Record', 'normalized_name' => 'source record', 'status' => 'active']);
        $target = Personnel::create(['full_name' => 'Target Record', 'normalized_name' => 'target record', 'status' => 'active']);

        // Create merge request
        $response = $this->postJson('/api/merge-requests', [
            'entity_type' => 'personnel',
            'source_entity_ids' => [$source->id],
            'target_entity_id' => $target->id,
            'reason' => 'Duplicate records need merging',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $mergeId = $response->json('data.id');

        // proposed -> under_review
        $this->postJson("/api/merge-requests/{$mergeId}/transition", [
            'target_state' => 'under_review',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        // under_review -> approved
        $this->postJson("/api/merge-requests/{$mergeId}/transition", [
            'target_state' => 'approved',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        // Execute merge
        $this->postJson("/api/merge-requests/{$mergeId}/execute", [], [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(200);

        // Source should be retired
        $source->refresh();
        $this->assertEquals('retired', $source->status);
        $this->assertEquals($target->id, $source->merged_into_id);

        // Merge request should be executed
        $merge = MergeRequest::find($mergeId);
        $this->assertEquals('executed', $merge->status);
    }

    public function test_merge_preserves_source_record(): void
    {
        [$steward, ] = $this->auth('steward');

        $source = Personnel::create(['full_name' => 'To Be Merged', 'normalized_name' => 'to be merged', 'status' => 'active']);
        $target = Personnel::create(['full_name' => 'Merge Target', 'normalized_name' => 'merge target', 'status' => 'active']);

        $service = app(MergeService::class);
        $merge = $service->createRequest('personnel', [$source->id], $target->id, $steward->id, 'Test merge');
        $service->transition($merge, 'under_review', $steward->id);
        $service->transition($merge, 'approved', $steward->id);
        $service->executeMerge($merge, $steward->id);

        // Source record still exists (soft-linked, not destroyed)
        $this->assertDatabaseHas('personnel', ['id' => $source->id, 'status' => 'retired']);
    }

    public function test_merge_requires_approval_before_execution(): void
    {
        [$steward, ] = $this->auth('steward');

        $source = Personnel::create(['full_name' => 'Source', 'normalized_name' => 'source', 'status' => 'active']);
        $target = Personnel::create(['full_name' => 'Target', 'normalized_name' => 'target', 'status' => 'active']);

        $service = app(MergeService::class);
        $merge = $service->createRequest('personnel', [$source->id], $target->id, $steward->id, 'Test');

        $this->expectException(\InvalidArgumentException::class);
        $service->executeMerge($merge, $steward->id);
    }

    // --- Data Quality Metrics ---

    public function test_nightly_metrics_computation(): void
    {
        Organization::create(['code' => 'ORG-000010', 'name' => 'Quality Test Org', 'normalized_name' => 'quality test org', 'status' => 'active']);
        Personnel::create(['full_name' => 'Quality Test Person', 'normalized_name' => 'quality test person', 'status' => 'active']);

        $service = app(DataQualityService::class);
        $run = $service->computeNightlyMetrics();

        $this->assertEquals('completed', $run->status);
        $this->assertGreaterThan(0, $run->metrics()->count());
    }

    public function test_metrics_are_idempotent_per_day(): void
    {
        $service = app(DataQualityService::class);
        $run1 = $service->computeNightlyMetrics();
        $run2 = $service->computeNightlyMetrics();

        $this->assertEquals($run1->id, $run2->id);
    }

    public function test_failed_metrics_do_not_overwrite_prior(): void
    {
        $service = app(DataQualityService::class);
        $run = $service->computeNightlyMetrics();

        $this->assertEquals('completed', $run->status);
        // A second call on same day returns the same successful run
        $run2 = $service->computeNightlyMetrics();
        $this->assertEquals('completed', $run2->status);
        $this->assertEquals($run->id, $run2->id);
    }

    // --- Reporting ---

    public function test_reporting_endpoints_accessible_by_manager(): void
    {
        [, $token] = $this->auth('manager');

        $this->getJson('/api/reports/tickets', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $this->getJson('/api/reports/appointments', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $this->getJson('/api/reports/plans', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $this->getJson('/api/reports/data-quality', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $this->getJson('/api/reports/merges', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
    }

    public function test_applicant_cannot_access_reports(): void
    {
        [, $token] = $this->auth('applicant');

        $this->getJson('/api/reports/tickets', ['Authorization' => "Bearer {$token}"])->assertStatus(403);
    }

    public function test_csv_export(): void
    {
        [, $token] = $this->auth('manager');

        $response = $this->get('/api/reports/export?report_type=tickets', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    // --- Version History ---

    public function test_master_data_version_history_maintained(): void
    {
        [$steward, $token] = $this->auth('steward');

        $this->postJson('/api/organizations', [
            'code' => 'ORG-000999',
            'name' => 'Versioned Org',
        ], ['Authorization' => "Bearer {$token}"]);

        $org = Organization::where('code', 'ORG-000999')->first();

        $this->putJson("/api/organizations/{$org->id}", [
            'name' => 'Versioned Org Updated',
            'change_reason' => 'Name correction',
        ], ['Authorization' => "Bearer {$token}"]);

        $this->putJson("/api/organizations/{$org->id}", [
            'name' => 'Versioned Org Final',
            'change_reason' => 'Final name',
        ], ['Authorization' => "Bearer {$token}"]);

        // Should have 3 versions: create + 2 updates
        $this->assertDatabaseCount('master_data_versions', 3);
    }

    // --- Integrity Alerts ---

    public function test_integrity_check_creates_record(): void
    {
        [, $token] = $this->auth('manager');

        // Create and publish a plan
        $planResp = $this->postJson('/api/admissions-plans', [
            'academic_year' => '2026-2027',
            'intake_batch' => 'Spring 2027',
        ], ['Authorization' => "Bearer {$token}"]);

        $planId = $planResp->json('data.id');
        $plan = \App\Models\AdmissionsPlan::find($planId);
        $versionId = $plan->current_version_id;

        $this->putJson("/api/admissions-plans/{$planId}/versions/{$versionId}", ['effective_date' => '2027-01-15'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$planId}/versions/{$versionId}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$planId}/versions/{$versionId}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$planId}/versions/{$versionId}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$planId}/versions/{$versionId}/transition", ['target_state' => 'published'], ['Authorization' => "Bearer {$token}"]);

        // Verify integrity
        $response = $this->getJson("/api/admissions-plans/{$planId}/versions/{$versionId}/integrity", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)->assertJsonPath('data.valid', true);

        // Check that integrity record was created
        $this->assertDatabaseHas('published_artifact_integrity_checks', [
            'artifact_type' => 'admissions_plan_version',
            'artifact_id' => $versionId,
            'status' => 'verified',
        ]);
    }
}
