<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AppointmentService;
use App\Services\LockService;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AppointmentServiceTest extends TestCase
{
    private AppointmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AppointmentService::class);
    }

    private function makeUser(): User
    {
        $u = User::create(['username' => 'u_' . uniqid(), 'password_hash' => Hash::make('p'), 'full_name' => 'U', 'status' => 'active']);
        UserRoleScope::create(['user_id' => $u->id, 'role' => 'applicant', 'is_active' => true]);
        return $u;
    }

    private function makeSlot(int $cap = 5, int $minutesAhead = 4320): AppointmentSlot
    {
        return AppointmentSlot::create([
            'slot_type' => 'IN_PERSON',
            'start_at' => now()->addMinutes($minutesAhead),
            'end_at' => now()->addMinutes($minutesAhead + 60),
            'capacity' => $cap, 'available_qty' => $cap, 'status' => 'open',
        ]);
    }

    // --- Booking ---

    public function test_booking_decrements_capacity(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(3);

        $this->service->book($user->id, $slot->id, Str::uuid(), 'standard');
        $slot->refresh();
        $this->assertEquals(2, $slot->available_qty);
    }

    public function test_booking_full_slot_fails(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(1);

        $this->service->book($user->id, $slot->id, Str::uuid(), 'standard');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No available capacity');
        $this->service->book($user->id, $slot->id, Str::uuid(), 'standard');
    }

    public function test_slot_status_becomes_full_at_zero_capacity(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(1);

        $this->service->book($user->id, $slot->id, Str::uuid(), 'standard');
        $slot->refresh();
        $this->assertEquals('full', $slot->status);
        $this->assertEquals(0, $slot->available_qty);
    }

    public function test_idempotent_booking_returns_same_id(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(5);
        $key = Str::uuid()->toString();

        $a1 = $this->service->book($user->id, $slot->id, $key, 'standard');
        $a2 = $this->service->book($user->id, $slot->id, $key, 'standard');

        $this->assertEquals($a1->id, $a2->id);
        $slot->refresh();
        $this->assertEquals(4, $slot->available_qty); // Only decremented once
    }

    public function test_expired_request_key_rejected(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(5);
        $key = Str::uuid()->toString();

        // Create an appointment with an expired request key
        Appointment::create([
            'applicant_id' => $user->id, 'slot_id' => $slot->id,
            'state' => 'booked', 'request_key' => $key,
            'request_key_expires_at' => now()->subMinutes(1), 'booked_at' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expired');
        $this->service->book($user->id, $slot->id, $key, 'standard');
    }

    // --- Cancel ---

    public function test_cancel_restores_slot_capacity(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(3);
        $apt = $this->service->book($user->id, $slot->id, Str::uuid(), 'standard');

        $this->service->cancel($apt, 'Changed mind', $user->id);
        $slot->refresh();
        $this->assertEquals(3, $slot->available_qty);
    }

    public function test_cancel_too_late_fails(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(5, 300); // 5 hours ahead — inside 12h window
        $apt = $this->service->book($user->id, $slot->id, Str::uuid(), 'standard');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('12 hours');
        $this->service->cancel($apt, 'Too late', $user->id);
    }

    public function test_cancel_with_override_bypasses_window(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(5, 300); // 5 hours ahead
        $apt = $this->service->book($user->id, $slot->id, Str::uuid(), 'standard');

        $result = $this->service->cancel($apt, 'Admin override', $user->id, null, true);
        $this->assertEquals('cancelled', $result->state);
    }

    // --- Reschedule ---

    public function test_reschedule_moves_capacity_between_slots(): void
    {
        $user = $this->makeUser();
        $slot1 = $this->makeSlot(3, 4320);
        $slot2 = $this->makeSlot(3, 8640);
        $apt = $this->service->book($user->id, $slot1->id, Str::uuid(), 'standard');

        $this->service->reschedule($apt, $slot2->id, Str::uuid(), 'Schedule conflict', $user->id);

        $slot1->refresh();
        $slot2->refresh();
        $this->assertEquals(3, $slot1->available_qty); // Restored
        $this->assertEquals(2, $slot2->available_qty); // Consumed
    }

    public function test_reschedule_within_24h_fails(): void
    {
        $user = $this->makeUser();
        $slot1 = $this->makeSlot(5, 720); // 12 hours ahead
        $slot2 = $this->makeSlot(5, 8640);
        $apt = $this->service->book($user->id, $slot1->id, Str::uuid(), 'standard');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('24 hours');
        $this->service->reschedule($apt, $slot2->id, Str::uuid(), 'Too late', $user->id);
    }

    // --- No-show ---

    public function test_no_show_does_not_restore_capacity(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(5, -15); // 15 min in the past
        $apt = Appointment::create([
            'applicant_id' => $user->id, 'slot_id' => $slot->id,
            'state' => 'booked', 'request_key' => Str::uuid(),
            'request_key_expires_at' => now()->addMinutes(10), 'booked_at' => now()->subHour(),
        ]);
        $slot->decrement('available_qty');

        $result = $this->service->markNoShow($apt, $user->id);
        $this->assertEquals('no_show', $result->state);
        $this->assertNotNull($result->no_show_marked_at);

        $slot->refresh();
        $this->assertEquals(4, $slot->available_qty); // NOT restored
    }

    public function test_no_show_before_threshold_fails(): void
    {
        $user = $this->makeUser();
        $slot = $this->makeSlot(5, 5); // 5 min in future — before 10min threshold
        $apt = Appointment::create([
            'applicant_id' => $user->id, 'slot_id' => $slot->id,
            'state' => 'booked', 'request_key' => Str::uuid(),
            'request_key_expires_at' => now()->addMinutes(10), 'booked_at' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('10 minutes');
        $this->service->markNoShow($apt, $user->id);
    }

    // --- Lock service ---

    public function test_lock_acquire_and_release(): void
    {
        $lock = new LockService();
        $this->assertTrue($lock->acquire('test-lock-key'));
        $this->assertTrue($lock->release('test-lock-key'));
    }

    public function test_double_acquire_same_key_fails(): void
    {
        $lock1 = new LockService();
        $lock2 = new LockService();

        $this->assertTrue($lock1->acquire('exclusive'));
        $this->assertFalse($lock2->acquire('exclusive'));

        $lock1->release('exclusive');
        $this->assertTrue($lock2->acquire('exclusive'));
    }

    // --- Expire pending holds ---

    public function test_expire_pending_holds_cleans_up(): void
    {
        $count = $this->service->expirePendingHolds();
        $this->assertIsInt($count);
    }
}
