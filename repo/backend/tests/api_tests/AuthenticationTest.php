<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Support\Facades\Hash;

class AuthenticationTest extends TestCase
{
    private function createActiveUser(string $role = 'admin', array $userOverrides = []): User
    {
        $user = User::create(array_merge([
            'username' => 'testuser_' . uniqid(),
            'password_hash' => Hash::make('ValidPassword123!'),
            'full_name' => 'Test User',
            'status' => 'active',
        ], $userOverrides));

        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => $role,
            'is_active' => true,
        ]);

        return $user;
    }

    public function test_login_with_valid_credentials(): void
    {
        $user = $this->createActiveUser('advisor');

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'ValidPassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['token', 'mfa_required', 'user' => ['id', 'username', 'full_name', 'roles']],
                'meta' => ['correlation_id'],
                'error',
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNull($response->json('error'));
    }

    public function test_login_with_invalid_credentials(): void
    {
        $this->createActiveUser();

        $response = $this->postJson('/api/auth/login', [
            'username' => 'nonexistent',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_with_inactive_user(): void
    {
        $user = $this->createActiveUser('advisor', ['status' => 'inactive']);

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'ValidPassword123!',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_increments_failed_count(): void
    {
        $user = $this->createActiveUser();

        $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'wrongpassword',
        ]);

        $user->refresh();
        $this->assertEquals(1, $user->failed_login_count);
    }

    public function test_successful_login_resets_failed_count(): void
    {
        $user = $this->createActiveUser('advisor');
        $user->update(['failed_login_count' => 3]);

        $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'ValidPassword123!',
        ]);

        $user->refresh();
        $this->assertEquals(0, $user->failed_login_count);
    }

    public function test_lockout_after_max_failed_attempts(): void
    {
        $user = $this->createActiveUser('advisor');

        // Simulate max failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'username' => $user->username,
                'password' => 'wrongpassword',
            ]);
        }

        // After 5 failures user should be locked out
        $user->refresh();
        $this->assertNotNull($user->lockout_until);

        // Next attempt (even with correct password) should be blocked
        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'ValidPassword123!',
        ]);
        // Locked user triggers CAPTCHA_REQUIRED (403) since captcha_required is set
        $response->assertStatus(403);
    }

    public function test_authenticated_session_endpoint(): void
    {
        $user = $this->createActiveUser('advisor');

        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'ValidPassword123!',
        ]);

        $token = $loginResponse->json('data.token');

        $response = $this->getJson('/api/auth/session', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.username', $user->username);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/auth/session');
        $response->assertStatus(401);
    }

    public function test_logout_invalidates_session(): void
    {
        $user = $this->createActiveUser('advisor');

        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'ValidPassword123!',
        ]);

        $token = $loginResponse->json('data.token');

        $this->postJson('/api/auth/logout', [], [
            'Authorization' => "Bearer {$token}",
        ])->assertStatus(200);

        // Session should be revoked in database
        $decoded = json_decode(base64_decode($token), true);
        $session = \App\Models\UserSession::where('token_id', $decoded['tid'])->first();
        $this->assertNotNull($session->revoked_at, 'Session should be revoked after logout');
    }

    public function test_login_requires_username_and_password(): void
    {
        $response = $this->postJson('/api/auth/login', []);
        $response->assertStatus(422);
    }

    public function test_login_records_attempt_in_log(): void
    {
        $this->createActiveUser('advisor', ['username' => 'logtest']);

        $this->postJson('/api/auth/login', [
            'username' => 'logtest',
            'password' => 'wrongpassword',
        ]);

        $this->assertDatabaseHas('login_attempts', [
            'username' => 'logtest',
            'outcome' => 'invalid_credentials',
        ]);
    }
}
