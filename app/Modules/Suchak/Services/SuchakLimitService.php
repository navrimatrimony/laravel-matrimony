<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakPlanFeature;
use Carbon\CarbonInterface;

class SuchakLimitService
{
    public function __construct(
        private readonly SuchakPolicyService $policyService,
        private readonly SuchakEntitlementService $entitlementService,
    ) {
    }

    public function requestActionSlaHours(): int
    {
        return $this->policyService->requestActionSlaHours();
    }

    public function collaborationSlaDays(): int
    {
        return $this->policyService->collaborationSlaDays();
    }

    public function qrTokenExpiryDays(): int
    {
        return $this->policyService->qrTokenExpiryDays();
    }

    public function uploadDailyLimit(): int
    {
        return $this->policyService->uploadDailyLimit();
    }

    public function activeProfileLimit(SuchakAccount $account, ?CarbonInterface $at = null): int
    {
        return $this->entitlementOrPolicyInteger(
            $account,
            SuchakPlanFeature::FEATURE_ACTIVE_PROFILE_LIMIT,
            $this->policyService->activeProfileFallbackLimit(),
            $at,
        );
    }

    public function pdfDownloadShareLimitPerDay(SuchakAccount $account, ?CarbonInterface $at = null): int
    {
        return $this->entitlementOrPolicyInteger(
            $account,
            SuchakPlanFeature::FEATURE_PDF_DOWNLOAD_SHARE_LIMIT,
            $this->policyService->pdfDownloadLimitPerDay(),
            $at,
        );
    }

    public function collaborationRequestLimit(SuchakAccount $account, ?CarbonInterface $at = null): ?int
    {
        return $this->entitlementService->integerFeatureValue(
            $account,
            SuchakPlanFeature::FEATURE_COLLABORATION_REQUEST_LIMIT,
            null,
            $at,
        );
    }

    public function leadRequestLimit(SuchakAccount $account, ?CarbonInterface $at = null): ?int
    {
        return $this->entitlementService->integerFeatureValue(
            $account,
            SuchakPlanFeature::FEATURE_LEAD_REQUEST_LIMIT,
            null,
            $at,
        );
    }

    private function entitlementOrPolicyInteger(
        SuchakAccount $account,
        string $featureKey,
        int $policyDefault,
        ?CarbonInterface $at,
    ): int {
        $entitled = $this->entitlementService->integerFeatureValue($account, $featureKey, null, $at);

        return $entitled ?? $policyDefault;
    }
}
