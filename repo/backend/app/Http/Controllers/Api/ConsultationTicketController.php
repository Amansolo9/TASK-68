<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsultationTicket;
use App\Models\TicketQualityReview;
use App\Services\TicketService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ConsultationTicketController extends Controller
{
    use HasApiResponse;

    public function __construct(private TicketService $ticketService) {}

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = ConsultationTicket::with(['applicant:id,full_name', 'advisor:id,full_name']);

        if ($user->hasRole('applicant')) {
            $query->where('applicant_id', $user->id);
        } elseif ($user->hasRole('admin')) {
            // admin: global access
        } elseif ($user->hasRole('manager')) {
            $deptScopes = $user->activeRoleScopes()
                ->whereNotNull('department_scope')
                ->pluck('department_scope')->toArray();
            if (!empty($deptScopes)) {
                $query->where(function ($q) use ($deptScopes) {
                    $q->whereIn('department_id', $deptScopes)->orWhereNull('department_id');
                });
            }
        } elseif ($user->hasRole('advisor')) {
            $hasGlobalScope = $user->activeRoleScopes()->whereNull('department_scope')->exists();
            if (!$hasGlobalScope) {
                $deptScopes = $user->activeRoleScopes()
                    ->whereNotNull('department_scope')
                    ->pluck('department_scope')->toArray();
                $query->where(function ($q) use ($user, $deptScopes) {
                    $q->where('advisor_id', $user->id);
                    if (!empty($deptScopes)) {
                        $q->orWhereIn('department_id', $deptScopes);
                    }
                });
            }
            // global-scope advisor sees all tickets
        } else {
            $query->whereRaw('1 = 0');
        }

        if ($request->has('status')) $query->where('status', $request->input('status'));
        if ($request->has('priority')) $query->where('priority', $request->input('priority'));
        if ($request->has('overdue')) $query->where('overdue_flag', true);
        if ($request->has('department_id')) $query->where('department_id', $request->input('department_id'));
        if ($request->has('advisor_id')) $query->where('advisor_id', $request->input('advisor_id'));
        if ($request->has('category_tag')) $query->where('category_tag', $request->input('category_tag'));

        return $this->paginated(
            $query->orderByDesc('overdue_flag')->orderByDesc('priority')->orderByDesc('created_at')
                ->paginate($request->input('per_page', 20))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'category_tag' => 'required|string|max:50',
            'priority' => 'required|in:Normal,High',
            'message' => 'required|string|min:10',
            'department_id' => 'nullable|string|max:50',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|mimes:jpeg,jpg,png|max:5120',
        ]);

        try {
            $ticket = $this->ticketService->createTicket(
                Auth::id(),
                $request->input('category_tag'),
                $request->input('priority'),
                $request->input('message'),
                $request->input('department_id'),
                $request->file('attachments', [])
            );

            return $this->success([
                'ticket' => $ticket->load(['messages', 'attachments']),
                'local_ticket_no' => $ticket->local_ticket_no,
            ], [], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_ERROR', $e->getMessage(), [], 422);
        }
    }

    public function show(ConsultationTicket $ticket): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('view', $ticket)) {
            return $this->error('FORBIDDEN', 'Access denied.', [], 403);
        }
        $ticket->load(['messages.sender:id,full_name', 'attachments', 'routingHistory', 'applicant:id,full_name', 'advisor:id,full_name']);
        return $this->success($ticket);
    }

    public function reply(Request $request, ConsultationTicket $ticket): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('reply', $ticket)) {
            return $this->error('FORBIDDEN', 'Access denied.', [], 403);
        }
        $request->validate(['message' => 'required|string|min:1']);

        try {
            $message = $this->ticketService->addReply($ticket, Auth::id(), $request->input('message'));
            return $this->success($message, [], 201);
        } catch (\RuntimeException $e) {
            return $this->error('TICKET_ERROR', $e->getMessage(), [], 409);
        }
    }

    public function transition(Request $request, ConsultationTicket $ticket): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('transition', $ticket)) {
            return $this->error('FORBIDDEN', 'Access denied.', [], 403);
        }
        $request->validate([
            'status' => 'required|string|in:triaged,in_progress,waiting_applicant,resolved,closed,reassigned,reopened',
        ]);

        try {
            $ticket = $this->ticketService->transitionStatus($ticket, $request->input('status'), Auth::id());
            return $this->success($ticket);
        } catch (\RuntimeException $e) {
            return $this->error('TICKET_LOCKED', $e->getMessage(), [], 409);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_TRANSITION', $e->getMessage(), [], 409);
        }
    }

    public function reassign(Request $request, ConsultationTicket $ticket): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('reassign', $ticket)) {
            return $this->error('FORBIDDEN', 'Access denied. Insufficient permissions or department scope mismatch.', [], 403);
        }
        $request->validate([
            'to_advisor_id' => 'nullable|integer|exists:users,id',
            'to_department_id' => 'nullable|string|max:50',
            'reason' => 'required|string|min:5',
        ]);

        try {
            $ticket = $this->ticketService->reassignTicket(
                $ticket, Auth::id(),
                $request->input('to_advisor_id'),
                $request->input('to_department_id'),
                $request->input('reason')
            );
            return $this->success($ticket);
        } catch (\RuntimeException $e) {
            return $this->error('TICKET_LOCKED', $e->getMessage(), [], 409);
        }
    }

    public function poll(ConsultationTicket $ticket): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('poll', $ticket)) {
            return $this->error('FORBIDDEN', 'Access denied.', [], 403);
        }
        return $this->success([
            'ticket_id' => $ticket->id,
            'status' => $ticket->status,
            'overdue_flag' => $ticket->overdue_flag,
            'message_count' => $ticket->messages()->count(),
            'last_message_at' => $ticket->messages()->max('created_at'),
            'updated_at' => $ticket->updated_at,
        ], ['poll_after_ms' => 10000]);
    }

    public function qualityReviews(Request $request): JsonResponse
    {
        $query = TicketQualityReview::with(['ticket:id,local_ticket_no,category_tag']);
        if ($request->has('sampled_week')) $query->where('sampled_week', $request->input('sampled_week'));
        if ($request->has('advisor_id')) $query->where('advisor_id', $request->input('advisor_id'));
        if ($request->has('review_state')) $query->where('review_state', $request->input('review_state'));
        return $this->paginated($query->orderByDesc('created_at')->paginate($request->input('per_page', 20)));
    }

    public function updateQualityReview(Request $request, TicketQualityReview $review): JsonResponse
    {
        $request->validate([
            'score' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string',
            'review_state' => 'sometimes|in:in_review,completed',
        ]);
        if (!$review->isLocked()) {
            return $this->error('REVIEW_NOT_LOCKED', 'Review must be locked before modification.', [], 409);
        }
        $review->update([
            'reviewer_manager_id' => Auth::id(),
            'score' => $request->input('score', $review->score),
            'notes' => $request->input('notes', $review->notes),
            'review_state' => $request->input('review_state', $review->review_state),
        ]);
        return $this->success($review->fresh());
    }
}
