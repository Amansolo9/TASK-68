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

class PublishedPlanBrowsingTest extends TestCase
{
    private function auth(string $role): array
    {
        $user = User::create([
            'username' => "{$role}_" . uniqid(),
            'password_hash' => Hash::make('TestPass123!'),
            'full_name' => 'Test ' . ucfirst($role),
            'status' => 'active',
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        $response = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'TestPass123!']);
        $token = $response->json('data.token'); $this->markTokenMfaVerified($token); return [$user, $token];
    }

    private function createPublishedPlan(string $token, string $year = '2025-2026', string $batch = 'Fall 2025'): AdmissionsPlan
    {
        // Create plan
        $resp = $this->postJson('/api/admissions-plans', [
            'academic_year' => $year,
            'intake_batch' => $batch,
            'description' => 'Test plan',
        ], ['Authorization' => "Bearer {$token}"]);

        $plan = AdmissionsPlan::find($resp->json('data.id'));
        $version = $plan->currentVersion;

        // Add program with track
        $progResp = $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/programs", [
            'program_code' => 'CS-101',
            'program_name' => 'Computer Science',
            'planned_capacity' => 100,
            'capacity_notes' => 'Limited lab seats',
        ], ['Authorization' => "Bearer {$token}"]);

        $programId = $progResp->json('data.id');

        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/programs/{$programId}/tracks", [
            'track_code' => 'CS-FT',
            'track_name' => 'Full-Time',
            'planned_capacity' => 60,
            'capacity_notes' => 'Morning sessions only',
            'admission_criteria' => 'GPA >= 3.0',
        ], ['Authorization' => "Bearer {$token}"]);

        // Publish: set effective date and transition through workflow
        $this->putJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}", [
            'effective_date' => '2025-09-01',
        ], ['Authorization' => "Bearer {$token}"]);

        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer {$token}"]);
        $this->postJson("/api/admissions-plans/{$plan->id}/versions/{$version->id}/transition", ['target_state' => 'published'], ['Authorization' => "Bearer {$token}"]);

        return $plan->fresh();
    }

    // --- Applicant can browse published plans ---

    public function test_applicant_can_retrieve_published_plans(): void
    {
        [$manager, $mToken] = $this->auth('manager');
        $plan = $this->createPublishedPlan($mToken);

        [$applicant, $aToken] = $this->auth('applicant');

        $response = $this->getJson('/api/published-plans', [
            'Authorization' => "Bearer {$aToken}",
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        // Verify published version data is present with programs and tracks
        $found = collect($data)->firstWhere('id', $plan->id);
        $this->assertNotNull($found);
        $this->assertNotEmpty($found['versions']);
        $pubVersion = collect($found['versions'])->firstWhere('state', 'published');
        $this->assertNotNull($pubVersion);
        $this->assertNotEmpty($pubVersion['programs']);
        $this->assertNotEmpty($pubVersion['programs'][0]['tracks']);
    }

    public function test_applicant_can_retrieve_published_plan_detail(): void
    {
        [$manager, $mToken] = $this->auth('manager');
        $plan = $this->createPublishedPlan($mToken);

        [$applicant, $aToken] = $this->auth('applicant');

        $response = $this->getJson("/api/published-plans/{$plan->id}", [
            'Authorization' => "Bearer {$aToken}",
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($plan->id, $data['id']);
        $this->assertEquals($plan->academic_year, $data['academic_year']);
        $this->assertNotNull($data['published_version']);
        $this->assertNotEmpty($data['published_version']['programs']);
        $this->assertEquals('CS-101', $data['published_version']['programs'][0]['program_code']);
        $this->assertNotEmpty($data['published_version']['programs'][0]['tracks']);
        $this->assertEquals('CS-FT', $data['published_version']['programs'][0]['tracks'][0]['track_code']);
    }

    public function test_applicant_cannot_access_internal_plan_endpoints(): void
    {
        [$manager, $mToken] = $this->auth('manager');
        $plan = $this->createPublishedPlan($mToken);

        [$applicant, $aToken] = $this->auth('applicant');

        // Internal plan list should be forbidden
        $response = $this->getJson('/api/admissions-plans', [
            'Authorization' => "Bearer {$aToken}",
        ]);
        $response->assertStatus(403);

        // Internal plan detail should be forbidden
        $response = $this->getJson("/api/admissions-plans/{$plan->id}", [
            'Authorization' => "Bearer {$aToken}",
        ]);
        $response->assertStatus(403);
    }

    public function test_applicant_cannot_see_draft_plan_via_published_endpoints(): void
    {
        [$manager, $mToken] = $this->auth('manager');

        // Create plan but don't publish it (stays in draft)
        $resp = $this->postJson('/api/admissions-plans', [
            'academic_year' => '2026-2027',
            'intake_batch' => 'Spring 2027',
            'description' => 'Draft only plan',
        ], ['Authorization' => "Bearer {$mToken}"]);

        $planId = $resp->json('data.id');

        [$applicant, $aToken] = $this->auth('applicant');

        // Published plans list should not include this draft plan
        $response = $this->getJson('/api/published-plans', [
            'Authorization' => "Bearer {$aToken}",
        ]);
        $response->assertStatus(200);

        $planIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($planId, $planIds);

        // Direct detail should return 404 (no published version)
        $response = $this->getJson("/api/published-plans/{$planId}", [
            'Authorization' => "Bearer {$aToken}",
        ]);
        $response->assertStatus(404);
    }

    // --- Year/intake filtering ---

    public function test_published_plans_filter_by_year(): void
    {
        [$manager, $mToken] = $this->auth('manager');
        $this->createPublishedPlan($mToken, '2025-2026', 'Fall 2025');
        $this->createPublishedPlan($mToken, '2026-2027', 'Fall 2026');

        [$applicant, $aToken] = $this->auth('applicant');

        $response = $this->getJson('/api/published-plans?academic_year=2025-2026', [
            'Authorization' => "Bearer {$aToken}",
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach ($data as $plan) {
            $this->assertEquals('2025-2026', $plan['academic_year']);
        }
    }

    public function test_published_plans_filter_by_intake_batch(): void
    {
        [$manager, $mToken] = $this->auth('manager');
        $this->createPublishedPlan($mToken, '2025-2026', 'Fall 2025');
        $this->createPublishedPlan($mToken, '2025-2026', 'Spring 2026');

        [$applicant, $aToken] = $this->auth('applicant');

        $response = $this->getJson('/api/published-plans?intake_batch=Spring', [
            'Authorization' => "Bearer {$aToken}",
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach ($data as $plan) {
            $this->assertStringContainsString('Spring', $plan['intake_batch']);
        }
    }

    // --- Published plan detail shows tracks and capacity ---

    public function test_published_plan_detail_includes_tracks_and_capacity(): void
    {
        [$manager, $mToken] = $this->auth('manager');
        $plan = $this->createPublishedPlan($mToken);

        [$applicant, $aToken] = $this->auth('applicant');

        $response = $this->getJson("/api/published-plans/{$plan->id}", [
            'Authorization' => "Bearer {$aToken}",
        ]);

        $response->assertStatus(200);
        $pubVersion = $response->json('data.published_version');
        $program = $pubVersion['programs'][0];
        $this->assertEquals(100, $program['planned_capacity']);
        $this->assertEquals('Limited lab seats', $program['capacity_notes']);

        $track = $program['tracks'][0];
        $this->assertEquals(60, $track['planned_capacity']);
        $this->assertEquals('Morning sessions only', $track['capacity_notes']);
        $this->assertEquals('GPA >= 3.0', $track['admission_criteria']);
    }
}
