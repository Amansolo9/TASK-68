<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\LoginAttempt;

class LoginThrottle
{
    public function handle(Request $request, Closure $next)
    {
        $ip = $request->ip();
        $windowMinutes = config('auth.login.lockout_window_minutes', 15);

        // Check IP-based rate limiting (100 attempts per window from same IP)
        $ipAttempts = LoginAttempt::where('ip_address', $ip)
            ->where('attempted_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        if ($ipAttempts >= 100) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => $request->header('X-Correlation-ID', '')],
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Too many login attempts from this address. Please try again later.',
                    'details' => [],
                ],
            ], 429);
        }

        return $next($request);
    }
}
