<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Public Suchak APK branding/config (theme, homepage photo, logos, taglines).
 */
class SuchakAppConfigApiController extends Controller
{
    public function __invoke(SuchakPolicyService $policyService): JsonResponse
    {
        $config = $policyService->apkConfig();

        return response()->json([
            'success' => true,
            'data' => [
                'theme_color' => $config['theme_color'],
                'tagline' => $config['tagline'],
                'homepage_photo_url' => $this->publicUrl($config['homepage_photo_path'] ?? null),
                'logo_light_url' => $this->publicUrl($config['logo_light_path'] ?? null),
                'logo_dark_url' => $this->publicUrl($config['logo_dark_path'] ?? null),
                'asset_specs' => [
                    'homepage_photo' => [
                        'ratio' => '9:16',
                        'recommended_px' => '1080x1920',
                        'formats' => ['jpg', 'jpeg', 'png', 'webp'],
                        'max_kb' => 2048,
                    ],
                    'logo' => [
                        'ratio' => '1:1',
                        'recommended_px' => '512x512',
                        'formats' => ['png'],
                        'max_kb' => 500,
                        'transparent' => true,
                    ],
                ],
            ],
        ]);
    }

    private function publicUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
