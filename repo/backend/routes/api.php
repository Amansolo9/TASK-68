<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\CaptchaController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\DataDictionaryController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PersonnelController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\CourseCategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AdmissionsPlanController;
use App\Http\Controllers\Api\ConsultationTicketController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\MergeController;
use App\Http\Controllers\Api\ReportingController;

// ═══════════════════════════════════════════════════════
// Public routes (no auth)
// ═══════════════════════════════════════════════════════
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle.login');
Route::post('/auth/captcha', [CaptchaController::class, 'generate']);
Route::get('/auth/captcha/{key}', [CaptchaController::class, 'image']);

// ═══════════════════════════════════════════════════════
// Authenticated but MFA-exempt (login flow, session, MFA setup)
// ═══════════════════════════════════════════════════════
Route::middleware(['auth.signed'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/session', [AuthController::class, 'session']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // MFA endpoints must be reachable before MFA is verified
    Route::prefix('mfa')->group(function () {
        Route::post('/setup', [MfaController::class, 'setup']);
        Route::post('/verify', [MfaController::class, 'verify']);
        Route::post('/verify-login', [MfaController::class, 'verifyLogin']);
        Route::post('/recovery/use', [MfaController::class, 'useRecoveryCode']);
    });
});

// ═══════════════════════════════════════════════════════
// Protected routes: auth + MFA enforced + operation logging
// (Fix 4: backend MFA enforcement, Fix 7: operation logging)
// ═══════════════════════════════════════════════════════
Route::middleware(['auth.signed', 'mfa.required', 'log.operation'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/poll', [DashboardController::class, 'poll']);

    // ── Published plans: all authenticated users (Fix 3) ──
    Route::get('/published-plans', [AdmissionsPlanController::class, 'publishedPlans']);
    Route::get('/published-plans/{admissionsPlan}', [AdmissionsPlanController::class, 'publishedPlanDetail']);

    // Read-only dictionary lookup for all
    Route::get('/lookup/dictionaries/{type}', [DataDictionaryController::class, 'lookup']);

    // Read-only master data for all
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::get('/personnel', [PersonnelController::class, 'index']);
    Route::get('/personnel/{personnel}', [PersonnelController::class, 'show']);
    Route::get('/positions', [PositionController::class, 'index']);
    Route::get('/positions/{position}', [PositionController::class, 'show']);
    Route::get('/course-categories', [CourseCategoryController::class, 'index']);
    Route::get('/course-categories/{courseCategory}', [CourseCategoryController::class, 'show']);

    // ── MFA admin actions ──
    Route::middleware(['permission:security.manage'])->group(function () {
        Route::post('/mfa/disable', [MfaController::class, 'disable']);
        Route::post('/mfa/recovery/generate', [MfaController::class, 'generateRecoveryCodes']);
    });

    // ── User management (admin) ──
    Route::middleware(['permission:security.manage'])->prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{user}', [UserController::class, 'show']);
        Route::put('/{user}', [UserController::class, 'update']);
        Route::post('/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('/{user}/activate', [UserController::class, 'activate']);
        Route::post('/{user}/unlock', [UserController::class, 'unlock']);
        Route::post('/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::put('/{user}/roles', [UserController::class, 'updateRoles']);
    });

    // ── Audit logs ──
    Route::middleware(['permission:audit.view'])->prefix('audit-logs')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/verify/chain', [AuditLogController::class, 'verifyChain']);
        Route::get('/{auditLog}', [AuditLogController::class, 'show']);
    });

    // ── Dictionaries (steward/admin write) ──
    Route::middleware(['permission:masterdata.edit', 'content.permission:masterdata,edit'])->prefix('dictionaries')->group(function () {
        Route::get('/', [DataDictionaryController::class, 'index']);
        Route::post('/', [DataDictionaryController::class, 'store']);
        Route::get('/{dictionary}', [DataDictionaryController::class, 'show']);
        Route::put('/{dictionary}', [DataDictionaryController::class, 'update']);
        Route::delete('/{dictionary}', [DataDictionaryController::class, 'destroy']);
    });

    // ── Master data write (steward/admin) ──
    Route::middleware(['permission:masterdata.edit', 'content.permission:masterdata,edit'])->group(function () {
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::put('/organizations/{organization}', [OrganizationController::class, 'update']);
        Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);
        Route::post('/personnel', [PersonnelController::class, 'store']);
        Route::put('/personnel/{personnel}', [PersonnelController::class, 'update']);
        Route::delete('/personnel/{personnel}', [PersonnelController::class, 'destroy']);
        Route::post('/positions', [PositionController::class, 'store']);
        Route::put('/positions/{position}', [PositionController::class, 'update']);
        Route::delete('/positions/{position}', [PositionController::class, 'destroy']);
        Route::post('/course-categories', [CourseCategoryController::class, 'store']);
        Route::put('/course-categories/{courseCategory}', [CourseCategoryController::class, 'update']);
        Route::delete('/course-categories/{courseCategory}', [CourseCategoryController::class, 'destroy']);
    });

    // ── Admissions Plans: internal management (Fix 3 – NOT for applicants) ──
    Route::middleware(['permission:plans.create_version', 'content.permission:plans,manage'])->prefix('admissions-plans')->group(function () {
        Route::get('/', [AdmissionsPlanController::class, 'index']);
        Route::post('/', [AdmissionsPlanController::class, 'store']);
        Route::get('/{admissionsPlan}', [AdmissionsPlanController::class, 'show']);

        // Versions
        Route::post('/{admissionsPlan}/versions', [AdmissionsPlanController::class, 'createVersion']);
        Route::post('/{admissionsPlan}/derive-from-published', [AdmissionsPlanController::class, 'deriveFromPublished']);
        Route::get('/{admissionsPlan}/versions/{version}', [AdmissionsPlanController::class, 'showVersion']);
        Route::put('/{admissionsPlan}/versions/{version}', [AdmissionsPlanController::class, 'updateVersion']);

        // Programs
        Route::post('/{admissionsPlan}/versions/{version}/programs', [AdmissionsPlanController::class, 'addProgram']);
        Route::put('/{admissionsPlan}/versions/{version}/programs/{program}', [AdmissionsPlanController::class, 'updateProgram']);
        Route::delete('/{admissionsPlan}/versions/{version}/programs/{program}', [AdmissionsPlanController::class, 'removeProgram']);

        // Tracks
        Route::post('/{admissionsPlan}/versions/{version}/programs/{program}/tracks', [AdmissionsPlanController::class, 'addTrack']);
        Route::put('/{admissionsPlan}/versions/{version}/programs/{program}/tracks/{track}', [AdmissionsPlanController::class, 'updateTrack']);
        Route::delete('/{admissionsPlan}/versions/{version}/programs/{program}/tracks/{track}', [AdmissionsPlanController::class, 'removeTrack']);

        // Transitions (Fix 5: per-transition permission checked inside controller)
        Route::post('/{admissionsPlan}/versions/{version}/transition', [AdmissionsPlanController::class, 'transitionState']);

        // Comparison & integrity
        Route::post('/{admissionsPlan}/compare', [AdmissionsPlanController::class, 'compareVersions']);
        Route::get('/{admissionsPlan}/versions/{version}/integrity', [AdmissionsPlanController::class, 'verifyIntegrity']);
    });

    // ── Consultation Tickets ──
    Route::prefix('tickets')->middleware(['content.permission:tickets,view'])->group(function () {
        Route::middleware(['permission:tickets.create'])->post('/', [ConsultationTicketController::class, 'store']);

        Route::get('/', [ConsultationTicketController::class, 'index']);
        Route::get('/{ticket}', [ConsultationTicketController::class, 'show']);
        Route::get('/{ticket}/poll', [ConsultationTicketController::class, 'poll']);
        Route::post('/{ticket}/reply', [ConsultationTicketController::class, 'reply']);

        Route::middleware(['permission:tickets.reply_assigned'])->group(function () {
            Route::post('/{ticket}/transition', [ConsultationTicketController::class, 'transition']);
        });
        Route::middleware(['permission:tickets.reassign'])->group(function () {
            Route::post('/{ticket}/reassign', [ConsultationTicketController::class, 'reassign']);
        });
    });

    // ── Quality Reviews ──
    Route::middleware(['permission:tickets.review_sampled'])->prefix('quality-reviews')->group(function () {
        Route::get('/', [ConsultationTicketController::class, 'qualityReviews']);
        Route::put('/{review}', [ConsultationTicketController::class, 'updateQualityReview']);
    });

    // ── Appointments ──
    Route::prefix('appointments')->middleware(['content.permission:appointments,view'])->group(function () {
        // Slot management (staff only)
        Route::middleware(['permission:appointments.manage'])->group(function () {
            Route::get('/slots', [AppointmentController::class, 'slots']);
            Route::post('/slots', [AppointmentController::class, 'createSlot']);
        });

        // Applicant self-service: own appointments only
        Route::middleware(['permission:appointments.book'])->group(function () {
            Route::get('/my', [AppointmentController::class, 'myAppointments']);
            Route::post('/book', [AppointmentController::class, 'book']);
        });

        // Staff appointment management listing
        Route::middleware(['permission:appointments.manage'])->group(function () {
            Route::get('/', [AppointmentController::class, 'staffAppointments']);
        });

        // Reschedule/cancel: accessible by both applicants (book) and staff (manage)
        // Object-level authorization enforced via AppointmentPolicy
        Route::middleware(['permission:appointments.book,appointments.manage'])->group(function () {
            Route::post('/{appointment}/reschedule', [AppointmentController::class, 'reschedule']);
            Route::post('/{appointment}/cancel', [AppointmentController::class, 'cancel']);
        });

        // Staff-only actions
        Route::middleware(['permission:appointments.manage'])->group(function () {
            Route::get('/{appointment}', [AppointmentController::class, 'show']);
            Route::post('/{appointment}/no-show', [AppointmentController::class, 'noShow']);
            Route::post('/{appointment}/complete', [AppointmentController::class, 'complete']);
        });
    });

    // ── Duplicate Detection & Merge ──
    Route::prefix('duplicates')->middleware(['permission:masterdata.merge_request', 'content.permission:masterdata,merge'])->group(function () {
        Route::get('/', [MergeController::class, 'duplicates']);
        Route::post('/detect', [MergeController::class, 'runDetection']);
        Route::put('/{duplicate}', [MergeController::class, 'updateDuplicate']);
    });
    Route::prefix('merge-requests')->middleware(['content.permission:masterdata,merge'])->group(function () {
        Route::middleware(['permission:masterdata.merge_request'])->group(function () {
            Route::get('/', [MergeController::class, 'mergeRequests']);
            Route::post('/', [MergeController::class, 'createMergeRequest']);
        });
        Route::middleware(['permission:masterdata.merge_approve'])->group(function () {
            Route::post('/{mergeRequest}/transition', [MergeController::class, 'transitionMerge']);
            Route::post('/{mergeRequest}/execute', [MergeController::class, 'executeMerge']);
        });
    });

    // ── Reporting ──
    Route::middleware(['permission:reports.view', 'content.permission:reports,view'])->prefix('reports')->group(function () {
        Route::get('/tickets', [ReportingController::class, 'ticketStats']);
        Route::get('/appointments', [ReportingController::class, 'appointmentStats']);
        Route::get('/plans', [ReportingController::class, 'planStats']);
        Route::get('/data-quality', [ReportingController::class, 'dataQualityMetrics']);
        Route::get('/data-quality/trend', [ReportingController::class, 'dataQualityTrend']);
        Route::get('/merges', [ReportingController::class, 'mergeStats']);
        Route::get('/export', [ReportingController::class, 'exportCsv']);
    });
});
