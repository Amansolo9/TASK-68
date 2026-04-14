<?php

namespace App\Services;

use App\Models\CaptchaChallenge;
use Illuminate\Support\Str;

/**
 * Offline CAPTCHA service using GD-generated math/text challenges.
 * No external service dependency.
 */
class CaptchaService
{
    /**
     * Generate a new CAPTCHA challenge.
     * Returns [challenge_key, answer] where answer is the solution.
     */
    public function generate(): array
    {
        $num1 = random_int(1, 20);
        $num2 = random_int(1, 20);
        $answer = (string) ($num1 + $num2);
        $challengeKey = Str::random(64);

        CaptchaChallenge::create([
            'challenge_key' => $challengeKey,
            'answer_hash' => password_hash($answer, PASSWORD_BCRYPT),
            'expires_at' => now()->addMinutes(config('security.captcha.expiry_minutes', 5)),
            'created_at' => now(),
        ]);

        return [
            'challenge_key' => $challengeKey,
            'question' => "What is {$num1} + {$num2}?",
            'num1' => $num1,
            'num2' => $num2,
        ];
    }

    /**
     * Generate a CAPTCHA image (PNG binary).
     * Uses GD library - works fully offline.
     */
    public function generateImage(string $challengeKey): ?string
    {
        $challenge = CaptchaChallenge::where('challenge_key', $challengeKey)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$challenge) {
            return null;
        }

        $width = config('security.captcha.width', 200);
        $height = config('security.captcha.height', 60);

        // Generate a simple math challenge image using GD
        $image = imagecreatetruecolor($width, $height);
        $bgColor = imagecolorallocate($image, 255, 255, 255);
        $textColor = imagecolorallocate($image, 0, 0, 0);
        $noiseColor = imagecolorallocate($image, 150, 150, 150);

        imagefill($image, 0, 0, $bgColor);

        // Add noise lines
        for ($i = 0; $i < 5; $i++) {
            imageline(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $noiseColor
            );
        }

        // We display a generic "Enter the answer" text - actual question is shown via API
        $text = "Solve the math";
        imagestring($image, 5, 30, 20, $text, $textColor);

        ob_start();
        imagepng($image);
        $imageData = ob_get_clean();
        imagedestroy($image);

        return $imageData;
    }

    /**
     * Verify a CAPTCHA answer.
     */
    public function verify(string $challengeKey, string $answer): bool
    {
        $challenge = CaptchaChallenge::where('challenge_key', $challengeKey)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$challenge) {
            return false;
        }

        $isValid = password_verify($answer, $challenge->answer_hash);

        // Mark as used regardless of outcome to prevent brute-force
        $challenge->update(['used' => true]);

        return $isValid;
    }

    /**
     * Check if CAPTCHA is required for a given username based on failed attempts.
     */
    public function isRequired(string $username): bool
    {
        if (!config('security.captcha.enabled', true)) {
            return false;
        }

        $maxAttempts = config('auth.login.max_attempts', 5);
        $windowMinutes = config('auth.login.lockout_window_minutes', 15);

        $recentFailures = \App\Models\LoginAttempt::where('username', $username)
            ->where('attempted_at', '>=', now()->subMinutes($windowMinutes))
            ->whereIn('outcome', ['invalid_credentials', 'mfa_failed', 'captcha_failed'])
            ->count();

        return $recentFailures >= $maxAttempts;
    }

    /**
     * Clean up expired challenges.
     */
    public function cleanupExpired(): int
    {
        return CaptchaChallenge::where('expires_at', '<', now())->delete();
    }
}
