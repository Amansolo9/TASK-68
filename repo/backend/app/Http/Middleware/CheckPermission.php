<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions)
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

        // When multiple permissions are given, user needs at least one (OR logic).
        // This allows routes like permission:appointments.book,appointments.manage
        // to be accessible by users with either permission.
        $hasAny = false;
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                $hasAny = true;
                break;
            }
        }

        if (!$hasAny) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => $request->header('X-Correlation-ID', '')],
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have the required permission: ' . implode(' or ', $permissions) . '.',
                    'details' => [],
                ],
            ], 403);
        }

        return $next($request);
    }
}
