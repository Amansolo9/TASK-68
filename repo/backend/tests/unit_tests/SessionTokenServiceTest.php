<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\SessionTokenService;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\Hash;

class SessionTokenServiceTest extends TestCase
{
    private SessionTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SessionTokenService::class);
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'testuser_' . uniqid(),
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Test User',
            'status' => 'active',
        ], $overrides));
    }

    public function test_issue_creates_valid_token_and_session(): void
    {
        $user = $this->createUser();
        [$token, $session] = $this->service->issue($user, '127.0.0.1', 'TestAgent');

        $this->assertNotEmpty($token);
        $this->assertInstanceOf(UserSession::class, $session);
        $this->assertEquals($user->id, $session->user_id);
        $this->assertEquals('127.0.0.1', $session->ip_address);
        $this->assertFalse($session->mfa_verified);
        $this->assertTrue($session->isValid());
    }

    public function test_verify_returns_session_for_valid_token(): void
    {
        $user = $this->createUser();
        [$token, $originalSession] = $this->service->issue($user, '127.0.0.1');

        $verifiedSession = $this->service->verify($token);

        $this->assertNotNull($verifiedSession);
        $this->assertEquals($originalSession->id, $verifiedSession->id);
    }

    public function test_verify_returns_null_for_invalid_token(): void
    {
        $this->assertNull($this->service->verify('invalid-token'));
        $this->assertNull($this->service->verify(''));
    }

    public function test_verify_returns_null_for_tampered_token(): void
    {
        $user = $this->createUser();
        [$token, ] = $this->service->issue($user, '127.0.0.1');

        // Decode, corrupt the signature, re-encode
        $decoded = json_decode(base64_decode($token), true);
        $decoded['sig'] = str_repeat('0', 64); // Replace signature with zeros
        $tampered = base64_encode(json_encode($decoded));
        $this->assertNull($this->service->verify($tampered));
    }

    public function test_verify_returns_null_for_revoked_session(): void
    {
        $user = $this->createUser();
        [$token, $session] = $this->service->issue($user, '127.0.0.1');

        $session->revoke();

        $this->assertNull($this->service->verify($token));
    }

    public function test_revoke_all_for_user(): void
    {
        $user = $this->createUser();
        [$token1, ] = $this->service->issue($user, '127.0.0.1');
        [$token2, ] = $this->service->issue($user, '127.0.0.2');

        $this->service->revokeAllForUser($user->id);

        $this->assertNull($this->service->verify($token1));
        $this->assertNull($this->service->verify($token2));
    }

    public function test_refresh_revokes_old_and_issues_new(): void
    {
        $user = $this->createUser();
        [$oldToken, $oldSession] = $this->service->issue($user, '127.0.0.1');

        [$newToken, $newSession] = $this->service->refresh($oldSession, '127.0.0.1');

        $this->assertNotEquals($oldToken, $newToken);
        $this->assertNull($this->service->verify($oldToken));
        $this->assertNotNull($this->service->verify($newToken));
    }
}
