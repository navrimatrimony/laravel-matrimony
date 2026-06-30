<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\MobilePlanApiController;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class MobilePlanCheckoutBridgeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $nonce = trim((string) $request->query('nonce', ''));
        if ($nonce === '' || ! preg_match('/^[A-Za-z0-9]{48}$/', $nonce)) {
            abort(403);
        }

        $payload = Cache::pull(MobilePlanApiController::CHECKOUT_BRIDGE_CACHE_PREFIX.$nonce);
        if (! is_array($payload)) {
            abort(403);
        }

        $user = User::query()->find((int) ($payload['user_id'] ?? 0));
        $plan = Plan::query()->with('terms')->find((int) ($payload['plan_id'] ?? 0));
        if (! $user instanceof User || ! $plan instanceof Plan) {
            abort(403);
        }

        if (! $this->isBuyablePlanForUser($user, $plan)) {
            abort(403);
        }

        $planTermId = isset($payload['plan_term_id']) ? (int) $payload['plan_term_id'] : null;
        if ($planTermId !== null
            && ! $plan->terms->contains(fn ($term): bool => (int) $term->id === $planTermId && (bool) $term->is_visible)
        ) {
            abort(403);
        }

        Auth::guard('web')->loginUsingId((int) $user->id, false);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $params = ['plan' => (string) $plan->slug];
        if ($planTermId !== null) {
            $params['plan_term_id'] = (string) $planTermId;
        }

        return redirect()->route('plans.subscribe', $params);
    }

    private function isBuyablePlanForUser(User $user, Plan $plan): bool
    {
        return (bool) $plan->is_active
            && (bool) $plan->is_visible
            && ! Plan::isFreeCatalogSlug((string) $plan->slug)
            && Plan::profileGenderAllowsPlan($user, $plan);
    }
}
