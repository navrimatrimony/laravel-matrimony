<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: Route::middleware('feature:showcase_profiles_enabled')->group(...)
 *
 * Generic — any feature_flags.key works. Disabled → 404 (or JSON Feature Disabled).
 */
class EnsureFeatureEnabled
{
    public function __construct(
        private readonly FeatureFlagService $featureFlags,
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        if (! $this->featureFlags->isEnabled($featureKey)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Feature Disabled',
                ], 404);
            }

            abort(404, 'Feature Disabled');
        }

        return $next($request);
    }
}
