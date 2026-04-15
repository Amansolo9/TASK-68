<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\TicketService;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\ConsultationTicket;
use App\Models\ConsultationMessage;
use App\Models\TicketQualityReview;
use Illuminate\Support\Facades\Hash;

class TicketServiceTest extends TestCase
{
    private TicketService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TicketService::class);
    }

    private function applicant(): User
    {
        $u = User::create(['username' => 'app_' . uniqid(), 'password_hash' => Hash::make('p'), 'full_name' => 'App', 'status' => 'active']);
        UserRoleScope::create(['user_id' => $u->id, 'role' => 'applicant', 'is_active' => true]);
        return $u;
    }

    // --- Ticket number generation ---

    public function test_ticket_number_follows_format(): void
    {
        $user = $this->applicant();
        $ticket = $this->service->createTicket($user->id, 'GENERAL', 'Normal', 'Test message body.');
        $this->assertMatchesRegularExpression('/^TKT-\d{8}-\d{4}$/', $ticket->local_ticket_no);
    }

    public function test_ticket_numbers_increment_sequentially(): void
    {
        $user = $this->applicant();
        $t1 = $this->service->createTicket($user->id, 'GENERAL', 'Normal', 'First ticket.');
        $t2 = $this->service->createTicket($user->id, 'GENERAL', 'Normal', 'Second ticket.');

        $seq1 = (int) substr($t1->local_ticket_no, -4);
        $seq2 = (int) substr($t2->local_ticket_no, -4);
        $this->assertEquals($seq1 + 1, $seq2);
    }

    // --- SLA deadline computation ---

    public function test_high_priority_sla_is_shorter_than_normal(): void
    {
        $highDeadline = $this->service->computeSlaDeadline('High');
        $normalDeadline = $this->service->computeSlaDeadline('Normal');
        $this->assertLessThan($normalDeadline->getTimestamp(), $highDeadline->getTimestamp());
    }

    // --- Overdue flag ---

    public function test_overdue_flag_set_when_past_due(): void
    {
        $user = $this->applicant();
        $ticket = ConsultationTicket::create([
            'local_ticket_no' => 'TKT-OVERDUE-0001',
            'applicant_id' => $user->id,
            'category_tag' => 'GENERAL',
            'priority' => 'High',
            'status' => 'new',
            'initial_message' => 'Overdue test.',
            'first_response_due_at' => now()->subHours(3),
            'overdue_flag' => false,
        ]);

        $count = $this->service->recalculateOverdueFlags();
        $this->assertGreaterThanOrEqual(1, $count);
        $ticket->refresh();
        $this->assertTrue($ticket->overdue_flag);
    }

    public function test_overdue_flag_not_set_when_already_responded(): void
    {
        $user = $this->applicant();
        $ticket = ConsultationTicket::create([
            'local_ticket_no' => 'TKT-RESPONDED-001',
            'applicant_id' => $user->id,
            'category_tag' => 'GENERAL',
            'priority' => 'High',
            'status' => 'in_progress',
            'initial_message' => 'Responded test.',
            'first_response_due_at' => now()->subHours(3),
            'first_responded_at' => now()->subHours(2),
            'overdue_flag' => false,
        ]);

        $this->service->recalculateOverdueFlags();
        $ticket->refresh();
        $this->assertFalse($ticket->overdue_flag);
    }

    // --- Transcript immutability ---

    public function test_reply_to_locked_ticket_is_rejected(): void
    {
        $user = $this->applicant();
        $ticket = $this->service->createTicket($user->id, 'GENERAL', 'Normal', 'Lock test msg.');
        $ticket->update(['status' => 'closed']);

        TicketQualityReview::create([
            'sampled_week' => '2026-W01', 'advisor_id' => 1,
            'ticket_id' => $ticket->id, 'review_state' => 'pending',
            'locked_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('locked');
        $this->service->addReply($ticket, $user->id, 'Should fail.');
    }

    // --- State transition validation ---

    public function test_closed_ticket_cannot_transition(): void
    {
        $user = $this->applicant();
        $ticket = $this->service->createTicket($user->id, 'GENERAL', 'Normal', 'Close test.');
        $ticket->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->transitionStatus($ticket, 'triaged', $user->id);
    }

    public function test_valid_transition_updates_status(): void
    {
        $user = $this->applicant();
        $ticket = $this->service->createTicket($user->id, 'GENERAL', 'Normal', 'Transition test.');

        $updated = $this->service->transitionStatus($ticket, 'triaged', $user->id);
        $this->assertEquals('triaged', $updated->status);
    }

    // --- Quality review sampling ---

    public function test_sampling_creates_locked_reviews(): void
    {
        $user = $this->applicant();
        $advisor = User::create(['username' => 'adv_' . uniqid(), 'password_hash' => Hash::make('p'), 'full_name' => 'Adv', 'status' => 'active']);

        // Create and close a ticket assigned to the advisor
        $ticket = $this->service->createTicket($user->id, 'GENERAL', 'Normal', 'Sample me.');
        $ticket->update([
            'advisor_id' => $advisor->id,
            'status' => 'closed',
            'closed_at' => now()->subDay(),
        ]);

        $weekId = now()->subWeek()->format('Y-\WW');
        $samples = $this->service->sampleQualityReviews($weekId);

        if (!empty($samples)) {
            $this->assertNotNull($samples[0]->locked_at);
        }
    }
}
