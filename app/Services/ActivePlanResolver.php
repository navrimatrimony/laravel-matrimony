<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for member "current active plan" and active subscription resolution.
 */
class ActivePlanResolver
{
    /**
     * Authoritative active subscription for access (paid window + grace), ordered by latest starts_at.
     */
    public function getActiveSubscription(User $user, ?CarbonInterface $at = null): ?Subscription
    {
        $this->enforceSingleActiveRowInvariantByUserId((int) $user->id, 'resolver_read');

        $moment = $at ?? now();
        $sub = Subscription::queryAuthoritativeAccessForUser($user, $moment)->first();

        if ($sub !== null) {
            $sub->loadMissing('plan');
            if ($sub->plan === null) {
                Log::critical('subscription_plan_relation_missing', [
                    'user_id' => (int) $user->id,
                    'subscription_id' => (int) $sub->id,
                    'plan_id' => (int) $sub->plan_id,
                ]);
            }
        }

        return $sub;
    }

    /**
     * Deterministic active plan for member feature checks and UI display.
     */
    public function get(User $user, ?CarbonInterface $at = null): Plan
    {
        $sub = $this->getActiveSubscription($user, $at);
        if ($sub !== null && $sub->plan !== null) {
            return $sub->plan->loadMissing(['features', 'quotaPolicies']);
        }

        return $this->defaultFreePlan($user);
    }

    /**
     * Best-effort plan label for UI; never throws.
     */
    public function getPlanName(User $user, ?CarbonInterface $at = null): string
    {
        try {
            $sub = $this->getActiveSubscription($user, $at);
            if ($sub !== null) {
                $snap = $sub->checkoutSnapshot();
                $snapName = trim((string) ($snap['plan_name'] ?? ''));
                if ($snapName !== '') {
                    return $snapName;
                }
                $sub->loadMissing('plan');
                $name = trim((string) ($sub->plan?->name ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }

            return trim((string) $this->defaultFreePlan($user)->name);
        } catch (\Throwable $e) {
            Log::critical('active_plan_name_resolution_failed', [
                'user_id' => (int) $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Hard invariant guard: at most one `status=active` row per user.
     * Auto-heals by keeping latest (starts_at desc, id desc), cancels others, and emits CRITICAL.
     */
    public function enforceSingleActiveRowInvariantByUserId(int $userId, string $context = 'unknown'): void
    {
        if ($userId <= 0) {
            return;
        }

        $activeIds = Subscription::query()
            ->where('user_id', $userId)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($activeIds->count() <= 1) {
            return;
        }

        $keepId = (int) $activeIds->first();
        $cancelIds = $activeIds->slice(1)->all();

        Subscription::query()
            ->whereIn('id', $cancelIds)
            ->update([
                'status' => Subscription::STATUS_CANCELLED,
                'updated_at' => now(),
            ]);

        Log::critical('subscription_active_row_violation_autofixed', [
            'user_id' => $userId,
            'context' => $context,
            'kept_subscription_id' => $keepId,
            'cancelled_subscription_ids' => $cancelIds,
        ]);
    }

    private function defaultFreePlan(?User $user = null): Plan
    {
        $p = Plan::defaultFree($user);
        if ($p) {
            return $p->loadMissing(['features', 'quotaPolicies']);
        }

        $any = Plan::query()->where('is_active', true)->orderBy('sort_order')->first();
        if ($any) {
            return $any->loadMissing(['features', 'quotaPolicies']);
        }

        $plan = new Plan([
            'name' => 'Free',
            'slug' => 'free',
            'price' => 0,
            'discount_percent' => null,
            'duration_days' => 0,
            'is_active' => true,
            'sort_order' => 0,
            'highlight' => false,
        ]);
        $plan->setRelation('features', collect());
        $plan->setRelation('quotaPolicies', collect());

        return $plan;
    }
}

