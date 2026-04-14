<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    /**
     * Applicant can view own appointment; staff with appointments.manage can view any.
     */
    public function view(User $user, Appointment $appointment): bool
    {
        if ($user->hasPermission('appointments.manage')) {
            return true;
        }
        return $appointment->applicant_id === $user->id;
    }

    /**
     * Reschedule: applicant owns it, OR advisor assigned to the slot, OR manager/admin override.
     */
    public function reschedule(User $user, Appointment $appointment): bool
    {
        if ($user->hasAnyRole(['manager', 'admin'])) {
            return true;
        }
        if ($user->hasRole('advisor') && $user->hasPermission('appointments.manage')) {
            return $this->isAdvisorAssignedToSlot($user, $appointment);
        }
        return $appointment->applicant_id === $user->id;
    }

    /**
     * Cancel: applicant owns it, OR advisor assigned to the slot, OR manager/admin override.
     */
    public function cancel(User $user, Appointment $appointment): bool
    {
        if ($user->hasAnyRole(['manager', 'admin'])) {
            return true;
        }
        if ($user->hasRole('advisor') && $user->hasPermission('appointments.manage')) {
            return $this->isAdvisorAssignedToSlot($user, $appointment);
        }
        return $appointment->applicant_id === $user->id;
    }

    /**
     * Check if advisor is assigned to the appointment's slot (by advisor_id or department scope).
     */
    private function isAdvisorAssignedToSlot(User $user, Appointment $appointment): bool
    {
        $slot = $appointment->slot;
        if (!$slot) {
            return false;
        }
        // Direct assignment to slot
        if ($slot->advisor_id === $user->id) {
            return true;
        }
        // Department scope match
        if ($slot->department_id && $user->hasDepartmentScope($slot->department_id)) {
            return true;
        }
        return false;
    }

    /**
     * Only staff with appointments.manage can mark no-show.
     */
    public function markNoShow(User $user, Appointment $appointment): bool
    {
        return $user->hasPermission('appointments.manage');
    }

    /**
     * Only staff with appointments.manage can mark complete.
     */
    public function complete(User $user, Appointment $appointment): bool
    {
        return $user->hasPermission('appointments.manage');
    }
}
