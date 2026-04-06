<?php

namespace App\Http\Middleware;

use App\Services\FeatureUsageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Usage: Route::middleware(['auth', 'subscription.feature:contact_number'])->group(...)
 * Aliases: contact_number, see_contact, chat, interest, profile_views, chat_images
 */
class EnsureSubscriptionFeature
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if (! app(FeatureUsageService::class)->subscriptionFeatureAllows($user, $feature)) {
            throw new HttpException(403, __('subscriptions.feature_locked'));
        }

        return $next($request);
    }
}
