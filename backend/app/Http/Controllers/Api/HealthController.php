<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $result = $checker->run();

        return response()->json([
            'service' => config('app.name'),
            'environment' => app()->environment(),
            'time' => now()->toIso8601String(),
        ] + $result, $result['status'] === 'ok' ? 200 : 503);
    }
}
