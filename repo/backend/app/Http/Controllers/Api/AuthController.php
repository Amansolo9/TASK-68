<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\SessionTokenService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private AuthService $authService,
        private SessionTokenService $sessionTokenService,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string|max:100',
            'password' => 'required|string',
            'captcha_key' => 'nullable|string',
            'captcha_answer' => 'nullable|string',
        ]);

        $result = $this->authService->attemptLogin(
            $request->input('username'),
            $request->input('password'),
            $request->ip(),
            $request->userAgent(),
            $request->input('captcha_key'),
            $request->input('captcha_answer')
        );

        if (!$result['success']) {
            $status = $result['captcha_required'] ? 403 : 401;
            return $this->error(
                $result['captcha_required'] ? 'CAPTCHA_REQUIRED' : 'INVALID_CREDENTIALS',
                $result['error'],
                ['captcha_required' => $result['captcha_required']],
                $status
            );
        }

        $user = $result['user'];
        $responseData = [
            'token' => $result['token'],
            'mfa_required' => $result['mfa_required'],
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'roles' => $user->getRoles(),
                'totp_enabled' => $user->totp_enabled,
            ],
        ];

        return $this->success($responseData);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = Auth::user();
        $guard = Auth::guard();
        $session = method_exists($guard, 'currentSession') ? $guard->currentSession() : null;

        if ($user && $session) {
            $this->authService->logout($user, $session->token_id, $request->ip());
        }

        return $this->success(['message' => 'Logged out successfully.']);
    }

    public function session(Request $request): JsonResponse
    {
        $user = Auth::user();
        $guard = Auth::guard();
        $session = method_exists($guard, 'currentSession') ? $guard->currentSession() : null;

        return $this->success([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'roles' => $user->getRoles(),
                'totp_enabled' => $user->totp_enabled,
                'mfa_verified' => $session ? $session->mfa_verified : false,
            ],
            'session' => $session ? [
                'issued_at' => $session->issued_at->toIso8601String(),
                'expires_at' => $session->expires_at->toIso8601String(),
                'mfa_verified' => $session->mfa_verified,
            ] : null,
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $guard = Auth::guard();
        $session = method_exists($guard, 'currentSession') ? $guard->currentSession() : null;

        if (!$session) {
            return $this->error('INVALID_SESSION', 'No active session found.', [], 401);
        }

        [$token, $newSession] = $this->sessionTokenService->refresh(
            $session,
            $request->ip(),
            $request->userAgent()
        );

        return $this->success([
            'token' => $token,
            'expires_at' => $newSession->expires_at->toIso8601String(),
        ]);
    }
}
