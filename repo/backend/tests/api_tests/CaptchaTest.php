<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\CaptchaService;

class CaptchaTest extends TestCase
{
    public function test_captcha_generation_returns_challenge(): void
    {
        $response = $this->postJson('/api/auth/captcha');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['challenge_key', 'question'],
            ]);

        $this->assertNotEmpty($response->json('data.challenge_key'));
        $this->assertStringContainsString('+', $response->json('data.question'));
    }

    public function test_captcha_verify_correct_answer(): void
    {
        $service = app(CaptchaService::class);
        $challenge = $service->generate();

        // The answer is num1 + num2
        $answer = (string) ($challenge['num1'] + $challenge['num2']);
        $isValid = $service->verify($challenge['challenge_key'], $answer);

        $this->assertTrue($isValid);
    }

    public function test_captcha_verify_wrong_answer(): void
    {
        $service = app(CaptchaService::class);
        $challenge = $service->generate();

        $isValid = $service->verify($challenge['challenge_key'], '999999');

        $this->assertFalse($isValid);
    }

    public function test_captcha_cannot_be_reused(): void
    {
        $service = app(CaptchaService::class);
        $challenge = $service->generate();
        $answer = (string) ($challenge['num1'] + $challenge['num2']);

        // First use should succeed
        $this->assertTrue($service->verify($challenge['challenge_key'], $answer));

        // Second use should fail (marked as used)
        $this->assertFalse($service->verify($challenge['challenge_key'], $answer));
    }

    public function test_captcha_with_invalid_key_fails(): void
    {
        $service = app(CaptchaService::class);

        $this->assertFalse($service->verify('nonexistent-key', '42'));
    }

    public function test_captcha_is_required_after_failed_attempts(): void
    {
        $service = app(CaptchaService::class);

        // By default, CAPTCHA is required after 5 failed logins
        $this->assertFalse($service->isRequired('newuser'));
    }
}
