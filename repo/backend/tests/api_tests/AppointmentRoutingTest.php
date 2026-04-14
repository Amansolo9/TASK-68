<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\AppointmentSlot;
use App\Models\Appointment;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AppointmentRoutingTest extends TestCase
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

    private function createSlot(int $capacity = 5, ?\DateTime $startAt = null): AppointmentSlot
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

    private function bookAppointment(User $user, string $token, AppointmentSlot $slot): int
    {
        $resp = $this->postJson('/api/appointments/book', [
            'slot_id' => $slot->id,
            'request_key' => Str::uuid()->toString(),
        ], ['Authorization' => "Bearer {$token}"]);
        return $resp->json('data.id');
    }

    // --- Applicant endpoint access ---

    public function test_applicant_can_access_my_appointments(): void
    {
        [$applicant, $token] = $this->auth('applicant');
        $slot = $this->createSlot();
        $this->bookAppointment($applicant, $token, $slot);

        $response = $this->getJson('/api/appointments/my', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    // --- Staff management endpoint ---

    public function test_advisor_can_access_staff_appointment_listing(): void
    {
        [$advisor, $token] = $this->auth('advisor');

        $response = $this->getJson('/api/appointments', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_manager_can_access_staff_appointment_listing(): void
    {
        [$manager, $token] = $this->auth('manager');

        $response = $this->getJson('/api/appointments', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_admin_can_access_staff_appointment_listing(): void
    {
        [$admin, $token] = $this->auth('admin');

        $response = $this->getJson('/api/appointments', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_applicant_cannot_access_staff_appointment_listing(): void
    {
        [$applicant, $token] = $this->auth('applicant');

        $response = $this->getJson('/api/appointments', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    // --- Manager override cancel through route middleware ---

    public function test_manager_override_cancel_succeeds_through_full_route(): void
    {
        [$applicant, $appToken] = $this->auth('applicant');
        $slot = $this->createSlot(5, now()->addHours(6)); // Within 12h — normal cancel blocked
        $appointmentId = $this->bookAppointment($applicant, $appToken, $slot);

        [$manager, $mgrToken] = $this->auth('manager');

        $response = $this->postJson("/api/appointments/{$appointmentId}/cancel", [
            'reason' => 'Manager override: applicant request',
            'override' => true,
        ], ['Authorization' => "Bearer {$mgrToken}"]);

        $response->assertStatus(200)
            ->assertJsonPath('data.state', 'cancelled');
    }

    public function test_manager_override_reschedule_succeeds_through_full_route(): void
    {
        [$applicant, $appToken] = $this->auth('applicant');
        $slot1 = $this->createSlot(5, now()->addHours(12)); // Within 24h — normal reschedule blocked
        $slot2 = $this->createSlot(5, now()->addDays(5));
        $appointmentId = $this->bookAppointment($applicant, $appToken, $slot1);

        [$manager, $mgrToken] = $this->auth('manager');

        $response = $this->postJson("/api/appointments/{$appointmentId}/reschedule", [
            'new_slot_id' => $slot2->id,
            'request_key' => Str::uuid()->toString(),
            'reason' => 'Manager override: rescheduling on behalf of applicant',
        ], ['Authorization' => "Bearer {$mgrToken}"]);

        $response->assertStatus(200);
    }

    public function test_applicant_cross_user_cancel_returns_403(): void
    {
        [$applicant1, $token1] = $this->auth('applicant');
        [$applicant2, $token2] = $this->auth('applicant');
        $slot = $this->createSlot(5, now()->addDays(3));
        $appointmentId = $this->bookAppointment($applicant1, $token1, $slot);

        // Applicant 2 tries to cancel Applicant 1's appointment
        $response = $this->postJson("/api/appointments/{$appointmentId}/cancel", [
            'reason' => 'Trying to cancel someone else appointment',
        ], ['Authorization' => "Bearer {$token2}"]);

        $response->assertStatus(403);
    }

    public function test_unauthorized_role_cancel_returns_403(): void
    {
        [$applicant, $appToken] = $this->auth('applicant');
        $slot = $this->createSlot(5, now()->addDays(3));
        $appointmentId = $this->bookAppointment($applicant, $appToken, $slot);

        [$steward, $sToken] = $this->auth('steward');

        // Steward has neither appointments.book nor appointments.manage
        $response = $this->postJson("/api/appointments/{$appointmentId}/cancel", [
            'reason' => 'Unauthorized attempt',
        ], ['Authorization' => "Bearer {$sToken}"]);

        $response->assertStatus(403);
    }

    // --- My endpoint strictly scoped to own appointments ---

    public function test_my_appointments_returns_only_own_appointments(): void
    {
        [$applicant1, $token1] = $this->auth('applicant');
        [$applicant2, $token2] = $this->auth('applicant');

        $slot = $this->createSlot(10);
        $this->bookAppointment($applicant1, $token1, $slot);
        $this->bookAppointment($applicant2, $token2, $slot);

        $response = $this->getJson('/api/appointments/my', [
            'Authorization' => "Bearer {$token1}",
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        foreach ($data as $apt) {
            $this->assertEquals($applicant1->id, $apt['applicant_id']);
        }
    }

    // --- Staff filter by state ---

    public function test_staff_appointment_listing_filters_by_state(): void
    {
        [$applicant, $appToken] = $this->auth('applicant');
        $slot = $this->createSlot(10);
        $this->bookAppointment($applicant, $appToken, $slot);

        [$manager, $mgrToken] = $this->auth('manager');

        $response = $this->getJson('/api/appointments?state=booked', [
            'Authorization' => "Bearer {$mgrToken}",
        ]);

        $response->assertStatus(200);
        foreach ($response->json('data') as $apt) {
            $this->assertEquals('booked', $apt['state']);
        }
    }
}
