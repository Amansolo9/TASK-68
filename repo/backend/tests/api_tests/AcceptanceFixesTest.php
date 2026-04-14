<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\ConsultationTicket;
use App\Models\ConsultationMessage;
use App\Models\TicketQualityReview;
use App\Models\OperationLog;
use App\Services\TicketService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AcceptanceFixesTest extends TestCase
{
    // ═══════════════════ Helpers ═══════════════════

    private function makeUser(string $role, ?string $dept = null, array $contentPerms = []): array
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
            'content_permissions' => !empty($contentPerms) ? $contentPerms : null,
            'is_active' => true,
        ]);
        $resp = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Pass12345678!']);
        $token = $resp->json('data.token'); $this->markTokenMfaVerified($token); return [$user, $token];
    }

    private function slot(int $cap = 5, int $daysAhead = 3, ?int $advisorId = null, ?string $deptId = null): AppointmentSlot
    {
        return AppointmentSlot::create([
            'slot_type' => 'IN_PERSON',
            'start_at' => now()->addDays($daysAhead),
            'end_at' => now()->addDays($daysAhead)->addHour(),
            'capacity' => $cap,
            'available_qty' => $cap,
            'status' => 'open',
            'advisor_id' => $advisorId,
            'department_id' => $deptId,
        ]);
    }

    private function bookFor(int $userId, int $slotId): Appointment
    {
        return Appointment::create([
            'applicant_id' => $userId,
            'slot_id' => $slotId,
            'state' => 'booked',
            'request_key' => Str::uuid(),
            'request_key_expires_at' => now()->addMinutes(10),
            'booked_at' => now(),
        ]);
    }

    // ═══════════════════ Fix 1: Advisor appointment management ═══════════════════

    public function test_advisor_can_reschedule_appointment_for_assigned_slot(): void
    {
        [$applicant] = $this->makeUser('applicant');
        [$advisor, $advToken] = $this->makeUser('advisor', 'DEPT-001');
        $slot1 = $this->slot(5, 3, $advisor->id, 'DEPT-001');
        $slot2 = $this->slot(5, 5);
        $apt = $this->bookFor($applicant->id, $slot1->id);

        $response = $this->postJson("/api/appointments/{$apt->id}/reschedule", [
            'new_slot_id' => $slot2->id,
            'request_key' => Str::uuid()->toString(),
            'reason' => 'Advisor schedule change',
        ], ['Authorization' => "Bearer $advToken"]);

        $response->assertStatus(200);
    }

    public function test_advisor_can_cancel_appointment_for_assigned_slot(): void
    {
        [$applicant] = $this->makeUser('applicant');
        [$advisor, $advToken] = $this->makeUser('advisor', 'DEPT-001');
        $slot = $this->slot(5, 3, $advisor->id, 'DEPT-001');
        $apt = $this->bookFor($applicant->id, $slot->id);

        $response = $this->postJson("/api/appointments/{$apt->id}/cancel", [
            'reason' => 'Advisor cancelled',
        ], ['Authorization' => "Bearer $advToken"]);

        $response->assertStatus(200);
    }

    public function test_advisor_cannot_cancel_unrelated_appointment(): void
    {
        [$applicant] = $this->makeUser('applicant');
        [$advisor, $advToken] = $this->makeUser('advisor', 'DEPT-001');
        // Slot has no advisor_id and different department
        $slot = $this->slot(5, 3, null, 'DEPT-999');
        $apt = $this->bookFor($applicant->id, $slot->id);

        $response = $this->postJson("/api/appointments/{$apt->id}/cancel", [
            'reason' => 'Should not work',
        ], ['Authorization' => "Bearer $advToken"]);

        $response->assertStatus(403);
    }

    public function test_applicant_cross_user_cancel_returns_403(): void
    {
        [$ownerA] = $this->makeUser('applicant');
        [, $tokB] = $this->makeUser('applicant');
        $slot = $this->slot();
        $apt = $this->bookFor($ownerA->id, $slot->id);

        $this->postJson("/api/appointments/{$apt->id}/cancel", [
            'reason' => 'Not mine',
        ], ['Authorization' => "Bearer $tokB"])
            ->assertStatus(403);
    }

    public function test_manager_override_still_succeeds(): void
    {
        [$applicant] = $this->makeUser('applicant');
        [, $mgrToken] = $this->makeUser('manager');
        $slot = $this->slot(5, 3);
        $apt = $this->bookFor($applicant->id, $slot->id);

        $this->postJson("/api/appointments/{$apt->id}/cancel", [
            'reason' => 'Manager override',
            'override' => true,
        ], ['Authorization' => "Bearer $mgrToken"])
            ->assertStatus(200);
    }

    public function test_admin_override_still_succeeds(): void
    {
        [$applicant] = $this->makeUser('applicant');
        [, $adminToken] = $this->makeUser('admin');
        $slot = $this->slot(5, 3);
        $apt = $this->bookFor($applicant->id, $slot->id);

        $this->postJson("/api/appointments/{$apt->id}/reschedule", [
            'new_slot_id' => $this->slot(5, 5)->id,
            'request_key' => Str::uuid()->toString(),
            'reason' => 'Admin reschedule',
        ], ['Authorization' => "Bearer $adminToken"])
            ->assertStatus(200);
    }

    // ═══════════════════ Fix 2: Operation log sensitive data redaction ═══════════════════

    public function test_password_reset_does_not_persist_sensitive_fields(): void
    {
        [$admin, $adminToken] = $this->makeUser('admin');
        [$target] = $this->makeUser('applicant');
        OperationLog::truncate();

        $this->postJson("/api/users/{$target->id}/reset-password", [
            'new_password' => 'NewSecurePass123!',
            'password_confirmation' => 'NewSecurePass123!',
        ], ['Authorization' => "Bearer $adminToken"]);

        $log = OperationLog::where('route', 'LIKE', '%reset-password%')->first();
        $this->assertNotNull($log, 'Reset-password operation should be logged');
        $params = $log->request_summary['params'] ?? [];
        $this->assertArrayNotHasKey('new_password', $params);
        $this->assertArrayNotHasKey('password_confirmation', $params);
        // Flattened check: no password values anywhere
        $json = json_encode($params);
        $this->assertStringNotContainsString('NewSecurePass123!', $json);
    }

    public function test_dob_id_payloads_are_redacted(): void
    {
        OperationLog::truncate();

        // Simulate a login with extra DOB/ID fields (they'd be redacted if present)
        $this->postJson('/api/auth/login', [
            'username' => 'test_' . uniqid(),
            'password' => 'SomePass123!',
            'date_of_birth' => '1990-01-15',
            'government_id' => '123-45-6789',
        ]);

        $log = OperationLog::where('route', 'LIKE', '%login%')->first();
        if ($log) {
            $params = $log->request_summary['params'] ?? [];
            // Login is allowlisted - only username should be captured
            $this->assertArrayNotHasKey('password', $params);
            $this->assertArrayNotHasKey('date_of_birth', $params);
            $this->assertArrayNotHasKey('government_id', $params);
        }
    }

    public function test_nested_payload_redaction(): void
    {
        OperationLog::truncate();

        [$admin, $adminToken] = $this->makeUser('admin');
        [$target] = $this->makeUser('applicant');

        // Update user with nested sensitive data
        $this->putJson("/api/users/{$target->id}", [
            'full_name' => 'Updated Name',
            'profile' => [
                'date_of_birth' => '1990-05-20',
                'government_id' => '987-65-4321',
            ],
        ], ['Authorization' => "Bearer $adminToken"]);

        $log = OperationLog::where('route', 'LIKE', "%users/{$target->id}%")
            ->where('method', 'PUT')
            ->first();

        if ($log) {
            $json = json_encode($log->request_summary);
            $this->assertStringNotContainsString('1990-05-20', $json);
            $this->assertStringNotContainsString('987-65-4321', $json);
        }
    }

    public function test_non_sensitive_action_context_still_logged(): void
    {
        OperationLog::truncate();

        [$applicant, $appToken] = $this->makeUser('applicant');

        $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'Test non-sensitive logging',
        ], ['Authorization' => "Bearer $appToken"]);

        $log = OperationLog::where('route', 'LIKE', '%tickets%')
            ->where('method', 'POST')
            ->first();

        $this->assertNotNull($log);
        $params = $log->request_summary['params'] ?? [];
        $this->assertEquals('GENERAL', $params['category_tag'] ?? null);
        $this->assertEquals('Normal', $params['priority'] ?? null);
    }

    // ═══════════════════ Fix 3: Content-level RBAC enforcement ═══════════════════

    public function test_content_permission_grants_access(): void
    {
        // User with tickets content permission can access tickets
        [$user, $token] = $this->makeUser('applicant', null, ['tickets.view']);

        $this->getJson('/api/tickets', ['Authorization' => "Bearer $token"])
            ->assertStatus(200);
    }

    public function test_missing_content_permission_denies_access(): void
    {
        // User with restrictive content permissions that don't include tickets
        $user = User::create([
            'username' => 'restricted_' . uniqid(),
            'password_hash' => Hash::make('Pass12345678!'),
            'full_name' => 'Restricted User',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => 'applicant',
            'content_permissions' => ['plans.view'], // has content perms but NOT tickets.view
            'is_active' => true,
        ]);
        $resp = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Pass12345678!']);
        $token = $resp->json('data.token');

        $this->getJson('/api/tickets', ['Authorization' => "Bearer $token"])
            ->assertStatus(403);
    }

    public function test_no_content_restrictions_defaults_to_role_access(): void
    {
        // User with no content_permissions at all should still have role-level access
        [$user, $token] = $this->makeUser('applicant');

        $this->getJson('/api/tickets', ['Authorization' => "Bearer $token"])
            ->assertStatus(200);
    }

    public function test_admin_bypasses_content_restrictions(): void
    {
        [$admin, $adminToken] = $this->makeUser('admin');

        $this->getJson('/api/tickets', ['Authorization' => "Bearer $adminToken"])
            ->assertStatus(200);
    }

    public function test_content_permission_enforced_on_plans_routes(): void
    {
        // Manager with content restrictions that exclude plans.manage
        $user = User::create([
            'username' => 'mgr_noplans_' . uniqid(),
            'password_hash' => Hash::make('Pass12345678!'),
            'full_name' => 'Manager No Plans',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => 'manager',
            'content_permissions' => ['tickets.view', 'reports.view'], // has content perms but NOT plans.manage
            'is_active' => true,
        ]);
        $resp = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Pass12345678!']);
        $token = $resp->json('data.token');

        $this->getJson('/api/admissions-plans', ['Authorization' => "Bearer $token"])
            ->assertStatus(403);
    }

    public function test_content_permission_enforced_on_appointments_routes(): void
    {
        // Applicant with content restrictions that exclude appointments.view
        $user = User::create([
            'username' => 'app_noappt_' . uniqid(),
            'password_hash' => Hash::make('Pass12345678!'),
            'full_name' => 'Applicant No Appts',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => 'applicant',
            'content_permissions' => ['tickets.view'], // has content perms but NOT appointments.view
            'is_active' => true,
        ]);
        $resp = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Pass12345678!']);
        $token = $resp->json('data.token');

        $this->getJson('/api/appointments/my', ['Authorization' => "Bearer $token"])
            ->assertStatus(403);
    }

    public function test_content_permission_enforced_on_reports_routes(): void
    {
        // Manager with content restrictions that exclude reports.view
        $user = User::create([
            'username' => 'mgr_norep_' . uniqid(),
            'password_hash' => Hash::make('Pass12345678!'),
            'full_name' => 'Manager No Reports',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => 'manager',
            'content_permissions' => ['tickets.view', 'plans.manage'], // has content perms but NOT reports.view
            'is_active' => true,
        ]);
        $resp = $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'Pass12345678!']);
        $token = $resp->json('data.token');

        $this->getJson('/api/reports/tickets', ['Authorization' => "Bearer $token"])
            ->assertStatus(403);
    }

    public function test_content_permission_allows_when_matching(): void
    {
        // Manager with content permission that includes reports.view
        [$mgr, $mgrToken] = $this->makeUser('manager', null, ['reports.view', 'plans.manage', 'tickets.view']);

        $this->getJson('/api/reports/tickets', ['Authorization' => "Bearer $mgrToken"])
            ->assertStatus(200);
    }

    // ═══════════════════ Fix 4: SLA 1-business-day semantics ═══════════════════

    public function test_normal_priority_sla_is_one_business_day(): void
    {
        $service = app(TicketService::class);

        // Monday 10:00 -> Tuesday 10:00 (1 business day)
        $this->travelTo(now()->next('Monday')->setTime(10, 0));
        $deadline = $service->computeSlaDeadline('Normal');

        $expected = now()->next('Tuesday')->setTime(10, 0);
        $this->assertEquals(
            $expected->format('Y-m-d H:i'),
            $deadline->format('Y-m-d H:i'),
            'Normal priority SLA should be 1 business day (same time next business day)'
        );
    }

    public function test_normal_priority_sla_spans_weekend(): void
    {
        $service = app(TicketService::class);

        // Friday 14:00 -> Monday 14:00 (skips weekend)
        $this->travelTo(now()->next('Friday')->setTime(14, 0));
        $deadline = $service->computeSlaDeadline('Normal');

        $expected = now()->next('Monday')->setTime(14, 0);
        $this->assertEquals(
            $expected->format('Y-m-d H:i'),
            $deadline->format('Y-m-d H:i'),
            'Normal priority SLA should skip weekends'
        );
    }

    public function test_high_priority_sla_within_business_hours(): void
    {
        $service = app(TicketService::class);

        // Monday 09:00 -> Monday 11:00 (2 business hours)
        $monday = now()->next('Monday')->setTime(9, 0);
        $this->travelTo($monday);
        $deadline = $service->computeSlaDeadline('High');

        $expected = $monday->copy()->setTime(11, 0);
        $this->assertEquals(
            $expected->format('Y-m-d H:i'),
            $deadline->format('Y-m-d H:i'),
            'High priority SLA should be 2 business hours'
        );
    }

    public function test_high_priority_sla_spans_overnight(): void
    {
        $service = app(TicketService::class);

        // Monday 16:00 -> Tuesday 09:00 (1h left Monday + 1h Tuesday = 2h)
        $this->travelTo(now()->next('Monday')->setTime(16, 0));
        $deadline = $service->computeSlaDeadline('High');

        $expected = now()->next('Tuesday')->setTime(9, 0);
        $this->assertEquals(
            $expected->format('Y-m-d H:i'),
            $deadline->format('Y-m-d H:i'),
            'High priority SLA should span overnight correctly'
        );
    }

    public function test_sla_config_keys_are_loaded(): void
    {
        $this->assertNotNull(config('sla.high_priority_hours'));
        $this->assertNotNull(config('sla.normal_priority_days'));
        $this->assertNotNull(config('sla.business_hours_start'));
        $this->assertNotNull(config('sla.business_hours_end'));
        $this->assertNotNull(config('sla.business_days'));
    }

    // ═══════════════════ Fix 6: Quality review lock on all mutations ═══════════════════

    public function test_locked_ticket_cannot_be_reassigned(): void
    {
        [$app, $appToken] = $this->makeUser('applicant');
        [$mgr, $mgrToken] = $this->makeUser('manager', 'DEPT-001');
        [$adv] = $this->makeUser('advisor', 'DEPT-001');

        $cr = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Test lock reassign',
        ], ['Authorization' => "Bearer $appToken"]);
        $tid = $cr->json('data.ticket.id');
        $ticket = ConsultationTicket::find($tid);
        $ticket->update(['department_id' => 'DEPT-001', 'advisor_id' => $adv->id, 'status' => 'closed', 'closed_at' => now()]);

        // Lock the ticket via quality review
        TicketQualityReview::create([
            'sampled_week' => '2026-W15',
            'advisor_id' => $adv->id,
            'ticket_id' => $tid,
            'review_state' => 'pending',
            'locked_at' => now(),
        ]);

        $response = $this->postJson("/api/tickets/{$tid}/reassign", [
            'to_advisor_id' => $mgr->id,
            'reason' => 'Should be blocked',
        ], ['Authorization' => "Bearer $mgrToken"]);

        $response->assertStatus(409);
    }

    public function test_locked_ticket_cannot_have_status_changed(): void
    {
        [$app, $appToken] = $this->makeUser('applicant');
        [$adv, $advToken] = $this->makeUser('advisor', 'DEPT-001');

        $cr = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Test lock transition',
        ], ['Authorization' => "Bearer $appToken"]);
        $tid = $cr->json('data.ticket.id');
        $ticket = ConsultationTicket::find($tid);
        $ticket->update(['department_id' => 'DEPT-001', 'advisor_id' => $adv->id]);

        TicketQualityReview::create([
            'sampled_week' => '2026-W15',
            'advisor_id' => $adv->id,
            'ticket_id' => $tid,
            'review_state' => 'pending',
            'locked_at' => now(),
        ]);

        $response = $this->postJson("/api/tickets/{$tid}/transition", [
            'status' => 'triaged',
        ], ['Authorization' => "Bearer $advToken"]);

        $response->assertStatus(409);
    }

    public function test_locked_ticket_cannot_receive_transcript_edits(): void
    {
        [$app, $appToken] = $this->makeUser('applicant');
        [$adv, $advToken] = $this->makeUser('advisor', 'DEPT-001');

        $cr = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Test lock reply',
        ], ['Authorization' => "Bearer $appToken"]);
        $tid = $cr->json('data.ticket.id');
        $ticket = ConsultationTicket::find($tid);
        $ticket->update(['department_id' => 'DEPT-001', 'advisor_id' => $adv->id]);

        TicketQualityReview::create([
            'sampled_week' => '2026-W15',
            'advisor_id' => $adv->id,
            'ticket_id' => $tid,
            'review_state' => 'pending',
            'locked_at' => now(),
        ]);

        $response = $this->postJson("/api/tickets/{$tid}/reply", [
            'message' => 'Should be blocked by lock',
        ], ['Authorization' => "Bearer $advToken"]);

        $response->assertStatus(409);
    }

    public function test_completed_review_unlocks_ticket(): void
    {
        [$app, $appToken] = $this->makeUser('applicant');
        [$adv, $advToken] = $this->makeUser('advisor', 'DEPT-001');

        $cr = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL', 'priority' => 'Normal', 'message' => 'Test completed review unlock',
        ], ['Authorization' => "Bearer $appToken"]);
        $tid = $cr->json('data.ticket.id');
        $ticket = ConsultationTicket::find($tid);
        $ticket->update(['department_id' => 'DEPT-001', 'advisor_id' => $adv->id]);

        // Create a completed review (should not lock)
        TicketQualityReview::create([
            'sampled_week' => '2026-W15',
            'advisor_id' => $adv->id,
            'ticket_id' => $tid,
            'review_state' => 'completed',
            'locked_at' => now(),
        ]);

        $response = $this->postJson("/api/tickets/{$tid}/reply", [
            'message' => 'Should be allowed after review complete',
        ], ['Authorization' => "Bearer $advToken"]);

        $response->assertStatus(201);
    }

    // ═══════════════════ Fix 7: Plans report export ═══════════════════

    public function test_plans_export_succeeds(): void
    {
        [, $mgrToken] = $this->makeUser('manager');

        $response = $this->get('/api/reports/export?report_type=plans', [
            'Authorization' => "Bearer $mgrToken",
        ]);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_invalid_report_type_returns_422(): void
    {
        [, $mgrToken] = $this->makeUser('manager');

        $response = $this->get('/api/reports/export?report_type=nonexistent', [
            'Authorization' => "Bearer $mgrToken",
        ]);

        $response->assertStatus(422);
    }
}
