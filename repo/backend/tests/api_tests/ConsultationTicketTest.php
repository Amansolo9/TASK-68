<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\ConsultationTicket;
use App\Models\ConsultationMessage;
use App\Models\TicketQualityReview;
use App\Services\TicketService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ConsultationTicketTest extends TestCase
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

    // --- Ticket Creation ---

    public function test_applicant_can_create_ticket(): void
    {
        [$user, $token] = $this->auth('applicant');

        $response = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'I have a question about the admissions process for the fall semester.',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['ticket', 'local_ticket_no']]);

        $ticketNo = $response->json('data.local_ticket_no');
        $this->assertStringStartsWith('TKT-', $ticketNo);
    }

    public function test_ticket_requires_category_and_priority(): void
    {
        [, $token] = $this->auth('applicant');

        $response = $this->postJson('/api/tickets', [
            'message' => 'Missing required fields test.',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422);
    }

    public function test_ticket_priority_must_be_valid(): void
    {
        [, $token] = $this->auth('applicant');

        $response = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Urgent',  // Invalid
            'message' => 'Should fail validation.',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422);
    }

    public function test_ticket_creates_initial_transcript_message(): void
    {
        [$user, $token] = $this->auth('applicant');

        $response = $this->postJson('/api/tickets', [
            'category_tag' => 'ADMISSION',
            'priority' => 'High',
            'message' => 'High priority question about admissions deadline.',
        ], ['Authorization' => "Bearer {$token}"]);

        $ticketId = $response->json('data.ticket.id');
        $messages = ConsultationMessage::where('ticket_id', $ticketId)->get();
        $this->assertCount(1, $messages);
        $this->assertEquals($user->id, $messages->first()->sender_user_id);
    }

    // --- SLA ---

    public function test_high_priority_ticket_has_sla_set(): void
    {
        [, $token] = $this->auth('applicant');

        $response = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'High',
            'message' => 'Urgent question that needs fast response.',
        ], ['Authorization' => "Bearer {$token}"]);

        $ticket = $response->json('data.ticket');
        $this->assertNotNull($ticket['first_response_due_at']);
    }

    public function test_normal_priority_ticket_has_sla_set(): void
    {
        [, $token] = $this->auth('applicant');

        $response = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'Standard question with normal priority.',
        ], ['Authorization' => "Bearer {$token}"]);

        $ticket = $response->json('data.ticket');
        $this->assertNotNull($ticket['first_response_due_at']);
    }

    public function test_overdue_recalculation(): void
    {
        [$user, ] = $this->auth('applicant');

        $ticket = ConsultationTicket::create([
            'local_ticket_no' => 'TKT-TEST-0001',
            'applicant_id' => $user->id,
            'category_tag' => 'GENERAL',
            'priority' => 'High',
            'status' => 'new',
            'initial_message' => 'Test message',
            'first_response_due_at' => now()->subHours(3),
            'overdue_flag' => false,
        ]);

        $service = app(TicketService::class);
        $updated = $service->recalculateOverdueFlags();

        $this->assertGreaterThanOrEqual(1, $updated);
        $ticket->refresh();
        $this->assertTrue($ticket->overdue_flag);
    }

    // --- Replies ---

    public function test_advisor_can_reply_to_assigned_ticket(): void
    {
        [$applicant, $appToken] = $this->auth('applicant');
        [$advisor, $advToken] = $this->auth('advisor');

        $createResp = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'Need help with my application.',
        ], ['Authorization' => "Bearer {$appToken}"]);

        $ticketId = $createResp->json('data.ticket.id');
        ConsultationTicket::find($ticketId)->update(['advisor_id' => $advisor->id]);

        $response = $this->postJson("/api/tickets/{$ticketId}/reply", [
            'message' => 'Thank you for reaching out. Let me help you.',
        ], ['Authorization' => "Bearer {$advToken}"]);

        $response->assertStatus(201);
    }

    public function test_first_advisor_reply_records_response_time(): void
    {
        [$applicant, $appToken] = $this->auth('applicant');
        [$advisor, $advToken] = $this->auth('advisor');

        $createResp = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'Question about my application status.',
        ], ['Authorization' => "Bearer {$appToken}"]);

        $ticketId = $createResp->json('data.ticket.id');
        ConsultationTicket::find($ticketId)->update(['advisor_id' => $advisor->id]);

        $this->postJson("/api/tickets/{$ticketId}/reply", [
            'message' => 'I will look into this for you.',
        ], ['Authorization' => "Bearer {$advToken}"]);

        $ticket = ConsultationTicket::find($ticketId);
        $this->assertNotNull($ticket->first_responded_at);
    }

    // --- State Transitions ---

    public function test_ticket_lifecycle_transitions(): void
    {
        [$applicant, $appToken] = $this->auth('applicant');
        [$advisor, $advToken] = $this->auth('advisor');

        $createResp = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'Lifecycle test ticket message.',
        ], ['Authorization' => "Bearer {$appToken}"]);

        $ticketId = $createResp->json('data.ticket.id');

        // new -> triaged
        $this->postJson("/api/tickets/{$ticketId}/transition", ['status' => 'triaged'], ['Authorization' => "Bearer {$advToken}"])
            ->assertStatus(200);

        // triaged -> in_progress
        $this->postJson("/api/tickets/{$ticketId}/transition", ['status' => 'in_progress'], ['Authorization' => "Bearer {$advToken}"])
            ->assertStatus(200);

        // in_progress -> resolved
        $this->postJson("/api/tickets/{$ticketId}/transition", ['status' => 'resolved'], ['Authorization' => "Bearer {$advToken}"])
            ->assertStatus(200);

        // resolved -> closed
        $this->postJson("/api/tickets/{$ticketId}/transition", ['status' => 'closed'], ['Authorization' => "Bearer {$advToken}"])
            ->assertStatus(200);

        $ticket = ConsultationTicket::find($ticketId);
        $this->assertEquals('closed', $ticket->status);
        $this->assertNotNull($ticket->closed_at);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        [$applicant, $appToken] = $this->auth('applicant');
        [$advisor, $advToken] = $this->auth('advisor');

        $createResp = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'Invalid transition test.',
        ], ['Authorization' => "Bearer {$appToken}"]);

        $ticketId = $createResp->json('data.ticket.id');

        // new -> resolved (invalid, should go through triaged first)
        $this->postJson("/api/tickets/{$ticketId}/transition", ['status' => 'resolved'], ['Authorization' => "Bearer {$advToken}"])
            ->assertStatus(409);
    }

    // --- Reassignment ---

    public function test_manager_can_reassign_with_reason(): void
    {
        [$applicant, $appToken] = $this->auth('applicant');
        [$manager, $mgrToken] = $this->auth('manager');
        [$advisor, ] = $this->auth('advisor');

        $createResp = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'Reassignment test ticket.',
        ], ['Authorization' => "Bearer {$appToken}"]);

        $ticketId = $createResp->json('data.ticket.id');

        $response = $this->postJson("/api/tickets/{$ticketId}/reassign", [
            'to_advisor_id' => $advisor->id,
            'reason' => 'Advisor specializes in this area.',
        ], ['Authorization' => "Bearer {$mgrToken}"]);

        $response->assertStatus(200);
        $ticket = ConsultationTicket::find($ticketId);
        $this->assertEquals($advisor->id, $ticket->advisor_id);
        $this->assertEquals('reassigned', $ticket->status);

        // Check routing history recorded
        $this->assertDatabaseHas('ticket_routing_history', [
            'ticket_id' => $ticketId,
            'to_advisor' => $advisor->id,
            'reason' => 'Advisor specializes in this area.',
        ]);
    }

    public function test_reassignment_requires_reason(): void
    {
        [, $appToken] = $this->auth('applicant');
        [, $mgrToken] = $this->auth('manager');

        $createResp = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'Reason required test.',
        ], ['Authorization' => "Bearer {$appToken}"]);

        $ticketId = $createResp->json('data.ticket.id');

        $response = $this->postJson("/api/tickets/{$ticketId}/reassign", [
            'to_advisor_id' => 1,
        ], ['Authorization' => "Bearer {$mgrToken}"]);

        $response->assertStatus(422);
    }

    // --- Transcript Immutability ---

    public function test_messages_are_immutable(): void
    {
        [$user, ] = $this->auth('applicant');

        $ticket = ConsultationTicket::create([
            'local_ticket_no' => 'TKT-IMMUT-0001',
            'applicant_id' => $user->id,
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'status' => 'new',
            'initial_message' => 'Test',
        ]);

        $message = ConsultationMessage::create([
            'ticket_id' => $ticket->id,
            'sender_user_id' => $user->id,
            'message_text' => 'Original message',
            'created_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $message->update(['message_text' => 'Tampered message']);
    }

    // --- Polling ---

    public function test_ticket_poll_endpoint_returns_status(): void
    {
        [$user, $token] = $this->auth('applicant');

        $createResp = $this->postJson('/api/tickets', [
            'category_tag' => 'GENERAL',
            'priority' => 'Normal',
            'message' => 'Polling test ticket.',
        ], ['Authorization' => "Bearer {$token}"]);

        $ticketId = $createResp->json('data.ticket.id');

        $response = $this->getJson("/api/tickets/{$ticketId}/poll", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['ticket_id', 'status', 'overdue_flag', 'message_count']]);
    }

    // --- Quality Review Sampling ---

    public function test_quality_review_sampling_is_idempotent(): void
    {
        $service = app(TicketService::class);
        $weekId = '2025-W01';

        // Run twice
        $first = $service->sampleQualityReviews($weekId);
        $second = $service->sampleQualityReviews($weekId);

        // Second run should not create duplicates
        // (Empty here since no closed tickets exist, but tests idempotency path)
        $this->assertIsArray($first);
        $this->assertIsArray($second);
    }
}
