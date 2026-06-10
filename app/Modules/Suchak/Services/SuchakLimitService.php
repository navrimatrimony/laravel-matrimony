<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakPlanFeature;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use Carbon\CarbonInterface;
use InvalidArgumentException;

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

    public function monthlyUploadLimit(SuchakAccount $account, ?CarbonInterface $at = null): ?int
    {
        return $this->entitlementService->integerFeatureValue(
            $account,
            SuchakPlanFeature::FEATURE_MONTHLY_UPLOAD_LIMIT,
            null,
            $at,
        );
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

    public function assertUploadAllowed(SuchakAccount $account, ?CarbonInterface $at = null): void
    {
        $at ??= now();
        $dailyLimit = $this->uploadDailyLimit();

        if ($dailyLimit > 0) {
            $usedToday = SuchakBiodataIntakeLink::query()
                ->where('suchak_account_id', $account->id)
                ->where('created_at', '>=', $at->copy()->startOfDay())
                ->count();

            if ($usedToday >= $dailyLimit) {
                throw new InvalidArgumentException('Daily Suchak upload limit reached for this account.');
            }
        }

        $monthlyLimit = $this->monthlyUploadLimit($account, $at);
        if ($monthlyLimit !== null && $monthlyLimit > 0) {
            $usedThisMonth = SuchakBiodataIntakeLink::query()
                ->where('suchak_account_id', $account->id)
                ->where('created_at', '>=', $at->copy()->startOfMonth())
                ->count();

            if ($usedThisMonth >= $monthlyLimit) {
                throw new InvalidArgumentException('Monthly Suchak upload entitlement limit reached for this account.');
            }
        }
    }

    public function assertPdfExportAllowed(SuchakAccount $account, ?CarbonInterface $at = null): void
    {
        $limit = $this->pdfDownloadShareLimitPerDay($account, $at);
        if ($limit <= 0) {
            return;
        }

        $at ??= now();
        $usedToday = SuchakBiodataExport::query()
            ->where('suchak_account_id', $account->id)
            ->where('export_type', SuchakBiodataExport::TYPE_BIODATA_PDF)
            ->where('created_at', '>=', $at->copy()->startOfDay())
            ->count();

        if ($usedToday >= $limit) {
            throw new InvalidArgumentException('Daily PDF/QR limit reached for this Suchak account.');
        }
    }

    public function assertActiveProfileSlotAvailable(SuchakAccount $account, ?CarbonInterface $at = null): void
    {
        $limit = $this->activeProfileLimit($account, $at);
        if ($limit <= 0) {
            return;
        }

        $used = SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->whereIn('representation_status', [
                SuchakProfileRepresentation::STATUS_PENDING,
                SuchakProfileRepresentation::STATUS_CONSENT_PENDING,
                SuchakProfileRepresentation::STATUS_ACTIVE,
            ])
            ->count();

        if ($used >= $limit) {
            throw new InvalidArgumentException('Active profile limit reached for this Suchak account.');
        }
    }

    public function assertCollaborationRequestAllowed(SuchakAccount $account, ?CarbonInterface $at = null): void
    {
        $limit = $this->collaborationRequestLimit($account, $at);
        if ($limit === null || $limit <= 0) {
            return;
        }

        $openRequests = SuchakCollaborationRequest::query()
            ->where('requesting_suchak_account_id', $account->id)
            ->whereIn('status', SuchakCollaborationRequest::OPEN_STATUSES)
            ->count();

        if ($openRequests >= $limit) {
            throw new InvalidArgumentException('Open collaboration request limit reached for this Suchak account.');
        }
    }

    public function assertLeadRequestAllowed(SuchakAccount $account, ?CarbonInterface $at = null): void
    {
        $limit = $this->leadRequestLimit($account, $at);
        if ($limit === null || $limit <= 0) {
            return;
        }

        $openRequests = SuchakProfileRequest::query()
            ->where('selected_suchak_account_id', $account->id)
            ->whereIn('request_status', SuchakProfileRequest::OPEN_STATUSES)
            ->count();

        if ($openRequests >= $limit) {
            throw new InvalidArgumentException('Open Suchak lead request limit reached for this account.');
        }
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
