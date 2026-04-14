<?php

namespace App\Services;

use App\Models\User;
use App\Models\LoginAttempt;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        private SessionTokenService $sessionTokenService,
        private AuditService $auditService,
        private CaptchaService $captchaService,
    ) {}

    /**
     * Attempt to authenticate a user.
     * Returns ['success' => bool, 'token' => ?string, 'user' => ?User, 'mfa_required' => bool, 'error' => ?string, 'captcha_required' => bool]
     */
    public function attemptLogin(string $username, string $password, string $ipAddress, ?string $userAgent = null, ?string $captchaKey = null, ?string $captchaAnswer = null): array
    {
        // Check if CAPTCHA is required
        $captchaRequired = $this->captchaService->isRequired($username);

        if ($captchaRequired) {
            if (!$captchaKey || !$captchaAnswer) {
                $this->recordAttempt($username, $ipAddress, 'captcha_failed', true, $userAgent);
                return [
                    'success' => false,
                    'token' => null,
                    'user' => null,
                    'mfa_required' => false,
                    'error' => 'CAPTCHA verification required.',
                    'captcha_required' => true,
                ];
            }

            if (!$this->captchaService->verify($captchaKey, $captchaAnswer)) {
                $this->recordAttempt($username, $ipAddress, 'captcha_failed', true, $userAgent);
                return [
                    'success' => false,
                    'token' => null,
                    'user' => null,
                    'mfa_required' => false,
                    'error' => 'CAPTCHA verification failed.',
                    'captcha_required' => true,
                ];
            }
        }

        // Find user
        $user = User::where('username', $username)->first();

        if (!$user) {
            $this->recordAttempt($username, $ipAddress, 'invalid_credentials', $captchaRequired, $userAgent);
            return [
                'success' => false,
                'token' => null,
                'user' => null,
                'mfa_required' => false,
                'error' => 'Invalid credentials.',
                'captcha_required' => $this->captchaService->isRequired($username),
            ];
        }

        // Check lockout
        if ($user->isLockedOut()) {
            $this->recordAttempt($username, $ipAddress, 'locked_out', $captchaRequired, $userAgent);
            return [
                'success' => false,
                'token' => null,
                'user' => null,
                'mfa_required' => false,
                'error' => 'Account is temporarily locked. Please try again later.',
                'captcha_required' => true,
            ];
        }

        // Check if user is active
        if (!$user->isActive()) {
            $this->recordAttempt($username, $ipAddress, 'invalid_credentials', $captchaRequired, $userAgent);
            return [
                'success' => false,
                'token' => null,
                'user' => null,
                'mfa_required' => false,
                'error' => 'Account is inactive.',
                'captcha_required' => false,
            ];
        }

        // Verify password
        if (!Hash::check($password, $user->password_hash)) {
            $this->handleFailedLogin($user, $username, $ipAddress, $captchaRequired, $userAgent);
            return [
                'success' => false,
                'token' => null,
                'user' => null,
                'mfa_required' => false,
                'error' => 'Invalid credentials.',
                'captcha_required' => $this->captchaService->isRequired($username),
            ];
        }

        // Successful credential verification — reset failed count
        $user->update([
            'failed_login_count' => 0,
            'lockout_until' => null,
        ]);

        // Issue session token
        [$token, $session] = $this->sessionTokenService->issue($user, $ipAddress, $userAgent);

        // Check if MFA is required
        $mfaRequired = $user->requiresMfa();

        $this->recordAttempt($username, $ipAddress, $mfaRequired ? 'mfa_required' : 'success', $captchaRequired, $userAgent);

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Audit log
        $this->auditService->log(
            'user',
            (string) $user->id,
            'login',
            $user->id,
            implode(',', $user->getRoles()),
            $ipAddress,
            null,
            null,
            ['mfa_required' => $mfaRequired]
        );

        return [
            'success' => true,
            'token' => $token,
            'user' => $user,
            'mfa_required' => $mfaRequired,
            'mfa_verified' => false,
            'error' => null,
            'captcha_required' => false,
        ];
    }

    /**
     * Logout - revoke current session.
     */
    public function logout(User $user, string $tokenId, string $ipAddress): void
    {
        $session = $user->sessions()
            ->where('token_id', $tokenId)
            ->whereNull('revoked_at')
            ->first();

        if ($session) {
            $session->revoke();
        }

        $this->auditService->log(
            'user',
            (string) $user->id,
            'logout',
            $user->id,
            implode(',', $user->getRoles()),
            $ipAddress
        );
    }

    private function handleFailedLogin(User $user, string $username, string $ipAddress, bool $captchaRequired, ?string $userAgent): void
    {
        $user->increment('failed_login_count');
        $user->refresh(); // Ensure in-memory count matches DB after increment

        $maxAttempts = config('auth.login.max_attempts', 5);
        $lockoutDuration = config('auth.login.lockout_duration_minutes', 30);

        if ($user->failed_login_count >= $maxAttempts) {
            $user->update([
                'lockout_until' => now()->addMinutes($lockoutDuration),
            ]);
        }

        $this->recordAttempt($username, $ipAddress, 'invalid_credentials', $captchaRequired, $userAgent);
    }

    private function recordAttempt(string $username, string $ipAddress, string $outcome, bool $captchaRequired, ?string $userAgent): void
    {
        LoginAttempt::create([
            'username' => $username,
            'attempted_at' => now(),
            'ip_address' => $ipAddress,
            'outcome' => $outcome,
            'captcha_required' => $captchaRequired,
            'user_agent' => $userAgent,
        ]);
    }
}
