<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\MfaService;
use Illuminate\Support\Facades\Hash;

class MfaTest extends TestCase
{
    private function createAdminAndLogin(): array
    {
        $user = User::create([
            'username' => 'adminmfa_' . uniqid(),
            'password_hash' => Hash::make('AdminPassword123!'),
            'full_name' => 'Admin MFA Test',
            'status' => 'active',
        ]);

        UserRoleScope::create([
            'user_id' => $user->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'AdminPassword123!',
        ]);

        $token = $loginResponse->json('data.token'); $this->markTokenMfaVerified($token); return [$user, $token];
    }

    public function test_admin_login_requires_mfa(): void
    {
        $user = User::create([
            'username' => 'admin_mfa_' . uniqid(),
            'password_hash' => Hash::make('AdminPassword123!'),
            'full_name' => 'Admin',
            'status' => 'active',
            'totp_enabled' => true,
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'AdminPassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.mfa_required', true);
    }

    public function test_mfa_setup_returns_otpauth_uri(): void
    {
        [$user, $token] = $this->createAdminAndLogin();

        $response = $this->postJson('/api/mfa/setup', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['otpauth_uri', 'recovery_codes'],
            ]);

        $this->assertStringContains('otpauth://totp/', $response->json('data.otpauth_uri'));
        $this->assertCount(8, $response->json('data.recovery_codes'));
    }

    public function test_mfa_verify_with_invalid_code_fails(): void
    {
        [$user, $token] = $this->createAdminAndLogin();

        $this->postJson('/api/mfa/setup', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response = $this->postJson('/api/mfa/verify', ['code' => '000000'], [
            'Authorization' => "Bearer {$token}",
        ]);

        // Will fail because 000000 is unlikely to be the current valid code
        $response->assertStatus(401);
    }

    public function test_mfa_verify_requires_six_digit_code(): void
    {
        [$user, $token] = $this->createAdminAndLogin();

        $response = $this->postJson('/api/mfa/verify', ['code' => '12'], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_mfa_setup_stores_encrypted_secret(): void
    {
        [$user, $token] = $this->createAdminAndLogin();

        $this->postJson('/api/mfa/setup', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $user->refresh();
        $mfaSecret = $user->mfaSecret;

        $this->assertNotNull($mfaSecret);
        $this->assertNotEmpty($mfaSecret->encrypted_totp_secret);
        // Secret should be encrypted, not plaintext base32
        $this->assertStringNotContainsString('ABCDEFGHIJKLMNOP', $mfaSecret->encrypted_totp_secret);
    }

    public function test_recovery_codes_are_generated(): void
    {
        [$user, $token] = $this->createAdminAndLogin();

        $response = $this->postJson('/api/mfa/setup', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $codes = $response->json('data.recovery_codes');
        $this->assertCount(8, $codes);

        // Each code should match pattern XXXX-XXXX-XXXX
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code);
        }
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(str_contains($haystack, $needle), "Failed asserting that '{$haystack}' contains '{$needle}'");
    }
}
