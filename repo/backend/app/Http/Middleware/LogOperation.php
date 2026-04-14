<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\OperationLog;
use Illuminate\Support\Facades\Auth;

class LogOperation
{
    /**
     * Sensitive keys that must be redacted at any nesting depth.
     */
    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'totp_secret',
        'totp_code',
        'mfa_code',
        'recovery_code',
        'captcha_answer',
        'token',
        'session_token',
        'secret',
        'date_of_birth',
        'dob',
        'government_id',
        'national_id',
        'id_number',
        'institutional_id',
        'signature',
        'encrypted_date_of_birth',
        'encrypted_government_id',
        'encrypted_institutional_id',
        'authorization',
        'cookie',
    ];

    /**
     * Routes where only explicitly allowed keys are logged (allowlist approach).
     */
    private const ALLOWLISTED_ROUTES = [
        'auth/login' => ['username'],
        'auth/logout' => [],
        'auth/refresh' => [],
        'mfa/setup' => [],
        'mfa/verify' => [],
        'mfa/verify-login' => [],
        'mfa/recovery/use' => [],
        'mfa/disable' => ['user_id'],
        'mfa/recovery/generate' => ['user_id'],
        'users/*/reset-password' => [],
    ];

    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);

        $response = $next($request);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($this->shouldLog($request)) {
            OperationLog::create([
                'correlation_id' => $request->header('X-Correlation-ID', ''),
                'user_id' => Auth::id(),
                'route' => $request->path(),
                'method' => $request->method(),
                'request_summary' => $this->summarizeRequest($request),
                'outcome' => $response->getStatusCode() < 400 ? 'success' : 'failure',
                'latency_ms' => $latencyMs,
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }

    private function shouldLog(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE']);
    }

    private function summarizeRequest(Request $request): array
    {
        $summary = [
            'path' => $request->path(),
            'method' => $request->method(),
        ];

        // Check if this route should use an allowlist
        $allowedKeys = $this->getAllowlistForRoute($request->path());

        if ($allowedKeys !== null) {
            // Allowlisted route: only include explicitly allowed keys
            $params = [];
            foreach ($allowedKeys as $key) {
                if ($request->has($key)) {
                    $params[$key] = $request->input($key);
                }
            }
            $summary['params'] = $params;
        } else {
            // General route: redact sensitive keys recursively
            $allInput = $request->all();
            $summary['params'] = $this->redactSensitive($allInput);
        }

        return $summary;
    }

    /**
     * Check if the given path matches an allowlisted route pattern.
     * Returns the array of allowed keys, or null if no allowlist applies.
     */
    private function getAllowlistForRoute(string $path): ?array
    {
        // Normalize: strip leading "api/" if present
        $normalized = preg_replace('#^api/#', '', $path);

        foreach (self::ALLOWLISTED_ROUTES as $pattern => $keys) {
            $regex = '#^' . str_replace('*', '[^/]+', $pattern) . '$#';
            if (preg_match($regex, $normalized)) {
                return $keys;
            }
        }

        return null;
    }

    /**
     * Recursively redact sensitive keys from input data.
     */
    private function redactSensitive(mixed $data, int $depth = 0): mixed
    {
        // Prevent excessive recursion
        if ($depth > 5) {
            return '[TRUNCATED]';
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $result[$key] = '[REDACTED]';
                } else {
                    $result[$key] = $this->redactSensitive($value, $depth + 1);
                }
            }
            return $result;
        }

        if (is_string($data) && strlen($data) > 200) {
            return substr($data, 0, 200) . '...';
        }

        return $data;
    }

    /**
     * Check if a key name matches any sensitive key pattern.
     */
    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::REDACTED_KEYS as $sensitive) {
            if ($lower === $sensitive) {
                return true;
            }
            // Also catch variations like 'user_password', 'new_token', etc.
            if (str_contains($lower, $sensitive)) {
                return true;
            }
        }
        return false;
    }
}
