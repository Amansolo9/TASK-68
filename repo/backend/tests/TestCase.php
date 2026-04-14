<?php

namespace Tests;

use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Create a user with the given role and return [User, token].
     * The session is marked as MFA-verified so protected routes work in tests.
     */
    protected function authenticateAs(string $role, ?string $dept = null, array $userOverrides = []): array
    {
        $user = User::create(array_merge([
            'username' => "{$role}_" . uniqid(),
            'password_hash' => Hash::make('TestPass123!'),
            'full_name' => ucfirst($role) . ' Test User',
            'status' => 'active',
        ], $userOverrides));

        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => $role,
            'department_scope' => $dept,
            'is_active' => true,
        ]);

        $token = $this->loginAndVerifyMfa($user->username, 'TestPass123!');

        return [$user, $token];
    }

    /**
     * Login and mark the resulting session as MFA-verified.
     * Can be called by any test that has its own user creation logic.
     */
    protected function loginAndVerifyMfa(string $username, string $password): ?string
    {
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);

        $token = $loginResponse->json('data.token');

        if ($token) {
            $this->markTokenMfaVerified($token);
        }

        return $token;
    }

    /**
     * Mark a Bearer token's session as MFA-verified in the database.
     */
    protected function markTokenMfaVerified(string $token): void
    {
        $decoded = json_decode(base64_decode($token), true);
        if ($decoded && isset($decoded['tid'])) {
            UserSession::where('token_id', $decoded['tid'])->update(['mfa_verified' => true]);
        }
    }
}
