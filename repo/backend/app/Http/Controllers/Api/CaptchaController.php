<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CaptchaService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaptchaController extends Controller
{
    use HasApiResponse;

    public function __construct(private CaptchaService $captchaService) {}

    public function generate(Request $request): JsonResponse
    {
        $challenge = $this->captchaService->generate();

        return $this->success([
            'challenge_key' => $challenge['challenge_key'],
            'question' => $challenge['question'],
        ]);
    }

    public function image(string $key)
    {
        $imageData = $this->captchaService->generateImage($key);

        if (!$imageData) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => request()->header('X-Correlation-ID', '')],
                'error' => [
                    'code' => 'CAPTCHA_NOT_FOUND',
                    'message' => 'CAPTCHA challenge not found or expired.',
                    'details' => [],
                ],
            ], 404);
        }

        return response($imageData, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
