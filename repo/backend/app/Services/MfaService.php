<?php

namespace App\Services;

use App\Models\User;
use App\Models\MfaSecret;
use App\Models\UserSession;
use Illuminate\Support\Str;

class MfaService
{
    private EncryptionService $encryption;
    private AuditService $audit;

    public function __construct(EncryptionService $encryption, AuditService $audit)
    {
        $this->encryption = $encryption;
        $this->audit = $audit;
    }

    /**
     * Generate a new TOTP secret for a user.
     * Returns the base32-encoded secret for QR code generation.
     */
    public function setupTotp(User $user): array
    {
        $secret = $this->generateBase32Secret();
        $issuer = config('auth.mfa.issuer', 'Admissions System');

        // Generate recovery codes
        $recoveryCodes = $this->generateRecoveryCodes(8);

        // Store encrypted secret
        MfaSecret::updateOrCreate(
            ['user_id' => $user->id],
            [
                'encrypted_totp_secret' => $this->encryption->encrypt($secret),
                'encrypted_recovery_codes' => array_map(
                    fn ($code) => $this->encryption->encrypt($code),
                    $recoveryCodes
                ),
                'recovery_codes_remaining' => count($recoveryCodes),
                'verified_at' => null,
            ]
        );

        // Build the otpauth URI for QR code generation
        $otpauthUri = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            urlencode($issuer),
            urlencode($user->username),
            $secret,
            urlencode($issuer)
        );

        return [
            'secret' => $secret,
            'otpauth_uri' => $otpauthUri,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    /**
     * Verify a TOTP code and activate MFA if this is initial setup.
     */
    public function verifyTotp(User $user, string $code): bool
    {
        $mfaSecret = $user->mfaSecret;
        if (!$mfaSecret) {
            return false;
        }

        $secret = $this->encryption->decrypt($mfaSecret->encrypted_totp_secret);
        $isValid = $this->verifyTotpCode($secret, $code);

        if ($isValid && !$mfaSecret->isVerified()) {
            // First successful verification — activate MFA
            $mfaSecret->update(['verified_at' => now()]);
            $user->update(['totp_enabled' => true]);

            $this->audit->log(
                'user', (string) $user->id, 'mfa_enabled',
                $user->id, null, request()->ip()
            );
        }

        return $isValid;
    }

    /**
     * Verify TOTP during login and mark session as MFA-verified.
     */
    public function verifyLoginTotp(User $user, UserSession $session, string $code): bool
    {
        $isValid = $this->verifyTotp($user, $code);

        if ($isValid) {
            $session->update(['mfa_verified' => true]);

            $this->audit->log(
                'user', (string) $user->id, 'mfa_verified',
                $user->id, null, request()->ip(),
                null, null, ['session_id' => $session->id]
            );
        }

        return $isValid;
    }

    /**
     * Use a recovery code.
     */
    public function useRecoveryCode(User $user, UserSession $session, string $recoveryCode): bool
    {
        $mfaSecret = $user->mfaSecret;
        if (!$mfaSecret || $mfaSecret->recovery_codes_remaining <= 0) {
            return false;
        }

        $encryptedCodes = $mfaSecret->encrypted_recovery_codes ?? [];
        $found = false;

        foreach ($encryptedCodes as $index => $encryptedCode) {
            try {
                $decrypted = $this->encryption->decrypt($encryptedCode);
                if (hash_equals($decrypted, $recoveryCode)) {
                    $found = true;
                    unset($encryptedCodes[$index]);
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (!$found) {
            return false;
        }

        $mfaSecret->update([
            'encrypted_recovery_codes' => array_values($encryptedCodes),
            'recovery_codes_remaining' => count($encryptedCodes),
        ]);

        $session->update(['mfa_verified' => true]);

        $this->audit->log(
            'user', (string) $user->id, 'mfa_recovery_code_used',
            $user->id, null, request()->ip(),
            null, null, ['codes_remaining' => count($encryptedCodes)]
        );

        return true;
    }

    /**
     * Disable MFA for a user (admin action).
     */
    public function disableMfa(User $user, int $actorId, string $ipAddress): void
    {
        $user->mfaSecret?->delete();
        $user->update(['totp_enabled' => false]);

        $this->audit->log(
            'user', (string) $user->id, 'mfa_disabled',
            $actorId, 'admin', $ipAddress,
            null, null, ['target_user' => $user->username]
        );
    }

    /**
     * Generate new recovery codes (admin action).
     */
    public function regenerateRecoveryCodes(User $user, int $actorId, string $ipAddress): array
    {
        $mfaSecret = $user->mfaSecret;
        if (!$mfaSecret) {
            throw new \RuntimeException('MFA is not set up for this user.');
        }

        $recoveryCodes = $this->generateRecoveryCodes(8);

        $mfaSecret->update([
            'encrypted_recovery_codes' => array_map(
                fn ($code) => $this->encryption->encrypt($code),
                $recoveryCodes
            ),
            'recovery_codes_remaining' => count($recoveryCodes),
        ]);

        $this->audit->log(
            'user', (string) $user->id, 'mfa_recovery_codes_regenerated',
            $actorId, 'admin', $ipAddress
        );

        return $recoveryCodes;
    }

    /**
     * Verify a TOTP code against a secret.
     * Allows +-1 time step window for clock drift tolerance.
     */
    private function verifyTotpCode(string $secret, string $code, int $window = 1): bool
    {
        $timestamp = time();
        $timeStep = 30;

        for ($i = -$window; $i <= $window; $i++) {
            $currentTimestamp = (int) floor(($timestamp + ($i * $timeStep)) / $timeStep);
            $expectedCode = $this->generateTotpCode($secret, $currentTimestamp);
            if (hash_equals($expectedCode, str_pad($code, 6, '0', STR_PAD_LEFT))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a TOTP code for a given timestamp counter.
     */
    private function generateTotpCode(string $base32Secret, int $counter): string
    {
        $secret = $this->base32Decode($base32Secret);
        $counterBytes = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    private function generateBase32Secret(int $length = 32): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    private function generateRecoveryCodes(int $count): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(4) . '-' . Str::random(4) . '-' . Str::random(4));
        }
        return $codes;
    }

    private function base32Decode(string $input): string
    {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper(rtrim($input, '='));
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $val = strpos($map, $input[$i]);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
