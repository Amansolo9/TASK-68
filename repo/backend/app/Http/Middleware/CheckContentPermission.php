<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckContentPermission
{
    /**
     * Enforce content-level RBAC.
     * Usage: middleware('content.permission:entity_type,action')
     * e.g., content.permission:tickets,view or content.permission:plans,edit
     */
    public function handle(Request $request, Closure $next, string $entityType, string $action = 'view')
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => $request->header('X-Correlation-ID', '')],
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                    'details' => [],
                ],
            ], 401);
        }

        if (!$user->hasContentAccess($entityType, $action)) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => $request->header('X-Correlation-ID', '')],
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => "Content-level access denied for {$entityType}.{$action}.",
                    'details' => [],
                ],
            ], 403);
        }

        return $next($request);
    }
}
