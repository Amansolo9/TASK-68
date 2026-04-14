<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Services\AppointmentService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class AppointmentController extends Controller
{
    use HasApiResponse;

    public function __construct(private AppointmentService $appointmentService) {}

    // --- Slots ---

    public function slots(Request $request): JsonResponse
    {
        $query = AppointmentSlot::where('status', 'open')
            ->where('start_at', '>', now());

        if ($request->has('department_id')) $query->where('department_id', $request->input('department_id'));
        if ($request->has('slot_type')) $query->where('slot_type', $request->input('slot_type'));
        if ($request->has('date_from')) $query->where('start_at', '>=', $request->input('date_from'));
        if ($request->has('date_to')) $query->where('start_at', '<=', $request->input('date_to'));

        return $this->paginated($query->orderBy('start_at')->paginate($request->input('per_page', 20)));
    }

    public function createSlot(Request $request): JsonResponse
    {
        $request->validate([
            'slot_type' => 'required|string|max:50',
            'department_id' => 'nullable|string|max:50',
            'advisor_id' => 'nullable|integer|exists:users,id',
            'start_at' => 'required|date|after:now',
            'end_at' => 'required|date|after:start_at',
            'capacity' => 'required|integer|min:1',
        ]);

        $slot = AppointmentSlot::create([
            ...$request->only(['slot_type', 'department_id', 'advisor_id', 'start_at', 'end_at', 'capacity']),
            'available_qty' => $request->input('capacity'),
        ]);

        return $this->success($slot, [], 201);
    }

    // --- Staff appointment listing ---

    public function staffAppointments(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = Appointment::with(['slot', 'applicant:id,full_name']);

        // Advisors see appointments for their slots; managers/admins see all
        if ($user->hasAnyRole(['manager', 'admin'])) {
            // No additional scope — full access
        } elseif ($user->hasRole('advisor')) {
            $query->whereHas('slot', fn ($q) => $q->where('advisor_id', $user->id));
        }

        if ($request->has('state')) $query->where('state', $request->input('state'));
        if ($request->has('date_from')) $query->whereHas('slot', fn ($q) => $q->where('start_at', '>=', $request->input('date_from')));
        if ($request->has('date_to')) $query->whereHas('slot', fn ($q) => $q->where('start_at', '<=', $request->input('date_to')));

        return $this->paginated($query->orderByDesc('booked_at')->paginate($request->input('per_page', 20)));
    }

    // --- Bookings ---

    public function myAppointments(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = Appointment::with('slot')
            ->where('applicant_id', $user->id);

        if ($request->has('state')) $query->where('state', $request->input('state'));

        return $this->paginated($query->orderByDesc('booked_at')->paginate($request->input('per_page', 20)));
    }

    public function book(Request $request): JsonResponse
    {
        $request->validate([
            'slot_id' => 'required|integer|exists:appointment_slots,id',
            'request_key' => 'required|string|max:64',
            'booking_type' => 'nullable|string|max:50',
        ]);

        try {
            $appointment = $this->appointmentService->book(
                Auth::id(),
                $request->input('slot_id'),
                $request->input('request_key'),
                $request->input('booking_type', 'standard'),
                $request->ip()
            );

            return $this->success($appointment->load('slot'), [], 201);
        } catch (\RuntimeException $e) {
            return $this->error('BOOKING_FAILED', $e->getMessage(), [], 409);
        } catch (\InvalidArgumentException $e) {
            return $this->error('BOOKING_ERROR', $e->getMessage(), [], 400);
        }
    }

    public function reschedule(Request $request, Appointment $appointment): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('reschedule', $appointment)) {
            return $this->error('FORBIDDEN', 'You are not authorized to reschedule this appointment.', [], 403);
        }

        $request->validate([
            'new_slot_id' => 'required|integer|exists:appointment_slots,id',
            'request_key' => 'required|string|max:64',
            'reason' => 'required|string',
        ]);

        $isStaffAction = Auth::user()->hasPermission('appointments.manage');

        try {
            $appointment = $this->appointmentService->reschedule(
                $appointment,
                $request->input('new_slot_id'),
                $request->input('request_key'),
                $request->input('reason'),
                Auth::id(),
                $request->ip(),
                $isStaffAction
            );
            return $this->success($appointment->load('slot'));
        } catch (\InvalidArgumentException $e) {
            return $this->error('APPOINTMENT_RESCHEDULE_WINDOW_EXCEEDED', $e->getMessage(), [], 409);
        } catch (\RuntimeException $e) {
            return $this->error('RESCHEDULE_FAILED', $e->getMessage(), [], 409);
        }
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('cancel', $appointment)) {
            return $this->error('FORBIDDEN', 'You are not authorized to cancel this appointment.', [], 403);
        }

        $request->validate([
            'reason' => 'required|string',
        ]);

        $isOverride = $request->boolean('override', false);
        if ($isOverride && !Auth::user()->hasAnyRole(['manager', 'admin'])) {
            return $this->error('FORBIDDEN', 'Override requires manager or admin role.', [], 403);
        }

        try {
            $appointment = $this->appointmentService->cancel(
                $appointment,
                $request->input('reason'),
                Auth::id(),
                $request->ip(),
                $isOverride
            );
            return $this->success($appointment);
        } catch (\InvalidArgumentException $e) {
            return $this->error('APPOINTMENT_CANCEL_WINDOW_EXCEEDED', $e->getMessage(), [], 409);
        }
    }

    public function noShow(Appointment $appointment): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('markNoShow', $appointment)) {
            return $this->error('FORBIDDEN', 'You are not authorized to mark no-show.', [], 403);
        }

        try {
            $appointment = $this->appointmentService->markNoShow($appointment, Auth::id(), request()->ip());
            return $this->success($appointment);
        } catch (\InvalidArgumentException $e) {
            return $this->error('NO_SHOW_ERROR', $e->getMessage(), [], 409);
        }
    }

    public function complete(Appointment $appointment): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('complete', $appointment)) {
            return $this->error('FORBIDDEN', 'You are not authorized to complete this appointment.', [], 403);
        }

        try {
            $appointment = $this->appointmentService->complete($appointment, Auth::id(), request()->ip());
            return $this->success($appointment);
        } catch (\InvalidArgumentException $e) {
            return $this->error('COMPLETE_ERROR', $e->getMessage(), [], 409);
        }
    }

    public function show(Appointment $appointment): JsonResponse
    {
        if (Gate::forUser(Auth::user())->denies('view', $appointment)) {
            return $this->error('FORBIDDEN', 'You are not authorized to view this appointment.', [], 403);
        }

        $appointment->load(['slot', 'stateHistory', 'applicant:id,full_name']);
        return $this->success($appointment);
    }
}
