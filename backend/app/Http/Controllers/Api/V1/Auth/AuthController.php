<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Actions\AuthenticateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticateUser $authenticate): UserResource
    {
        $user = $authenticate->handle($request);

        return (new UserResource($user->load(['roles', 'unit', 'department'])))->withPermissions();
    }

    public function me(Request $request): UserResource
    {
        return (new UserResource($request->user()->load(['roles', 'unit', 'department'])))->withPermissions();
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $audit->log('auth.logout', $user, 'Logout realizado.');

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
