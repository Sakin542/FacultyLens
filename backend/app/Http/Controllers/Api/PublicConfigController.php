<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\FeatureFlagService;
use Illuminate\Http\JsonResponse;

/** Anonymous-safe client configuration: feature toggles and the public Google client id (never secrets). */
class PublicConfigController extends Controller
{
    public function __invoke(FeatureFlagService $features): JsonResponse
    {
        $googleClientId = trim((string) config('admin.google.client_id'));

        return response()->json([
            'status' => 'success',
            'data' => [
                'features' => $features->publicValues(),
                'google_client_id' => $googleClientId !== '' && $features->enabled('google_sign_in') ? $googleClientId : null,
            ],
        ]);
    }
}
