<?php

namespace App\Services\BiodataExport;

use App\Models\User;
use App\Services\EntitlementService;
use App\Services\FeatureUsageService;
use App\Support\PlanFeatureKeys;

final class BiodataExportPolicyService
{
    public function __construct(
        private FeatureUsageService $featureUsage,
        private EntitlementService $entitlements,
    ) {}

    /**
     * @return array{allowed: bool, limit: int|null, used: int, remaining: int|null, unlimited: bool, reset_at: mixed, reason: string|null}
     */
    public function exportState(User $user): array
    {
        return $this->featureUsage->getFeatureState($user, PlanFeatureKeys::BIODATA_EXPORT_LIMIT);
    }

    public function canUsePremiumTemplate(User $user): bool
    {
        if ($user->isAnyAdmin()) {
            return true;
        }

        return $this->entitlements->hasFeature((int) $user->id, PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES);
    }

    public function consumeExport(User $user): bool
    {
        return $this->featureUsage->consume((int) $user->id, PlanFeatureKeys::BIODATA_EXPORT_LIMIT);
    }
}
