<?php

namespace App\Services;

use App\Models\ConsultationTicket;
use App\Models\ConsultationMessage;
use App\Models\ConsultationAttachment;
use App\Models\TicketRoutingHistory;
use App\Models\TicketQualityReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB
    private const MAX_ATTACHMENTS_PER_SUBMISSION = 3;

    // JPEG: FF D8 FF, PNG: 89 50 4E 47
    private const FILE_SIGNATURES = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png'  => ["\x89\x50\x4E\x47"],
    ];

    public function __construct(
        private AuditService $auditService,
    ) {}

    /**
     * Create a new consultation ticket.
     */
    public function createTicket(
        int $applicantId,
        string $categoryTag,
        string $priority,
        string $messageText,
        ?string $departmentId = null,
        array $attachments = []
    ): ConsultationTicket {
        return DB::transaction(function () use ($applicantId, $categoryTag, $priority, $messageText, $departmentId, $attachments) {
            // Validate attachments before creating ticket
            $this->validateAttachmentList($attachments);

            $ticketNo = $this->generateTicketNumber();
            $dueAt = $this->computeSlaDeadline($priority);

            $ticket = ConsultationTicket::create([
                'local_ticket_no' => $ticketNo,
                'applicant_id' => $applicantId,
                'category_tag' => $categoryTag,
                'priority' => $priority,
                'department_id' => $departmentId,
                'status' => 'new',
                'first_response_due_at' => $dueAt,
                'initial_message' => $messageText,
            ]);

            // Store initial message in transcript
            ConsultationMessage::create([
                'ticket_id' => $ticket->id,
                'sender_user_id' => $applicantId,
                'message_text' => $messageText,
                'created_at' => now(),
            ]);

            // Process attachments
            foreach ($attachments as $file) {
                $this->processAttachment($ticket, $file);
            }

            $this->auditService->log(
                'consultation_ticket', (string) $ticket->id, 'ticket_created',
                $applicantId, 'applicant', request()?->ip(),
                null, $this->auditService->computeEntityHash($ticket->toArray()),
                ['ticket_no' => $ticketNo, 'priority' => $priority]
            );

            return $ticket;
        });
    }

    /**
     * Add a reply message to a ticket.
     */
    public function addReply(ConsultationTicket $ticket, int $senderUserId, string $messageText): ConsultationMessage
    {
        if ($ticket->isTranscriptLocked()) {
            throw new \RuntimeException('Ticket transcript is locked for quality review.');
        }

        if (in_array($ticket->status, ['closed', 'auto_closed'])) {
            throw new \RuntimeException('Cannot reply to a closed ticket.');
        }

        $message = ConsultationMessage::create([
            'ticket_id' => $ticket->id,
            'sender_user_id' => $senderUserId,
            'message_text' => $messageText,
            'created_at' => now(),
        ]);

        // If this is the first advisor response, record it
        if (is_null($ticket->first_responded_at) && $senderUserId !== $ticket->applicant_id) {
            $ticket->update(['first_responded_at' => now()]);
        }

        return $message;
    }

    /**
     * Transition ticket status.
     */
    public function transitionStatus(ConsultationTicket $ticket, string $newStatus, int $actorId): ConsultationTicket
    {
        if ($ticket->isQualityLocked()) {
            throw new \RuntimeException('Ticket is locked for quality review. Status changes are not permitted.');
        }

        if (!$ticket->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition from '{$ticket->status}' to '{$newStatus}'."
            );
        }

        $updates = ['status' => $newStatus];
        if (in_array($newStatus, ['closed', 'auto_closed'])) {
            $updates['closed_at'] = now();
        }

        $ticket->update($updates);

        $this->auditService->log(
            'consultation_ticket', (string) $ticket->id, "ticket_status_{$newStatus}",
            $actorId, null, request()?->ip()
        );

        return $ticket->fresh();
    }

    /**
     * Reassign a ticket (manager action, requires reason).
     */
    public function reassignTicket(
        ConsultationTicket $ticket,
        int $actorId,
        ?int $toAdvisorId,
        ?string $toDepartmentId,
        string $reason
    ): ConsultationTicket {
        if ($ticket->isQualityLocked()) {
            throw new \RuntimeException('Ticket is locked for quality review. Reassignment is not permitted.');
        }

        $fromAdvisor = $ticket->advisor_id;
        $fromDept = $ticket->department_id;

        TicketRoutingHistory::create([
            'ticket_id' => $ticket->id,
            'from_department' => $fromDept,
            'to_department' => $toDepartmentId ?? $fromDept,
            'from_advisor' => $fromAdvisor,
            'to_advisor' => $toAdvisorId,
            'reason' => $reason,
            'actor_user_id' => $actorId,
            'created_at' => now(),
        ]);

        $ticket->update([
            'advisor_id' => $toAdvisorId,
            'department_id' => $toDepartmentId ?? $ticket->department_id,
            'status' => 'reassigned',
        ]);

        $this->auditService->log(
            'consultation_ticket', (string) $ticket->id, 'ticket_reassigned',
            $actorId, null, request()?->ip(),
            null, null, ['reason' => $reason, 'to_advisor' => $toAdvisorId]
        );

        return $ticket->fresh();
    }

    /**
     * Recalculate SLA overdue flags.
     */
    public function recalculateOverdueFlags(): int
    {
        $updated = ConsultationTicket::whereNotIn('status', ['closed', 'auto_closed', 'resolved'])
            ->whereNotNull('first_response_due_at')
            ->whereNull('first_responded_at')
            ->where('first_response_due_at', '<', now())
            ->where('overdue_flag', false)
            ->update(['overdue_flag' => true]);

        return $updated;
    }

    /**
     * Sample 5% of closed tickets per advisor for quality review.
     * Idempotent per advisor/week.
     */
    public function sampleQualityReviews(string $weekIdentifier): array
    {
        $sampled = [];

        // Get advisors with closed tickets that week
        $advisorTickets = ConsultationTicket::where('status', 'closed')
            ->whereNotNull('advisor_id')
            ->whereBetween('closed_at', [
                now()->startOfWeek()->subWeek(),
                now()->startOfWeek(),
            ])
            ->select('advisor_id', DB::raw('COUNT(*) as count'))
            ->groupBy('advisor_id')
            ->get();

        foreach ($advisorTickets as $row) {
            // Check if already sampled for this week
            $existing = TicketQualityReview::where('sampled_week', $weekIdentifier)
                ->where('advisor_id', $row->advisor_id)
                ->exists();

            if ($existing) {
                continue;
            }

            // 5% sample, minimum 1
            $sampleSize = max(1, (int) ceil($row->count * 0.05));

            $tickets = ConsultationTicket::where('status', 'closed')
                ->where('advisor_id', $row->advisor_id)
                ->whereBetween('closed_at', [
                    now()->startOfWeek()->subWeek(),
                    now()->startOfWeek(),
                ])
                ->inRandomOrder()
                ->limit($sampleSize)
                ->get();

            foreach ($tickets as $ticket) {
                $review = TicketQualityReview::create([
                    'sampled_week' => $weekIdentifier,
                    'advisor_id' => $row->advisor_id,
                    'ticket_id' => $ticket->id,
                    'review_state' => 'pending',
                    'locked_at' => now(),
                ]);
                $sampled[] = $review;
            }
        }

        return $sampled;
    }

    // --- Attachment Handling ---

    public function validateAttachmentList(array $files): void
    {
        if (count($files) > self::MAX_ATTACHMENTS_PER_SUBMISSION) {
            throw new \InvalidArgumentException("Maximum " . self::MAX_ATTACHMENTS_PER_SUBMISSION . " attachments per submission.");
        }
    }

    public function processAttachment(ConsultationTicket $ticket, $file): ConsultationAttachment
    {
        $mimeType = $file->getMimeType();
        $size = $file->getSize();
        $originalName = $file->getClientOriginalName();

        // Validate MIME type
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException("File type '{$mimeType}' not allowed. Only JPEG and PNG accepted.");
        }

        // Validate size
        if ($size > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException("File exceeds maximum size of 5 MB.");
        }

        // Validate file signature (magic bytes)
        $handle = fopen($file->getRealPath(), 'rb');
        $header = fread($handle, 8);
        fclose($handle);

        $signatureValid = false;
        foreach (self::FILE_SIGNATURES[$mimeType] ?? [] as $sig) {
            if (str_starts_with($header, $sig)) {
                $signatureValid = true;
                break;
            }
        }

        // Compute SHA-256
        $sha256 = hash_file('sha256', $file->getRealPath());

        if (!$signatureValid) {
            // Quarantine the file
            $quarantinePath = 'quarantine/' . Str::uuid() . '_' . $originalName;
            $file->storeAs('quarantine', basename($quarantinePath), 'local');

            $attachment = ConsultationAttachment::create([
                'ticket_id' => $ticket->id,
                'original_filename' => $originalName,
                'mime_type' => $mimeType,
                'file_size' => $size,
                'storage_path' => $quarantinePath,
                'sha256_fingerprint' => $sha256,
                'upload_status' => 'quarantined',
                'quarantine_reason' => 'File signature does not match declared MIME type.',
            ]);

            $this->auditService->log(
                'consultation_attachment', (string) $attachment->id, 'attachment_quarantined',
                null, null, request()?->ip(),
                null, null, ['reason' => 'signature_mismatch', 'declared_type' => $mimeType]
            );

            return $attachment;
        }

        // Store the file
        $storagePath = 'attachments/' . date('Y/m/d') . '/' . Str::uuid() . '_' . $originalName;
        $file->storeAs(dirname($storagePath), basename($storagePath), 'local');

        $attachment = ConsultationAttachment::create([
            'ticket_id' => $ticket->id,
            'original_filename' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => $size,
            'storage_path' => $storagePath,
            'sha256_fingerprint' => $sha256,
            'upload_status' => 'completed',
        ]);

        return $attachment;
    }

    // --- SLA Computation ---

    public function computeSlaDeadline(string $priority): \DateTime
    {
        $now = now();

        if ($priority === 'High') {
            $hours = (int) config('sla.high_priority_hours', 2);
            return $this->addBusinessHours($now, $hours);
        }

        // Normal: 1 business day = same time next business day
        $days = (int) config('sla.normal_priority_days', 1);
        return $this->addBusinessDays($now, $days);
    }

    /**
     * Add N business hours to a start time, skipping non-working periods.
     */
    private function addBusinessHours(\DateTime $start, int $hours): \DateTime
    {
        $businessStart = config('sla.business_hours_start', '08:00');
        $businessEnd = config('sla.business_hours_end', '17:00');
        $businessDays = array_map('intval', explode(',', config('sla.business_days', '1,2,3,4,5')));

        $current = clone $start;
        $remaining = $hours;

        while ($remaining > 0) {
            $dayOfWeek = (int) $current->format('N');

            if (in_array($dayOfWeek, $businessDays)) {
                $dayStart = (clone $current)->setTime(...array_map('intval', explode(':', $businessStart)));
                $dayEnd = (clone $current)->setTime(...array_map('intval', explode(':', $businessEnd)));

                if ($current < $dayStart) {
                    $current = $dayStart;
                }

                if ($current < $dayEnd) {
                    $availableHours = ($dayEnd->getTimestamp() - $current->getTimestamp()) / 3600;

                    if ($availableHours >= $remaining) {
                        $current->modify("+{$remaining} hours");
                        $remaining = 0;
                    } else {
                        $remaining -= $availableHours;
                        $current = $dayEnd;
                    }
                }
            }

            if ($remaining > 0) {
                $current->modify('+1 day');
                $current->setTime(...array_map('intval', explode(':', $businessStart)));
            }
        }

        return $current;
    }

    /**
     * Add N business days to a start time.
     * The deadline is the same clock time N business days later,
     * clamped to within business hours.
     */
    private function addBusinessDays(\DateTime $start, int $days): \DateTime
    {
        $businessStart = config('sla.business_hours_start', '08:00');
        $businessEnd = config('sla.business_hours_end', '17:00');
        $businessDays = array_map('intval', explode(',', config('sla.business_days', '1,2,3,4,5')));

        $current = clone $start;

        // Clamp start to business hours for the target time calculation
        $dayStart = (clone $current)->setTime(...array_map('intval', explode(':', $businessStart)));
        $dayEnd = (clone $current)->setTime(...array_map('intval', explode(':', $businessEnd)));

        // Determine the target time-of-day (clamped to business hours)
        if ($current < $dayStart) {
            $targetTime = $businessStart;
        } elseif ($current > $dayEnd) {
            $targetTime = $businessEnd;
        } else {
            $targetTime = $current->format('H:i');
        }

        $remaining = $days;
        while ($remaining > 0) {
            $current->modify('+1 day');
            $dayOfWeek = (int) $current->format('N');
            if (in_array($dayOfWeek, $businessDays)) {
                $remaining--;
            }
        }

        // Set the target time on the final business day
        $current->setTime(...array_map('intval', explode(':', $targetTime)));

        return $current;
    }

    private function generateTicketNumber(): string
    {
        $date = now()->format('Ymd');
        $last = ConsultationTicket::where('local_ticket_no', 'like', "TKT-{$date}-%")
            ->orderBy('id', 'desc')
            ->value('local_ticket_no');

        if ($last) {
            $seq = (int) substr($last, -4) + 1;
        } else {
            $seq = 1;
        }

        return sprintf('TKT-%s-%04d', $date, $seq);
    }
}
