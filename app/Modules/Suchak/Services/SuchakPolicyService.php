<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakPolicy;

class SuchakPolicyService
{
    public const KEY_DEFAULT_CONSENT_VALIDITY_MONTHS = 'default_consent_validity_months';
    public const KEY_ALLOW_TWO_YEAR_CONSENT = 'allow_two_year_consent';
    public const KEY_ALLOW_UNTIL_REVOKED_CONSENT = 'allow_until_revoked_consent';
    public const KEY_REQUEST_ACTION_SLA_HOURS = 'request_action_sla_hours';
    public const KEY_COLLABORATION_SLA_DAYS = 'collaboration_sla_days';
    public const KEY_PDF_DOWNLOAD_LIMIT_PER_DAY = 'pdf_download_limit_per_day';
    public const KEY_QR_TOKEN_EXPIRY_DAYS = 'qr_token_expiry_days';
    public const KEY_SUCHAK_UPLOAD_DAILY_LIMIT = 'suchak_upload_daily_limit';
    public const KEY_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN = 'suchak_active_profile_limit_by_plan';
    public const KEY_SUCHAK_FREE_TRIAL_DAYS = 'suchak_free_trial_days';
    public const KEY_SUCHAK_GRACE_PERIOD_DAYS = 'suchak_grace_period_days';
    public const KEY_SUCHAK_PLAN_PRICING_MODE = 'suchak_plan_pricing_mode';
    public const KEY_SUCHAK_PAYMENT_MODE = 'suchak_payment_mode';
    public const KEY_SUCHAK_COMMISSION_RULES_JSON = 'suchak_commission_rules_json';
    public const KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE = 'suchak_package_publish_approval_mode';

    public const DEFAULT_CONSENT_VALIDITY_MONTHS = 12;
    public const DEFAULT_REQUEST_ACTION_SLA_HOURS = 48;
    public const DEFAULT_COLLABORATION_SLA_DAYS = 7;
    public const DEFAULT_PDF_DOWNLOAD_LIMIT_PER_DAY = 20;
    public const DEFAULT_QR_TOKEN_EXPIRY_DAYS = 30;
    public const DEFAULT_SUCHAK_UPLOAD_DAILY_LIMIT = 25;
    public const DEFAULT_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN = 0;
    public const DEFAULT_SUCHAK_FREE_TRIAL_DAYS = 0;
    public const DEFAULT_SUCHAK_GRACE_PERIOD_DAYS = 0;
    public const DEFAULT_SUCHAK_PLAN_PRICING_MODE = 'manual_catalog';
    public const DEFAULT_SUCHAK_PAYMENT_MODE = 'manual_only';
    public const DEFAULT_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE = 'admin_review';
    public const DEFAULT_SUCHAK_COMMISSION_RULES = [
        'mode' => 'to_be_discussed',
        'default_percent' => 0,
        'default_amount' => 0,
        'require_ack' => true,
    ];

    public function value(string $key, mixed $default = null): mixed
    {
        $policy = $this->activePolicy($key);

        if ($policy === null) {
            return $default;
        }

        return $this->typedValue($policy, $default);
    }

    public function integer(string $key, int $default): int
    {
        $value = $this->value($key, $default);

        return is_int($value) ? $value : $default;
    }

    public function positiveInteger(string $key, int $default): int
    {
        $value = $this->integer($key, $default);

        return $value > 0 ? $value : $default;
    }

    public function boolean(string $key, bool $default): bool
    {
        $value = $this->value($key, $default);

        return is_bool($value) ? $value : $default;
    }

    public function string(string $key, string $default): string
    {
        $value = $this->value($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function array(string $key, array $default): array
    {
        $value = $this->value($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function consentValidityMonths(): int
    {
        return $this->positiveInteger(
            self::KEY_DEFAULT_CONSENT_VALIDITY_MONTHS,
            self::DEFAULT_CONSENT_VALIDITY_MONTHS,
        );
    }

    public function allowsTwoYearConsent(): bool
    {
        return $this->boolean(self::KEY_ALLOW_TWO_YEAR_CONSENT, true);
    }

    public function allowsUntilRevokedConsent(): bool
    {
        return $this->boolean(self::KEY_ALLOW_UNTIL_REVOKED_CONSENT, true);
    }

    public function requestActionSlaHours(): int
    {
        return $this->positiveInteger(
            self::KEY_REQUEST_ACTION_SLA_HOURS,
            self::DEFAULT_REQUEST_ACTION_SLA_HOURS,
        );
    }

    public function collaborationSlaDays(): int
    {
        return $this->positiveInteger(
            self::KEY_COLLABORATION_SLA_DAYS,
            self::DEFAULT_COLLABORATION_SLA_DAYS,
        );
    }

    public function pdfDownloadLimitPerDay(): int
    {
        return $this->positiveInteger(
            self::KEY_PDF_DOWNLOAD_LIMIT_PER_DAY,
            self::DEFAULT_PDF_DOWNLOAD_LIMIT_PER_DAY,
        );
    }

    public function qrTokenExpiryDays(): int
    {
        return $this->positiveInteger(
            self::KEY_QR_TOKEN_EXPIRY_DAYS,
            self::DEFAULT_QR_TOKEN_EXPIRY_DAYS,
        );
    }

    public function uploadDailyLimit(): int
    {
        return $this->positiveInteger(
            self::KEY_SUCHAK_UPLOAD_DAILY_LIMIT,
            self::DEFAULT_SUCHAK_UPLOAD_DAILY_LIMIT,
        );
    }

    public function activeProfileFallbackLimit(): int
    {
        return $this->integer(
            self::KEY_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN,
            self::DEFAULT_SUCHAK_ACTIVE_PROFILE_LIMIT_BY_PLAN,
        );
    }

    public function freeTrialDays(): int
    {
        return max(0, $this->integer(
            self::KEY_SUCHAK_FREE_TRIAL_DAYS,
            self::DEFAULT_SUCHAK_FREE_TRIAL_DAYS,
        ));
    }

    public function gracePeriodDays(): int
    {
        return max(0, $this->integer(
            self::KEY_SUCHAK_GRACE_PERIOD_DAYS,
            self::DEFAULT_SUCHAK_GRACE_PERIOD_DAYS,
        ));
    }

    public function planPricingMode(): string
    {
        return $this->string(
            self::KEY_SUCHAK_PLAN_PRICING_MODE,
            self::DEFAULT_SUCHAK_PLAN_PRICING_MODE,
        );
    }

    public function paymentMode(): string
    {
        return $this->string(
            self::KEY_SUCHAK_PAYMENT_MODE,
            self::DEFAULT_SUCHAK_PAYMENT_MODE,
        );
    }

    public function packagePublishApprovalMode(): string
    {
        $mode = $this->string(
            self::KEY_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE,
            self::DEFAULT_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE,
        );

        return in_array($mode, ['admin_review', 'auto_publish'], true)
            ? $mode
            : self::DEFAULT_SUCHAK_PACKAGE_PUBLISH_APPROVAL_MODE;
    }

    /**
     * @return array<string, mixed>
     */
    public function commissionRules(): array
    {
        return $this->array(
            self::KEY_SUCHAK_COMMISSION_RULES_JSON,
            self::DEFAULT_SUCHAK_COMMISSION_RULES,
        );
    }

    private function activePolicy(string $key): ?SuchakPolicy
    {
        return SuchakPolicy::query()
            ->where('policy_key', $key)
            ->where('is_active', true)
            ->first();
    }

    private function typedValue(SuchakPolicy $policy, mixed $default): mixed
    {
        $raw = trim((string) $policy->policy_value);

        return match ($policy->value_type) {
            SuchakPolicy::TYPE_INTEGER => preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : $default,
            SuchakPolicy::TYPE_BOOLEAN => $this->booleanValue($raw),
            SuchakPolicy::TYPE_JSON => $this->jsonValue($raw, $default),
            SuchakPolicy::TYPE_STRING => $raw,
            default => $default,
        };
    }

    private function booleanValue(string $raw): bool
    {
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    private function jsonValue(string $raw, mixed $default): mixed
    {
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}
