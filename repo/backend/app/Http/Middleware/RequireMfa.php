<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequireMfa
{
    public function handle(Request $request, Closure $next)
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

        if ($user->requiresMfa()) {
            $guard = Auth::guard();
            if (method_exists($guard, 'isMfaVerified') && !$guard->isMfaVerified()) {
                return response()->json([
                    'data' => null,
                    'meta' => ['correlation_id' => $request->header('X-Correlation-ID', '')],
                    'error' => [
                        'code' => 'MFA_REQUIRED',
                        'message' => 'MFA verification required to access this resource.',
                        'details' => [],
                    ],
                ], 403);
            }
        }

        return $next($request);
    }
}
