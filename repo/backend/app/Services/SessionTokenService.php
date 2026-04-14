<?php

namespace App\Services;

use App\Models\UserSession;
use App\Models\User;
use Illuminate\Support\Str;

class SessionTokenService
{
    private string $secret;
    private int $lifetimeMinutes;

    public function __construct()
    {
        $this->secret = config('auth.session.token_secret');
        $this->lifetimeMinutes = config('auth.session.lifetime_minutes', 120);
    }

    /**
     * Issue a new signed session token for a user.
     * Returns [token_plaintext, session_model].
     */
    public function issue(User $user, string $ipAddress, ?string $userAgent = null): array
    {
        $tokenId = Str::random(64);
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addMinutes($this->lifetimeMinutes);

        // Create the payload to sign
        $payload = $this->buildPayload($tokenId, $user->id, $issuedAt->timestamp, $expiresAt->timestamp);
        $signature = $this->sign($payload);
        $token = base64_encode(json_encode([
            'tid' => $tokenId,
            'uid' => $user->id,
            'iat' => $issuedAt->timestamp,
            'exp' => $expiresAt->timestamp,
            'sig' => $signature,
        ]));

        $session = UserSession::create([
            'user_id' => $user->id,
            'token_id' => $tokenId,
            'token_hash' => hash('sha256', $tokenId),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent ? Str::limit($userAgent, 500) : null,
            'mfa_verified' => false,
        ]);

        return [$token, $session];
    }

    /**
     * Verify and decode a session token.
     * Returns the Session model if valid, null otherwise.
     */
    public function verify(string $token): ?UserSession
    {
        try {
            $decoded = json_decode(base64_decode($token), true);
            if (!$decoded || !isset($decoded['tid'], $decoded['uid'], $decoded['iat'], $decoded['exp'], $decoded['sig'])) {
                return null;
            }

            // Verify signature
            $payload = $this->buildPayload($decoded['tid'], $decoded['uid'], $decoded['iat'], $decoded['exp']);
            $expectedSignature = $this->sign($payload);

            if (!hash_equals($expectedSignature, $decoded['sig'])) {
                return null;
            }

            // Check expiry from token itself
            if ($decoded['exp'] < time()) {
                return null;
            }

            // Find session in DB and validate
            $session = UserSession::where('token_id', $decoded['tid'])
                ->where('user_id', $decoded['uid'])
                ->first();

            if (!$session || !$session->isValid()) {
                return null;
            }

            return $session;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Refresh a session token (extends expiry).
     */
    public function refresh(UserSession $session, string $ipAddress, ?string $userAgent = null): array
    {
        // Revoke old session
        $session->revoke();

        // Issue new one
        return $this->issue($session->user, $ipAddress, $userAgent);
    }

    /**
     * Revoke all sessions for a user.
     */
    public function revokeAllForUser(int $userId): void
    {
        UserSession::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function buildPayload(string $tokenId, int $userId, int $issuedAt, int $expiresAt): string
    {
        return implode('|', [$tokenId, $userId, $issuedAt, $expiresAt]);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
