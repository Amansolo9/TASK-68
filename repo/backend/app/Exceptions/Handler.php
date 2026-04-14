<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'password',
        'password_confirmation',
        'totp_secret',
    ];

    public function render($request, Throwable $e)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->renderApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    protected function renderApiException($request, Throwable $e)
    {
        $correlationId = $request->header('X-Correlation-ID', (string) \Illuminate\Support\Str::uuid());

        if ($e instanceof ValidationException) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => $correlationId],
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'The given data was invalid.',
                    'details' => $e->errors(),
                ],
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => $correlationId],
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                    'details' => [],
                ],
            ], 401);
        }

        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => $correlationId],
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Resource not found.',
                    'details' => [],
                ],
            ], 404);
        }

        if ($e instanceof HttpException) {
            return response()->json([
                'data' => null,
                'meta' => ['correlation_id' => $correlationId],
                'error' => [
                    'code' => 'HTTP_ERROR',
                    'message' => $e->getMessage() ?: 'An error occurred.',
                    'details' => [],
                ],
            ], $e->getStatusCode());
        }

        $status = 500;
        $message = config('app.debug') ? $e->getMessage() : 'Internal server error.';

        return response()->json([
            'data' => null,
            'meta' => ['correlation_id' => $correlationId],
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => $message,
                'details' => config('app.debug') ? ['trace' => $e->getTraceAsString()] : [],
            ],
        ], $status);
    }
}
