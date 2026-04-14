<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    use HasApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $roles = $user->getRoles();

        $dashboardData = [
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'roles' => $roles,
            ],
            'system_status' => 'operational',
        ];

        return $this->success($dashboardData, [
            'poll_after_ms' => (int) config('app.poll_interval_ms', 10000),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        // Polling endpoint for near-real-time updates
        $user = Auth::user();

        $updates = [
            'timestamp' => now()->toIso8601String(),
            'has_updates' => false,
        ];

        return $this->success($updates, [
            'poll_after_ms' => (int) config('app.poll_interval_ms', 10000),
        ]);
    }
}
