<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakPlanFeature;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class SuchakEntitlementService
{
    public function __construct(
        private readonly SuchakPaymentStatusService $paymentStatusService,
    ) {
    }

    /**
     * @return array<string, int|bool|string|null>
     */
    public function currentFeatureLimits(SuchakAccount $account, ?CarbonInterface $at = null): array
    {
        $subscription = $this->paymentStatusService->activeSubscriptionFor($account, $at);
        if ($subscription === null || $subscription->suchakPlan === null) {
            return [];
        }

        $limits = [];
        foreach ($subscription->suchakPlan->enabledFeatures as $feature) {
            if (! in_array($feature->feature_key, SuchakPlanFeature::FEATURE_KEYS, true)) {
                continue;
            }

            $limits[$feature->feature_key] = $feature->typedValue();
        }

        return $limits;
    }

    public function currentFeatureValue(
        SuchakAccount $account,
        string $featureKey,
        mixed $default = null,
        ?CarbonInterface $at = null,
    ): mixed {
        $this->assertFeatureKey($featureKey);

        $limits = $this->currentFeatureLimits($account, $at);

        return array_key_exists($featureKey, $limits) ? $limits[$featureKey] : $default;
    }

    public function hasFeature(SuchakAccount $account, string $featureKey, ?CarbonInterface $at = null): bool
    {
        $value = $this->currentFeatureValue($account, $featureKey, null, $at);

        return $value === true || (is_int($value) && $value !== 0) || (is_string($value) && $value !== '');
    }

    public function integerFeatureValue(
        SuchakAccount $account,
        string $featureKey,
        ?int $default = null,
        ?CarbonInterface $at = null,
    ): ?int {
        $value = $this->currentFeatureValue($account, $featureKey, $default, $at);

        return is_int($value) ? $value : $default;
    }

    private function assertFeatureKey(string $featureKey): void
    {
        if (! in_array($featureKey, SuchakPlanFeature::FEATURE_KEYS, true)) {
            throw new InvalidArgumentException('Invalid Suchak entitlement feature key.');
        }
    }
}
