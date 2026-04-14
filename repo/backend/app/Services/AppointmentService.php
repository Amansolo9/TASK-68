<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentStateHistory;
use App\Models\SlotReservation;
use Illuminate\Support\Facades\DB;

class AppointmentService
{
    private const REQUEST_KEY_TTL_MINUTES = 10;

    public function __construct(
        private LockService $lockService,
        private AuditService $auditService,
    ) {}

    /**
     * Book an appointment with idempotency via request_key.
     */
    public function book(
        int $applicantId,
        int $slotId,
        string $requestKey,
        string $bookingType = 'standard',
        ?string $ipAddress = null
    ): Appointment {
        // Idempotency check: if request_key already used, return original
        $existing = Appointment::where('request_key', $requestKey)
            ->where('applicant_id', $applicantId)
            ->where('request_key_expires_at', '>', now())
            ->first();

        if ($existing) {
            return $existing;
        }

        // Check for expired request key (same key, expired)
        $expired = Appointment::where('request_key', $requestKey)
            ->where('request_key_expires_at', '<=', now())
            ->exists();

        if ($expired) {
            throw new \InvalidArgumentException('Request key has expired. Please generate a new one.');
        }

        $lockKey = "slot_booking:{$slotId}";

        return $this->lockService->withLock($lockKey, function () use ($applicantId, $slotId, $requestKey, $bookingType, $ipAddress) {
            return DB::transaction(function () use ($applicantId, $slotId, $requestKey, $bookingType, $ipAddress) {
                $slot = AppointmentSlot::lockForUpdate()->findOrFail($slotId);

                if (!$slot->hasAvailableCapacity()) {
                    throw new \RuntimeException('No available capacity for this slot.');
                }

                // Pre-deduct capacity
                $slot->decrement('available_qty');
                if ($slot->available_qty <= 0) {
                    $slot->update(['status' => 'full']);
                }

                // Create reservation
                $reservation = SlotReservation::create([
                    'slot_id' => $slotId,
                    'correlation_key' => $requestKey,
                    'reserved_qty' => 1,
                    'expires_at' => now()->addMinutes(self::REQUEST_KEY_TTL_MINUTES),
                    'status' => 'confirmed',
                ]);

                // Create appointment
                $appointment = Appointment::create([
                    'applicant_id' => $applicantId,
                    'slot_id' => $slotId,
                    'booking_type' => $bookingType,
                    'state' => 'booked',
                    'request_key' => $requestKey,
                    'request_key_expires_at' => now()->addMinutes(self::REQUEST_KEY_TTL_MINUTES),
                    'booked_at' => now(),
                ]);

                $reservation->update(['appointment_id' => $appointment->id]);

                $this->recordTransition($appointment, null, 'booked', $applicantId, $ipAddress);

                $this->auditService->log(
                    'appointment', (string) $appointment->id, 'appointment_booked',
                    $applicantId, null, $ipAddress,
                    null, $this->auditService->computeEntityHash($appointment->toArray()),
                    ['slot_id' => $slotId, 'request_key' => $requestKey]
                );

                return $appointment;
            });
        });
    }

    /**
     * Reschedule: only if now <= start_at - 24h.
     */
    public function reschedule(
        Appointment $appointment,
        int $newSlotId,
        string $requestKey,
        string $reason,
        int $actorId,
        ?string $ipAddress = null,
        bool $isStaffAction = false
    ): Appointment {
        // Idempotency
        if ($appointment->request_key === $requestKey && $appointment->state === 'rescheduled') {
            return $appointment;
        }

        $slot = $appointment->slot;
        $hoursUntilStart = now()->diffInHours($slot->start_at, false);

        if (!$isStaffAction && $hoursUntilStart < 24) {
            throw new \InvalidArgumentException('Reschedule is only allowed up to 24 hours before slot start.');
        }

        if (!$appointment->canTransitionTo('rescheduled')) {
            throw new \InvalidArgumentException("Cannot reschedule from state '{$appointment->state}'.");
        }

        $lockKey = "slot_booking:{$newSlotId}";

        return $this->lockService->withLock($lockKey, function () use ($appointment, $newSlotId, $requestKey, $reason, $actorId, $ipAddress) {
            return DB::transaction(function () use ($appointment, $newSlotId, $requestKey, $reason, $actorId, $ipAddress) {
                // Release old slot
                $oldSlot = $appointment->slot;
                $oldSlot->increment('available_qty');
                if ($oldSlot->status === 'full') {
                    $oldSlot->update(['status' => 'open']);
                }

                // Reserve new slot
                $newSlot = AppointmentSlot::lockForUpdate()->findOrFail($newSlotId);
                if (!$newSlot->hasAvailableCapacity()) {
                    throw new \RuntimeException('No available capacity for the new slot.');
                }
                $newSlot->decrement('available_qty');
                if ($newSlot->available_qty <= 0) {
                    $newSlot->update(['status' => 'full']);
                }

                $appointment->update([
                    'slot_id' => $newSlotId,
                    'state' => 'rescheduled',
                    'reschedule_reason' => $reason,
                    'request_key' => $requestKey,
                    'request_key_expires_at' => now()->addMinutes(self::REQUEST_KEY_TTL_MINUTES),
                ]);

                $this->recordTransition($appointment, 'booked', 'rescheduled', $actorId, $ipAddress, $reason);

                // Transition back to booked
                $appointment->update(['state' => 'booked', 'booked_at' => now()]);
                $this->recordTransition($appointment, 'rescheduled', 'booked', $actorId, $ipAddress, 'Rescheduled to new slot');

                $this->auditService->log(
                    'appointment', (string) $appointment->id, 'appointment_rescheduled',
                    $actorId, null, $ipAddress, null, null,
                    ['new_slot_id' => $newSlotId, 'reason' => $reason]
                );

                return $appointment->fresh();
            });
        });
    }

    /**
     * Cancel: only if now <= start_at - 12h.
     */
    public function cancel(
        Appointment $appointment,
        string $reason,
        int $actorId,
        ?string $ipAddress = null,
        bool $isOverride = false
    ): Appointment {
        $slot = $appointment->slot;
        $hoursUntilStart = now()->diffInHours($slot->start_at, false);

        if (!$isOverride && $hoursUntilStart < 12) {
            throw new \InvalidArgumentException('Cancellation is only allowed up to 12 hours before slot start.');
        }

        if (!$appointment->canTransitionTo('cancelled')) {
            throw new \InvalidArgumentException("Cannot cancel from state '{$appointment->state}'.");
        }

        return DB::transaction(function () use ($appointment, $reason, $actorId, $ipAddress, $isOverride) {
            $fromState = $appointment->state;

            // Restore slot capacity
            $slot = $appointment->slot;
            $slot->increment('available_qty');
            if ($slot->status === 'full') {
                $slot->update(['status' => 'open']);
            }

            $appointment->update([
                'state' => 'cancelled',
                'cancellation_reason' => $reason,
                'override_reason' => $isOverride ? $reason : null,
            ]);

            $this->recordTransition($appointment, $fromState, 'cancelled', $actorId, $ipAddress, $reason);

            $this->auditService->log(
                'appointment', (string) $appointment->id, 'appointment_cancelled',
                $actorId, null, $ipAddress, null, null,
                ['reason' => $reason, 'override' => $isOverride]
            );

            return $appointment->fresh();
        });
    }

    /**
     * Mark no-show: if not checked in within 10 min after start.
     * Slot is consumed (no inventory restore).
     */
    public function markNoShow(Appointment $appointment, int $actorId, ?string $ipAddress = null): Appointment
    {
        $slot = $appointment->slot;
        $threshold = $slot->start_at->copy()->addMinutes(10);

        if (now()->lessThan($threshold)) {
            throw new \InvalidArgumentException('No-show can only be marked after 10 minutes past slot start time.');
        }

        if (!$appointment->canTransitionTo('no_show')) {
            throw new \InvalidArgumentException("Cannot mark no-show from state '{$appointment->state}'.");
        }

        $fromState = $appointment->state;
        $appointment->update([
            'state' => 'no_show',
            'no_show_marked_at' => now(),
        ]);

        // Slot remains consumed — no inventory restore
        $this->recordTransition($appointment, $fromState, 'no_show', $actorId, $ipAddress, 'Attendee did not arrive');

        $this->auditService->log(
            'appointment', (string) $appointment->id, 'appointment_no_show',
            $actorId, null, $ipAddress
        );

        return $appointment->fresh();
    }

    /**
     * Mark appointment as completed.
     */
    public function complete(Appointment $appointment, int $actorId, ?string $ipAddress = null): Appointment
    {
        if (!$appointment->canTransitionTo('completed')) {
            throw new \InvalidArgumentException("Cannot complete from state '{$appointment->state}'.");
        }

        $fromState = $appointment->state;
        $appointment->update(['state' => 'completed']);
        $this->recordTransition($appointment, $fromState, 'completed', $actorId, $ipAddress);

        return $appointment->fresh();
    }

    /**
     * Expire pending holds that have passed their expiry.
     */
    public function expirePendingHolds(): int
    {
        $count = 0;

        $expired = SlotReservation::where('status', 'held')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $reservation) {
            DB::transaction(function () use ($reservation) {
                $reservation->update(['status' => 'expired']);
                $slot = $reservation->slot;
                $slot->increment('available_qty');
                if ($slot->status === 'full') {
                    $slot->update(['status' => 'open']);
                }
            });
            $count++;
        }

        // Expire pending appointments
        Appointment::where('state', 'pending')
            ->where('request_key_expires_at', '<', now())
            ->each(function ($apt) {
                $apt->update(['state' => 'expired']);
            });

        return $count;
    }

    private function recordTransition(Appointment $appointment, ?string $fromState, string $toState, int $actorId, ?string $ipAddress = null, ?string $reason = null): void
    {
        AppointmentStateHistory::create([
            'appointment_id' => $appointment->id,
            'from_state' => $fromState,
            'to_state' => $toState,
            'actor_user_id' => $actorId,
            'ip_address' => $ipAddress,
            'reason' => $reason,
            'transitioned_at' => now(),
        ]);
    }
}
