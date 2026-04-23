<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when quota data is missing from the only allowed sources
 * ({@code checkout_snapshot.quota_policies} or {@see \App\Models\Plan::$quotaPolicies}).
 */
final class QuotaPolicySourceViolation extends RuntimeException
{
    public static function missingPolicyRow(string $context, string $featureKey): self
    {
        return new self("Quota SSOT violation: missing plan_quota_policies row for `{$featureKey}` ({$context}).");
    }

    public static function missingSnapshotPayload(string $context, string $featureKey): self
    {
        return new self("Quota SSOT violation: checkout_snapshot.quota_policies missing `{$featureKey}` ({$context}).");
    }

    public static function incompletePayloads(string $context, string $detail = ''): self
    {
        $suffix = $detail !== '' ? ' '.$detail : '';

        return new self("Quota SSOT violation: incomplete quota payloads ({$context}).{$suffix}");
    }

    /**
     * Quota-engine keys must be read via {@see \App\Services\PlanQuotaUiSource} / entitlements, not {@code plan_features}.
     */
    public static function planFeaturesReadForbidden(string $featureKey, string $context = ''): self
    {
        $suffix = $context !== '' ? " ({$context})" : '';

        return new self(
            "Quota SSOT violation: `{$featureKey}` must not be read from plan_features. Use PlanQuotaUiSource or EntitlementService.{$suffix}"
        );
    }
}
