<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MfaService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MfaController extends Controller
{
    use HasApiResponse;

    public function __construct(private MfaService $mfaService) {}

    public function setup(Request $request): JsonResponse
    {
        $user = Auth::user();
        $result = $this->mfaService->setupTotp($user);

        return $this->success([
            'otpauth_uri' => $result['otpauth_uri'],
            'recovery_codes' => $result['recovery_codes'],
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = Auth::user();
        $isValid = $this->mfaService->verifyTotp($user, $request->input('code'));

        if (!$isValid) {
            return $this->error('INVALID_TOTP', 'Invalid TOTP code.', [], 401);
        }

        return $this->success(['verified' => true, 'totp_enabled' => $user->fresh()->totp_enabled]);
    }

    public function verifyLogin(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = Auth::user();
        $guard = Auth::guard();
        $session = method_exists($guard, 'currentSession') ? $guard->currentSession() : null;

        if (!$session) {
            return $this->error('INVALID_SESSION', 'No active session.', [], 401);
        }

        $isValid = $this->mfaService->verifyLoginTotp($user, $session, $request->input('code'));

        if (!$isValid) {
            return $this->error('INVALID_TOTP', 'Invalid TOTP code.', [], 401);
        }

        return $this->success([
            'mfa_verified' => true,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'roles' => $user->getRoles(),
            ],
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $targetUser = \App\Models\User::findOrFail($request->input('user_id'));
        $this->mfaService->disableMfa($targetUser, Auth::id(), $request->ip());

        return $this->success(['message' => 'MFA disabled for user.']);
    }

    public function generateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $targetUser = \App\Models\User::findOrFail($request->input('user_id'));
        $codes = $this->mfaService->regenerateRecoveryCodes($targetUser, Auth::id(), $request->ip());

        return $this->success(['recovery_codes' => $codes]);
    }

    public function useRecoveryCode(Request $request): JsonResponse
    {
        $request->validate([
            'recovery_code' => 'required|string',
        ]);

        $user = Auth::user();
        $guard = Auth::guard();
        $session = method_exists($guard, 'currentSession') ? $guard->currentSession() : null;

        if (!$session) {
            return $this->error('INVALID_SESSION', 'No active session.', [], 401);
        }

        $isValid = $this->mfaService->useRecoveryCode($user, $session, $request->input('recovery_code'));

        if (!$isValid) {
            return $this->error('INVALID_RECOVERY_CODE', 'Invalid recovery code.', [], 401);
        }

        return $this->success(['mfa_verified' => true]);
    }
}
