<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UserEntitlement;

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

        foreach ($plan->features as $feature) {
            UserEntitlement::updateOrCreate(
                [
                    'user_id' => $subscription->user_id,
                    'entitlement_key' => $feature->key,
                ],
                [
                    'valid_until' => $subscription->ends_at,
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
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
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
        if ($this->findValidEntitlement($userId, $key) === null) {
            return $default;
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
        UserEntitlement::whereNull('revoked_at')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now())
            ->update([
                'revoked_at' => now(),
            ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<UserEntitlement>
     */
    private function validEntitlementsQuery(int $userId, string $key)
    {
        return UserEntitlement::query()
            ->where('user_id', $userId)
            ->where('entitlement_key', $key)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
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
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
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
