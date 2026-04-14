<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait HasApiResponse
{
    protected function success($data = null, array $meta = [], int $status = 200): JsonResponse
    {
        $response = [
            'data' => $data,
            'meta' => array_merge([
                'correlation_id' => request()->header('X-Correlation-ID', ''),
            ], $meta),
            'error' => null,
        ];

        return response()->json($response, $status);
    }

    protected function error(string $code, string $message, array $details = [], int $status = 400, array $meta = []): JsonResponse
    {
        $response = [
            'data' => null,
            'meta' => array_merge([
                'correlation_id' => request()->header('X-Correlation-ID', ''),
            ], $meta),
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ];

        return response()->json($response, $status);
    }

    protected function paginated($paginator, array $meta = []): JsonResponse
    {
        return $this->success($paginator->items(), array_merge($meta, [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]));
    }
}
