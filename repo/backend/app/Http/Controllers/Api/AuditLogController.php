<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use HasApiResponse;

    public function __construct(private AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::orderBy('created_at', 'desc');

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }
        if ($request->has('entity_id')) {
            $query->where('entity_id', $request->input('entity_id'));
        }
        if ($request->has('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }
        if ($request->has('actor_user_id')) {
            $query->where('actor_user_id', $request->input('actor_user_id'));
        }
        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->input('from_date'));
        }
        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->input('to_date'));
        }

        $logs = $query->paginate($request->input('per_page', 50));

        return $this->paginated($logs);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        return $this->success($auditLog);
    }

    public function verifyChain(Request $request): JsonResponse
    {
        $fromId = $request->input('from_id', 0);
        $result = $this->auditService->verifyChain($fromId);

        return $this->success($result);
    }
}
