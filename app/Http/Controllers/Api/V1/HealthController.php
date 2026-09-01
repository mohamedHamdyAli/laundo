<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Smoke endpoints that prove the API stack end to end.
 *
 * These carry no business logic. They exist so the transport, the response
 * envelope, the locale resolution and the token guard can each be verified
 * independently of any feature.
 */
class HealthController extends Controller
{
    /**
     * Public liveness check.
     */
    public function ping(): JsonResponse
    {
        return successReturnData([
            'status' => 'ok',
            'time' => now()->toIso8601String(),
            'locale' => app()->getLocale(),
            'timezone' => config('app.timezone'),
        ]);
    }

    /**
     * The authenticated token owner. Proves the sanctum guard resolves.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return successReturnData([
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role?->slug,
            'status' => $user->status,
        ]);
    }
}
