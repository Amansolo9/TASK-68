<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = Auth::user();

        if (!$user || !$user->hasAnyRole($roles)) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => $request->header('X-Correlation-ID', '')],
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have the required role to access this resource.',
                    'details' => [],
                ],
            ], 403);
        }

        return $next($request);
    }
}
