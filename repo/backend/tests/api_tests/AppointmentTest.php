<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\AppointmentSlot;
use App\Models\Appointment;
use App\Services\AppointmentService;
use App\Services\LockService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AppointmentTest extends TestCase
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

    private function createSlot(int $capacity = 1, ?\DateTime $startAt = null): AppointmentSlot
    {
        $start = $startAt ?? now()->addDays(3);
        return AppointmentSlot::create([
            'slot_type' => 'IN_PERSON',
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'capacity' => $capacity,
            'available_qty' => $capacity,
            'status' => 'open',
        ]);
    }

    // --- Booking ---

    public function test_applicant_can_book_appointment(): void
    {
        [$user, $token] = $this->auth('applicant');
        $slot = $this->createSlot(5);
        $requestKey = Str::uuid()->toString();

        $response = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
            'request_key' => $requestKey,
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201)
            ->assertJsonPath('data.state', 'booked');

        $slot->refresh();
        $this->assertEquals(4, $slot->available_qty);
    }

    public function test_idempotent_booking_returns_same_result(): void
    {
        [$user, $token] = $this->auth('applicant');
        $slot = $this->createSlot(5);
        $requestKey = Str::uuid()->toString();

        // First booking
        $first = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
            'request_key' => $requestKey,
        ], ['Authorization' => "Bearer {$token}"]);

        // Second booking with same request key
        $second = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
            'request_key' => $requestKey,
        ], ['Authorization' => "Bearer {$token}"]);

        $second->assertStatus(201);
        $this->assertEquals($first->json('data.id'), $second->json('data.id'));

        // Capacity should only be deducted once
        $slot->refresh();
        $this->assertEquals(4, $slot->available_qty);
    }

    public function test_booking_fails_when_no_capacity(): void
    {
        [$user, $token] = $this->auth('applicant');
        $slot = $this->createSlot(1);

        // Book the only slot
        $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
            'request_key' => Str::uuid()->toString(),
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

        // Try to book again with different key
        $response = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
            'request_key' => Str::uuid()->toString(),
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409);
    }

    public function test_booking_requires_request_key(): void
    {
        [, $token] = $this->auth('applicant');
        $slot = $this->createSlot();

        $response = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422);
    }

    // --- Reschedule ---

    public function test_reschedule_within_24h_window(): void
    {
        [$user, $token] = $this->auth('applicant');
        $slot1 = $this->createSlot(5, now()->addDays(3));
        $slot2 = $this->createSlot(5, now()->addDays(5));

        // Book original
        $bookResp = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot1->id,
            'request_key' => Str::uuid()->toString(),
        ], ['Authorization' => "Bearer {$token}"]);

        $appointmentId = $bookResp->json('data.id');

        // Reschedule
        $response = $this->postJson("/api/appointments/{$appointmentId}/reschedule", [
            'new_slot_id' => $slot2->id,
            'request_key' => Str::uuid()->toString(),
            'reason' => 'Schedule conflict',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);

        // Old slot should be restored
        $slot1->refresh();
        $this->assertEquals(5, $slot1->available_qty);

        // New slot should be deducted
        $slot2->refresh();
        $this->assertEquals(4, $slot2->available_qty);
    }

    public function test_reschedule_too_close_to_start_fails(): void
    {
        [$user, $token] = $this->auth('applicant');
        $slot1 = $this->createSlot(5, now()->addHours(12));
        $slot2 = $this->createSlot(5, now()->addDays(5));

        $bookResp = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot1->id,
            'request_key' => Str::uuid()->toString(),
        ], ['Authorization' => "Bearer {$token}"]);

        $appointmentId = $bookResp->json('data.id');

        $response = $this->postJson("/api/appointments/{$appointmentId}/reschedule", [
            'new_slot_id' => $slot2->id,
            'request_key' => Str::uuid()->toString(),
            'reason' => 'Too late',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_RESCHEDULE_WINDOW_EXCEEDED');
    }

    // --- Cancel ---

    public function test_cancel_within_12h_window(): void
    {
        [$user, $token] = $this->auth('applicant');
        $slot = $this->createSlot(5, now()->addDays(2));

        $bookResp = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
            'request_key' => Str::uuid()->toString(),
        ], ['Authorization' => "Bearer {$token}"]);

        $appointmentId = $bookResp->json('data.id');

        $response = $this->postJson("/api/appointments/{$appointmentId}/cancel", [
            'reason' => 'No longer needed',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)
            ->assertJsonPath('data.state', 'cancelled');

        // Slot capacity restored
        $slot->refresh();
        $this->assertEquals(5, $slot->available_qty);
    }

    public function test_cancel_too_close_to_start_fails(): void
    {
        [$user, $token] = $this->auth('applicant');
        $slot = $this->createSlot(5, now()->addHours(6));

        $bookResp = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
            'request_key' => Str::uuid()->toString(),
        ], ['Authorization' => "Bearer {$token}"]);

        $appointmentId = $bookResp->json('data.id');

        $response = $this->postJson("/api/appointments/{$appointmentId}/cancel", [
            'reason' => 'Too late',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409);
    }

    // --- No-Show ---

    public function test_no_show_does_not_restore_capacity(): void
    {
        [$user, ] = $this->auth('applicant');
        [$advisor, $advToken] = $this->auth('advisor');

        $slot = $this->createSlot(5, now()->subMinutes(15));
        $slot->update(['status' => 'open']); // Force it open for test

        $appointment = Appointment::create([
            'applicant_id' => $user->id,
            'slot_id' => $slot->id,
            'state' => 'booked',
            'request_key' => Str::uuid()->toString(),
            'request_key_expires_at' => now()->addMinutes(10),
            'booked_at' => now()->subHour(),
        ]);

        $service = app(AppointmentService::class);
        $result = $service->markNoShow($appointment, $advisor->id);

        $this->assertEquals('no_show', $result->state);
        $this->assertNotNull($result->no_show_marked_at);

        // Capacity should NOT be restored
        $slot->refresh();
        $this->assertEquals(5, $slot->available_qty);
    }

    // --- Lock Service ---

    public function test_lock_acquire_and_release(): void
    {
        $lock = app(LockService::class);

        $this->assertTrue($lock->acquire('test-key'));
        $this->assertTrue($lock->release('test-key'));
    }

    public function test_lock_prevents_double_acquire(): void
    {
        $lock1 = new LockService();
        $lock2 = new LockService();

        $this->assertTrue($lock1->acquire('exclusive-key'));
        $this->assertFalse($lock2->acquire('exclusive-key'));

        $lock1->release('exclusive-key');
        $this->assertTrue($lock2->acquire('exclusive-key'));
    }

    // --- State History ---

    public function test_booking_creates_state_history(): void
    {
        [$user, $token] = $this->auth('applicant');
        $slot = $this->createSlot(5);

        $bookResp = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
            'request_key' => Str::uuid()->toString(),
        ], ['Authorization' => "Bearer {$token}"]);

        $appointmentId = $bookResp->json('data.id');

        $this->assertDatabaseHas('appointment_state_history', [
            'appointment_id' => $appointmentId,
            'to_state' => 'booked',
        ]);
    }
}
