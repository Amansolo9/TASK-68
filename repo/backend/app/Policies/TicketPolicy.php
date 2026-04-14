<?php

namespace App\Policies;

use App\Models\ConsultationTicket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Can user view this ticket?
     * - Applicant: own tickets only
     * - Advisor: assigned to them OR within their department scope
     * - Manager/Admin: within department scope (null scope = global)
     */
    public function view(User $user, ConsultationTicket $ticket): bool
    {
        return $this->hasTicketAccess($user, $ticket);
    }

    /**
     * Can user reply to this ticket?
     */
    public function reply(User $user, ConsultationTicket $ticket): bool
    {
        if ($user->hasRole('applicant')) {
            return $ticket->applicant_id === $user->id;
        }
        return $this->hasStaffAccess($user, $ticket);
    }

    /**
     * Can user transition this ticket's status?
     */
    public function transition(User $user, ConsultationTicket $ticket): bool
    {
        return $this->hasStaffAccess($user, $ticket);
    }

    /**
     * Can user reassign this ticket? Requires tickets.reassign permission + department scope.
     */
    public function reassign(User $user, ConsultationTicket $ticket): bool
    {
        if (!$user->hasPermission('tickets.reassign')) {
            return false;
        }
        // Manager/admin must have department scope for the ticket's department
        if ($ticket->department_id) {
            return $user->hasDepartmentScope($ticket->department_id);
        }
        return true; // No department set — global scope managers can reassign
    }

    /**
     * Can user poll this ticket?
     */
    public function poll(User $user, ConsultationTicket $ticket): bool
    {
        return $this->hasTicketAccess($user, $ticket);
    }

    // --- helpers ---

    private function hasTicketAccess(User $user, ConsultationTicket $ticket): bool
    {
        if ($user->hasRole('applicant')) {
            return $ticket->applicant_id === $user->id;
        }
        return $this->hasStaffAccess($user, $ticket);
    }

    private function hasStaffAccess(User $user, ConsultationTicket $ticket): bool
    {
        // Admin has global access
        if ($user->hasRole('admin')) {
            return true;
        }

        // Manager: department scope check
        if ($user->hasRole('manager')) {
            if ($ticket->department_id) {
                return $user->hasDepartmentScope($ticket->department_id);
            }
            return true;
        }

        // Advisor: must be assigned, or share department scope, or have global scope
        if ($user->hasRole('advisor')) {
            if ($ticket->advisor_id === $user->id) {
                return true;
            }
            if ($ticket->department_id) {
                return $user->hasDepartmentScope($ticket->department_id);
            }
            // Ticket has no department — allow if advisor has global scope (null dept_scope)
            return $user->activeRoleScopes()->whereNull('department_scope')->exists();
        }

        return false;
    }
}
