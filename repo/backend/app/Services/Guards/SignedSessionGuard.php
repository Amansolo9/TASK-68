<?php

namespace App\Services\Guards;

use App\Models\User;
use App\Models\UserSession;
use App\Services\SessionTokenService;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class SignedSessionGuard implements Guard
{
    use GuardHelpers;

    protected string $name;
    protected Request $request;
    protected ?UserSession $currentSession = null;
    protected ?string $resolvedToken = null;

    public function __construct(string $name, UserProvider $provider, Request $request)
    {
        $this->name = $name;
        $this->provider = $provider;
        $this->request = $request;
    }

    public function user(): ?User
    {
        // Resolve the current request fresh each time to support
        // multiple requests in test suites and request lifecycle changes.
        $request = app('request');
        $token = $this->getTokenFrom($request);

        if (!$token) {
            $this->user = null;
            $this->currentSession = null;
            return null;
        }

        // Return cached user if token hasn't changed
        if ($this->user !== null && $this->currentSession && $this->resolvedToken === $token) {
            return $this->user;
        }

        $tokenService = app(SessionTokenService::class);
        $session = $tokenService->verify($token);

        if (!$session) {
            $this->user = null;
            $this->currentSession = null;
            return null;
        }

        $this->currentSession = $session;
        $this->resolvedToken = $token;
        $user = $this->provider->retrieveById($session->user_id);

        if ($user && $user->isActive()) {
            $this->user = $user;
            return $this->user;
        }

        $this->user = null;
        return null;
    }

    public function validate(array $credentials = []): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);
        return $user && $this->provider->validateCredentials($user, $credentials);
    }

    public function currentSession(): ?UserSession
    {
        // Ensure user() has been called to populate session
        $this->user();
        return $this->currentSession;
    }

    public function isMfaVerified(): bool
    {
        $session = $this->currentSession();
        return $session && $session->mfa_verified;
    }

    protected function getTokenFrom(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $request->query('_token');
    }

    protected function getTokenFromRequest(): ?string
    {
        $header = $this->request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Fallback to query parameter (not recommended but supported)
        return $this->request->query('_token');
    }
}
