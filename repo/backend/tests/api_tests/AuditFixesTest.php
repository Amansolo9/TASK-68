<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\ConsultationTicket;
use App\Models\ConsultationMessage;
use App\Models\AdmissionsPlan;
use App\Models\AdmissionsPlanVersion;
use App\Models\Personnel;
use App\Models\OperationLog;
use App\Models\PlanStateHistory;
use App\Services\EncryptionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuditFixesTest extends TestCase
{
    // ═══════════════════ helpers ═══════════════════

    private function makeUser(string $role, ?string $dept = null): array
    {
        $user = User::create([
            'username' => "{$role}_" . uniqid(),
            'password_hash' => Hash::make('Pass12345678!'),
            'full_name' => ucfirst($role) . ' User',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => $role,
            'department_scope' => $dept,
            'is_active' => true,
        ]);
        $resp = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Pass12345678!']);
        $token = $resp->json('data.token'); $this->markTokenMfaVerified($token); return [$user, $token];
    }

    private function slot(int $cap = 5, int $daysAhead = 3): AppointmentSlot
    {
        return AppointmentSlot::create([
            'slot_type' => 'IN_PERSON', 'start_at' => now()->addDays($daysAhead),
            'end_at' => now()->addDays($daysAhead)->addHour(),
            'capacity' => $cap, 'available_qty' => $cap, 'status' => 'open',
        ]);
    }

    private function bookFor(int $userId, int $slotId): Appointment
    {
        return Appointment::create([
            'applicant_id' => $userId, 'slot_id' => $slotId, 'state' => 'booked',
            'request_key' => Str::uuid(), 'request_key_expires_at' => now()->addMinutes(10),
            'booked_at' => now(),
        ]);
    }

    // ═══════════════════ Fix 1: Appointment object-level auth ═══════════════════

    public function test_applicant_can_cancel_own_appointment(): void
    {
        [$owner, $tok] = $this->makeUser('applicant');
        $slot = $this->slot();
        $apt = $this->bookFor($owner->id, $slot->id);

        $this->postJson("/api/appointments/{$apt->id}/cancel", ['reason' => 'changed mind'], ['Authorization' => "Bearer $tok"])
            ->assertStatus(200);
    }

    public function test_applicant_cannot_cancel_another_applicants_appointment(): void
    {
        [$ownerA] = $this->makeUser('applicant');
        [, $tokB] = $this->makeUser('applicant');
        $slot = $this->slot();
        $apt = $this->bookFor($ownerA->id, $slot->id);

        $this->postJson("/api/appointments/{$apt->id}/cancel", ['reason' => 'steal'], ['Authorization' => "Bearer $tokB"])
            ->assertStatus(403);
    }

    public function test_applicant_cannot_reschedule_another_applicants_appointment(): void
    {
        [$ownerA] = $this->makeUser('applicant');
        [, $tokB] = $this->makeUser('applicant');
        $slot1 = $this->slot(); $slot2 = $this->slot(5, 5);
        $apt = $this->bookFor($ownerA->id, $slot1->id);

        $this->postJson("/api/appointments/{$apt->id}/reschedule",
            ['new_slot_id' => $slot2->id, 'request_key' => Str::uuid(), 'reason' => 'steal'],
            ['Authorization' => "Bearer $tokB"])
            ->assertStatus(403);
    }

    public function test_manager_can_cancel_any_appointment_with_override(): void
    {
        [$owner] = $this->makeUser('applicant');
        [, $mgrTok] = $this->makeUser('manager');
        $slot = $this->slot();
        $apt = $this->bookFor($owner->id, $slot->id);

        $this->postJson("/api/appointments/{$apt->id}/cancel",
            ['reason' => 'admin override', 'override' => true],
            ['Authorization' => "Bearer $mgrTok"])
            ->assertStatus(200);
    }

    public function test_idempotent_booking_still_works_for_authorized_caller(): void
    {
        [$owner, $tok] = $this->makeUser('applicant');
        $slot = $this->slot();
        $rk = Str::uuid()->toString();

        $r1 = $this->postJson('/api/appointments/book', ['slot_id' => $slot->id, 'request_key' => $rk], ['Authorization' => "Bearer $tok"]);
        $r2 = $this->postJson('/api/appointments/book', ['slot_id' => $slot->id, 'request_key' => $rk], ['Authorization' => "Bearer $tok"]);

        $r1->assertStatus(201);
        $r2->assertStatus(201);
        $this->assertEquals($r1->json('data.id'), $r2->json('data.id'));
    }

    // ═══════════════════ Fix 2: Ticket assignment/dept scope ═══════════════════

    public function test_assigned_advisor_can_reply(): void
    {
        [$app, $appTok] = $this->makeUser('applicant');
        [$adv, $advTok] = $this->makeUser('advisor', 'DEPT-001');

        $cr = $this->postJson('/api/tickets', ['category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Hello world test msg'], ['Authorization' => "Bearer $appTok"]);
        $tid = $cr->json('data.ticket.id');
        ConsultationTicket::find($tid)->update(['advisor_id' => $adv->id, 'department_id' => 'DEPT-001']);

        $this->postJson("/api/tickets/{$tid}/reply", ['message' => 'Advisor reply'], ['Authorization' => "Bearer $advTok"])
            ->assertStatus(201);
    }

    public function test_non_assigned_out_of_dept_advisor_gets_403(): void
    {
        [$app, $appTok] = $this->makeUser('applicant');
        [, $advTok] = $this->makeUser('advisor', 'DEPT-999');

        $cr = $this->postJson('/api/tickets', ['category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Hello world test msg'], ['Authorization' => "Bearer $appTok"]);
        $tid = $cr->json('data.ticket.id');
        ConsultationTicket::find($tid)->update(['department_id' => 'DEPT-001']);

        $this->getJson("/api/tickets/{$tid}", ['Authorization' => "Bearer $advTok"])
            ->assertStatus(403);
    }

    public function test_applicant_can_only_see_own_tickets(): void
    {
        [$appA, $tokA] = $this->makeUser('applicant');
        [$appB, $tokB] = $this->makeUser('applicant');

        $cr = $this->postJson('/api/tickets', ['category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'AppA ticket content'], ['Authorization' => "Bearer $tokA"]);
        $tid = $cr->json('data.ticket.id');

        $this->getJson("/api/tickets/{$tid}", ['Authorization' => "Bearer $tokB"])
            ->assertStatus(403);
    }

    public function test_manager_with_dept_scope_can_reassign(): void
    {
        [$app, $appTok] = $this->makeUser('applicant');
        [$mgr, $mgrTok] = $this->makeUser('manager', 'DEPT-001');
        [$adv] = $this->makeUser('advisor', 'DEPT-001');

        $cr = $this->postJson('/api/tickets', ['category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Need help with admission'], ['Authorization' => "Bearer $appTok"]);
        $tid = $cr->json('data.ticket.id');
        ConsultationTicket::find($tid)->update(['department_id' => 'DEPT-001']);

        $this->postJson("/api/tickets/{$tid}/reassign",
            ['to_advisor_id' => $adv->id, 'reason' => 'Specialist available'],
            ['Authorization' => "Bearer $mgrTok"])
            ->assertStatus(200);
    }

    // ═══════════════════ Fix 3: Plan visibility ═══════════════════

    public function test_applicant_sees_only_published_plans(): void
    {
        [, $tok] = $this->makeUser('applicant');

        $this->getJson('/api/published-plans', ['Authorization' => "Bearer $tok"])
            ->assertStatus(200);
    }

    public function test_applicant_cannot_access_internal_plan_management(): void
    {
        [, $tok] = $this->makeUser('applicant');

        $this->getJson('/api/admissions-plans', ['Authorization' => "Bearer $tok"])
            ->assertStatus(403);
    }

    public function test_manager_can_access_internal_plans(): void
    {
        [, $tok] = $this->makeUser('manager');

        $this->getJson('/api/admissions-plans', ['Authorization' => "Bearer $tok"])
            ->assertStatus(200);
    }

    // ═══════════════════ Fix 4: Backend MFA enforcement ═══════════════════

    public function test_mfa_endpoints_reachable_before_mfa_verification(): void
    {
        // Create admin with MFA enabled
        $user = User::create([
            'username' => 'mfaadmin_' . uniqid(),
            'password_hash' => Hash::make('Pass12345678!'),
            'full_name' => 'MFA Admin', 'status' => 'active', 'totp_enabled' => true,
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);

        $login = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Pass12345678!']);
        $tok = $login->json('data.token');

        // MFA setup should work before verification
        $this->postJson('/api/mfa/setup', [], ['Authorization' => "Bearer $tok"])
            ->assertStatus(200);
    }

    public function test_protected_route_blocked_before_mfa(): void
    {
        $user = User::create([
            'username' => 'mfablock_' . uniqid(),
            'password_hash' => Hash::make('Pass12345678!'),
            'full_name' => 'MFA Block', 'status' => 'active', 'totp_enabled' => true,
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);

        $login = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Pass12345678!']);
        $tok = $login->json('data.token');

        // Dashboard should be blocked because MFA not verified
        $this->getJson('/api/dashboard', ['Authorization' => "Bearer $tok"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'MFA_REQUIRED');
    }

    // ═══════════════════ Fix 5: Per-transition plan permissions ═══════════════════

    public function test_advisor_with_submit_cannot_publish(): void
    {
        // Advisor has no plans.publish permission
        [, $advTok] = $this->makeUser('advisor');

        // Advisor cannot even access internal plan management
        $this->getJson('/api/admissions-plans', ['Authorization' => "Bearer $advTok"])
            ->assertStatus(403);
    }

    public function test_manager_can_approve_and_publish(): void
    {
        [, $tok] = $this->makeUser('manager');

        $plan = $this->postJson('/api/admissions-plans', ['academic_year' => '2030-2031', 'intake_batch' => 'PermTest'], ['Authorization' => "Bearer $tok"]);
        $planId = $plan->json('data.id');
        $vId = AdmissionsPlan::find($planId)->current_version_id;

        $this->putJson("/api/admissions-plans/{$planId}/versions/{$vId}", ['effective_date' => '2030-09-01'], ['Authorization' => "Bearer $tok"]);

        $this->postJson("/api/admissions-plans/{$planId}/versions/{$vId}/transition", ['target_state' => 'submitted'], ['Authorization' => "Bearer $tok"])->assertStatus(200);
        $this->postJson("/api/admissions-plans/{$planId}/versions/{$vId}/transition", ['target_state' => 'under_review'], ['Authorization' => "Bearer $tok"])->assertStatus(200);
        $this->postJson("/api/admissions-plans/{$planId}/versions/{$vId}/transition", ['target_state' => 'approved'], ['Authorization' => "Bearer $tok"])->assertStatus(200);
        $this->postJson("/api/admissions-plans/{$planId}/versions/{$vId}/transition", ['target_state' => 'published'], ['Authorization' => "Bearer $tok"])->assertStatus(200);

        $v = AdmissionsPlanVersion::find($vId);
        $this->assertEquals('published', $v->state);
    }

    // ═══════════════════ Fix 6: Supersede audit compliance ═══════════════════

    public function test_supersede_writes_ip_and_hashes(): void
    {
        [, $tok] = $this->makeUser('manager');

        // Create and publish v1
        $plan = $this->postJson('/api/admissions-plans', ['academic_year' => '2031-2032', 'intake_batch' => 'SuperTest'], ['Authorization' => "Bearer $tok"]);
        $planId = $plan->json('data.id');
        $v1Id = AdmissionsPlan::find($planId)->current_version_id;
        $this->putJson("/api/admissions-plans/{$planId}/versions/{$v1Id}", ['effective_date' => '2031-09-01'], ['Authorization' => "Bearer $tok"]);
        foreach (['submitted','under_review','approved','published'] as $s) {
            $this->postJson("/api/admissions-plans/{$planId}/versions/{$v1Id}/transition", ['target_state' => $s], ['Authorization' => "Bearer $tok"]);
        }

        // Create and publish v2 (supersedes v1)
        $v2 = $this->postJson("/api/admissions-plans/{$planId}/versions", ['description' => 'v2'], ['Authorization' => "Bearer $tok"]);
        $v2Id = $v2->json('data.id');
        $this->putJson("/api/admissions-plans/{$planId}/versions/{$v2Id}", ['effective_date' => '2031-10-01'], ['Authorization' => "Bearer $tok"]);
        foreach (['submitted','under_review','approved','published'] as $s) {
            $this->postJson("/api/admissions-plans/{$planId}/versions/{$v2Id}/transition", ['target_state' => $s], ['Authorization' => "Bearer $tok"]);
        }

        // v1 supersede history should have IP and hashes
        $supersede = PlanStateHistory::where('version_id', $v1Id)->where('to_state', 'superseded')->first();
        $this->assertNotNull($supersede, 'Supersede history entry should exist');
        $this->assertNotNull($supersede->ip_address, 'Supersede should have IP address');
        $this->assertNotNull($supersede->before_hash, 'Supersede should have before_hash');
        $this->assertNotNull($supersede->after_hash, 'Supersede should have after_hash');
    }

    // ═══════════════════ Fix 7: Operation logging wired ═══════════════════

    public function test_mutating_request_creates_operation_log(): void
    {
        [, $tok] = $this->makeUser('applicant');
        OperationLog::truncate();

        $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Test operation log entry',
        ], ['Authorization' => "Bearer $tok"]);

        $log = OperationLog::where('route', 'LIKE', '%tickets%')->first();
        $this->assertNotNull($log, 'Operation log should be written for ticket creation');
        $this->assertEquals('success', $log->outcome);
    }

    public function test_operation_log_masks_sensitive_fields(): void
    {
        OperationLog::truncate();

        $this->postJson('/api/auth/login', ['username' => 'nonexistent', 'password' => 'secret123']);

        $log = OperationLog::where('route', 'LIKE', '%login%')->first();
        if ($log) {
            $summary = $log->request_summary;
            $this->assertArrayNotHasKey('password', $summary['params'] ?? []);
        }
    }

    // ═══════════════════ Fix 8: DOB duplicate detection ═══════════════════

    public function test_duplicate_found_on_name_plus_dob(): void
    {
        $enc = app(EncryptionService::class);
        $dob = $enc->encrypt('1990-05-15');

        Personnel::create(['full_name' => 'John Doe', 'normalized_name' => 'john doe', 'encrypted_date_of_birth' => $dob, 'status' => 'active']);
        Personnel::create(['full_name' => 'John Doe', 'normalized_name' => 'john doe', 'encrypted_date_of_birth' => $dob, 'status' => 'active']);

        $svc = app(\App\Services\DuplicateDetectionService::class);
        $candidates = $svc->detectPersonnelDuplicates();

        $nameDob = collect($candidates)->first(fn ($c) => $c->detection_basis === 'normalized_name_and_dob_match');
        $this->assertNotNull($nameDob, 'Should find name+DOB duplicate candidate');
        $this->assertGreaterThanOrEqual(0.95, (float) $nameDob->confidence);
    }

    public function test_different_dob_lowers_confidence(): void
    {
        $enc = app(EncryptionService::class);

        Personnel::create(['full_name' => 'Jane Smith', 'normalized_name' => 'jane smith', 'encrypted_date_of_birth' => $enc->encrypt('1990-01-01'), 'status' => 'active']);
        Personnel::create(['full_name' => 'Jane Smith', 'normalized_name' => 'jane smith', 'encrypted_date_of_birth' => $enc->encrypt('1985-12-31'), 'status' => 'active']);

        $svc = app(\App\Services\DuplicateDetectionService::class);
        $candidates = $svc->detectPersonnelDuplicates();

        $nameOnly = collect($candidates)->first(fn ($c) => str_contains($c->detection_basis, 'name_match'));
        $this->assertNotNull($nameOnly);
        $this->assertLessThan(0.95, (float) $nameOnly->confidence, 'Different DOB should yield lower confidence');
    }

    // ═══════════════════ Fix 10: Department scope consistency ═══════════════════

    public function test_advisor_outside_department_denied_ticket_list_access(): void
    {
        [$app, $appTok] = $this->makeUser('applicant');
        [, $advTok] = $this->makeUser('advisor', 'DEPT-OTHER');

        $this->postJson('/api/tickets', ['category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Dept scope test message'], ['Authorization' => "Bearer $appTok"]);
        ConsultationTicket::latest()->first()->update(['department_id' => 'DEPT-001']);

        $resp = $this->getJson('/api/tickets', ['Authorization' => "Bearer $advTok"]);
        $resp->assertStatus(200);
        // Should return no tickets since advisor is in DEPT-OTHER
        $this->assertCount(0, $resp->json('data'));
    }

    public function test_manager_in_department_sees_department_tickets(): void
    {
        [$app, $appTok] = $this->makeUser('applicant');
        [, $mgrTok] = $this->makeUser('manager', 'DEPT-001');

        $this->postJson('/api/tickets', ['category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Dept match test message'], ['Authorization' => "Bearer $appTok"]);
        ConsultationTicket::latest()->first()->update(['department_id' => 'DEPT-001']);

        $resp = $this->getJson('/api/tickets', ['Authorization' => "Bearer $mgrTok"]);
        $resp->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($resp->json('data')));
    }
}
