<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'API is healthy.',
            'application_name' => config('app.name'),
            'environment' => app()->environment(),
        ]);
    }
}
