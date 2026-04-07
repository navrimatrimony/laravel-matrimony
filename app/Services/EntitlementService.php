<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UserEntitlement;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    /**
     * @var array<int, Plan|null> Resolved active plan (with features) per user for this request/instance.
     */
    private array $activePlanWithFeaturesByUser = [];

    /**
     * Assign entitlements when subscription starts
     */
    public function assignFromSubscription(Subscription $subscription): void
    {
        $plan = $subscription->plan()->with('features')->first();

        if (! $plan) {
            return;
        }

        $grace = (int) config('subscription.grace_days', 0);
        $validUntil = null;
        if ($subscription->ends_at !== null) {
            $validUntil = $subscription->ends_at->copy()->addDays($grace);
        }

        foreach ($plan->features as $feature) {
            UserEntitlement::updateOrCreate(
                [
                    'user_id' => $subscription->user_id,
                    'entitlement_key' => $feature->key,
                ],
                [
                    'valid_until' => $validUntil,
                    'value_override' => null,
                    'revoked_at' => null,
                ]
            );
        }
    }

    /**
     * Re-apply plan feature entitlements from the user's current active subscription (e.g. after admin extends ends_at).
     */
    public function resyncFromActiveSubscription(int $userId): void
    {
        $sub = Subscription::query()
            ->where('user_id', $userId)
            ->effectivelyActiveForAccess()
            ->orderByDesc('starts_at')
            ->first();

        if (! $sub) {
            return;
        }

        $this->assignFromSubscription($sub);
    }

    /**
     * Check if user has a non-revoked, non-expired entitlement row for the key.
     */
    public function hasAccess(int $userId, string $key): bool
    {
        return $this->validEntitlementsQuery($userId, $key)->exists();
    }

    /**
     * Feature value from the active subscription's plan (PlanFeature), gated by a valid entitlement row.
     * UserEntitlement does not store the value; it only authorizes reading the plan row.
     *
     * @return string|mixed|null
     */
    public function getValue(int $userId, string $key, mixed $default = null): mixed
    {
        $ent = $this->findValidEntitlement($userId, $key);
        if ($ent === null) {
            return $default;
        }

        $override = $ent->value_override ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $plan = $this->resolveActivePlanWithFeatures($userId);
        if (! $plan) {
            return $default;
        }

        $value = $plan->featureValue($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    /**
     * True when {@see getValue} resolves to a “truthy” feature flag (e.g. 1, yes, non-zero limits).
     */
    public function hasFeature(int $userId, string $key): bool
    {
        $raw = $this->getValue($userId, $key, null);
        if ($raw === null) {
            return false;
        }

        return $this->isTruthyFeatureValue((string) $raw);
    }

    /**
     * Revoke expired entitlements
     */
    public function revokeExpired(): void
    {
        $grace = (int) config('subscription.grace_days', 0);

        $query = UserEntitlement::query()
            ->whereNull('revoked_at')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now());

        if ($grace > 0) {
            $query->whereNotExists(function ($sub) use ($grace) {
                $sub->select(DB::raw(1))
                    ->from('subscriptions')
                    ->whereColumn('subscriptions.user_id', 'user_entitlements.user_id')
                    ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
                    ->whereNotNull('subscriptions.ends_at')
                    ->where('subscriptions.ends_at', '<=', now())
                    ->where('subscriptions.ends_at', '>', now()->subDays($grace));
            });
        }

        $query->update([
            'revoked_at' => now(),
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<UserEntitlement>
     */
    private function validEntitlementsQuery(int $userId, string $key)
    {
        $grace = (int) config('subscription.grace_days', 0);

        return UserEntitlement::query()
            ->where('user_id', $userId)
            ->where('entitlement_key', $key)
            ->whereNull('revoked_at')
            ->where(function ($q) use ($grace) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>', now());

                if ($grace > 0) {
                    $q->orWhereExists(function ($sub) use ($grace) {
                        $sub->select(DB::raw(1))
                            ->from('subscriptions')
                            ->whereColumn('subscriptions.user_id', 'user_entitlements.user_id')
                            ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
                            ->whereNotNull('subscriptions.ends_at')
                            ->where('subscriptions.ends_at', '<=', now())
                            ->where('subscriptions.ends_at', '>', now()->subDays($grace));
                    });
                }
            });
    }

    private function findValidEntitlement(int $userId, string $key): ?UserEntitlement
    {
        return $this->validEntitlementsQuery($userId, $key)->first();
    }

    private function resolveActivePlanWithFeatures(int $userId): ?Plan
    {
        if (array_key_exists($userId, $this->activePlanWithFeaturesByUser)) {
            return $this->activePlanWithFeaturesByUser[$userId];
        }

        $sub = Subscription::query()
            ->where('user_id', $userId)
            ->effectivelyActiveForAccess()
            ->orderByDesc('starts_at')
            ->with(['plan.features'])
            ->first();

        $plan = $sub?->plan;
        if ($plan) {
            $plan->loadMissing('features');
        }

        $this->activePlanWithFeaturesByUser[$userId] = $plan;

        return $plan;
    }

    private function isTruthyFeatureValue(string $value): bool
    {
        $s = strtolower(trim($value));

        return match (true) {
            $s === '',
            $s === '0',
            $s === 'false',
            $s === 'no',
            $s === 'off' => false,
            default => true,
        };
    }
}
