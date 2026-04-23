<?php

namespace App\Models;

use App\Services\FeatureUsageService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanQuotaPolicy extends Model
{
    public const REFRESH_UNLIMITED = 'unlimited';

    public const REFRESH_DAILY = 'daily';

    public const REFRESH_WEEKLY = 'weekly';

    public const REFRESH_MONTHLY_30D_IST = 'monthly_30d_ist';

    public const REFRESH_LIFETIME = 'lifetime';

    /** Incoming interest reveal window aligned with calendar quarters. */
    public const REFRESH_QUARTERLY = 'quarterly';

    public const OVERUSE_BLOCK = 'block';

    public const OVERUSE_PACK = 'pack';

    protected $table = 'plan_quota_policies';

    protected $fillable = [
        'plan_id',
        'feature_key',
        'is_enabled',
        'refresh_type',
        'limit_value',
        'daily_sub_cap',
        'per_day_usage_limit_enabled',
        'grace_percent_of_plan',
        'overuse_mode',
        'pack_price_paise',
        'pack_message_count',
        'pack_validity_days',
        'policy_meta',
    ];

    protected $casts = [
        'plan_id' => 'integer',
        'is_enabled' => 'boolean',
        'limit_value' => 'integer',
        'daily_sub_cap' => 'integer',
        'per_day_usage_limit_enabled' => 'boolean',
        'grace_percent_of_plan' => 'integer',
        'pack_price_paise' => 'integer',
        'pack_message_count' => 'integer',
        'pack_validity_days' => 'integer',
        'policy_meta' => 'array',
    ];

    /**
     * @return list<string>
     */
    public static function refreshTypes(): array
    {
        return [
            self::REFRESH_UNLIMITED,
            self::REFRESH_DAILY,
            self::REFRESH_WEEKLY,
            self::REFRESH_MONTHLY_30D_IST,
            self::REFRESH_QUARTERLY,
            self::REFRESH_LIFETIME,
        ];
    }

    /**
     * Legacy admin value `monthly` (removed duplicate option) maps to canonical storage.
     */
    public static function normalizeRefreshType(string $refresh): string
    {
        return $refresh === 'monthly' ? self::REFRESH_MONTHLY_30D_IST : $refresh;
    }

    /**
     * @return list<string>
     */
    public static function overuseModes(): array
    {
        return [self::OVERUSE_BLOCK, self::OVERUSE_PACK];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Ensures every {@see PlanQuotaPolicyKeys::ordered()} row exists for the plan (additive only).
     * Missing keys get safe defaults: disabled, limit 0, monthly refresh — SSOT readers stay valid.
     */
    public static function ensureAllKeysForPlan(Plan $plan): void
    {
        $plan->loadMissing('quotaPolicies');
        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            static::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $featureKey],
                static::defaultsForNewPlan($featureKey)
            );
        }
        $plan->unsetRelation('quotaPolicies');
    }

    /** @deprecated Use {@see ensureAllKeysForPlan} */
    public static function ensureAllForPlan(Plan $plan): void
    {
        static::ensureAllKeysForPlan($plan);
    }

    /**
     * Catalog/seed only: upserts quota rows from an in-memory map (never read {@code plan_features} at runtime).
     *
     * @param  array<string, string|int|float|bool|null>  $featureValues
     */
    public static function syncRowsFromCatalogFeatureMap(int $planId, array $featureValues): void
    {
        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            $attrs = static::attributesFromCatalogFeatureMap($featureKey, $featureValues);
            static::query()->updateOrCreate(
                ['plan_id' => $planId, 'feature_key' => $featureKey],
                $attrs
            );
        }
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $m
     * @return array<string, mixed>
     */
    private static function attributesFromCatalogFeatureMap(string $featureKey, array $m): array
    {
        $rawStr = static function (string $k) use ($m): string {
            $v = $m[$k] ?? '';

            return is_scalar($v) ? trim((string) $v) : '';
        };

        if (PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($featureKey)) {
            $raw = $rawStr($featureKey);
            $on = $raw !== '' && in_array($raw, ['1', 'true', 'yes', 'on'], true);

            return [
                'is_enabled' => $on,
                'refresh_type' => self::REFRESH_MONTHLY_30D_IST,
                'limit_value' => null,
                'daily_sub_cap' => null,
                'per_day_usage_limit_enabled' => false,
                'grace_percent_of_plan' => 10,
                'overuse_mode' => self::OVERUSE_BLOCK,
                'pack_price_paise' => null,
                'pack_message_count' => null,
                'pack_validity_days' => null,
                'policy_meta' => null,
            ];
        }

        if ($featureKey === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
            $access = $rawStr(FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS);
            $previewRaw = $rawStr(PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT);
            $preview = is_numeric($previewRaw) ? (int) $previewRaw : 0;
            $unlimited = $preview === -1 || $preview >= 999;
            $accessOn = $access !== '' && in_array($access, ['1', 'true', 'yes', 'on'], true);
            $enabled = $accessOn || $preview > 0 || $unlimited;

            return [
                'is_enabled' => $enabled,
                'refresh_type' => $unlimited ? self::REFRESH_UNLIMITED : self::REFRESH_MONTHLY_30D_IST,
                'limit_value' => $unlimited ? null : max(0, $preview),
                'daily_sub_cap' => null,
                'per_day_usage_limit_enabled' => false,
                'grace_percent_of_plan' => 10,
                'overuse_mode' => self::OVERUSE_BLOCK,
                'pack_price_paise' => null,
                'pack_message_count' => null,
                'pack_validity_days' => null,
                'policy_meta' => null,
            ];
        }

        $raw = $rawStr($featureKey);
        $n = is_numeric($raw) ? (int) $raw : 0;
        $unlimited = $n === -1;
        $policyMeta = null;
        $refreshForNumeric = self::REFRESH_MONTHLY_30D_IST;
        if ($featureKey === PlanFeatureKeys::INTEREST_VIEW_LIMIT) {
            $p = strtolower(trim($rawStr(PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD)));
            if ($p === '') {
                $p = 'monthly';
            }
            $refreshForNumeric = match ($p) {
                'weekly' => self::REFRESH_WEEKLY,
                'quarterly' => self::REFRESH_QUARTERLY,
                default => self::REFRESH_MONTHLY_30D_IST,
            };
        }
        if ($featureKey === PlanFeatureKeys::CHAT_SEND_LIMIT) {
            $ci = $rawStr(PlanFeatureKeys::CHAT_INITIATE_NEW_CHATS_ONLY);
            $on = $ci !== '' && in_array($ci, ['1', 'true', 'yes', 'on'], true);
            $policyMeta = ['chat_initiate_new_chats_only' => $on];
        }

        return [
            'is_enabled' => $n !== 0 || $unlimited,
            'refresh_type' => $unlimited ? self::REFRESH_UNLIMITED : $refreshForNumeric,
            'limit_value' => $unlimited ? null : max(0, $n),
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'grace_percent_of_plan' => 10,
            'overuse_mode' => self::OVERUSE_BLOCK,
            'pack_price_paise' => null,
            'pack_message_count' => null,
            'pack_validity_days' => null,
            'policy_meta' => $policyMeta,
        ];
    }

    /**
     * Defaults when creating a new plan row (no reads from plan_features).
     *
     * @return array<string, mixed>
     */
    public static function defaultsForNewPlan(string $featureKey): array
    {
        if (PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($featureKey)) {
            return [
                'is_enabled' => false,
                'refresh_type' => self::REFRESH_MONTHLY_30D_IST,
                'limit_value' => null,
                'daily_sub_cap' => null,
                'per_day_usage_limit_enabled' => false,
                'grace_percent_of_plan' => 10,
                'overuse_mode' => self::OVERUSE_BLOCK,
                'pack_price_paise' => null,
                'pack_message_count' => null,
                'pack_validity_days' => null,
                'policy_meta' => null,
            ];
        }

        if ($featureKey === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
            return [
                'is_enabled' => false,
                'refresh_type' => self::REFRESH_MONTHLY_30D_IST,
                'limit_value' => 0,
                'daily_sub_cap' => null,
                'per_day_usage_limit_enabled' => false,
                'grace_percent_of_plan' => 10,
                'overuse_mode' => self::OVERUSE_BLOCK,
                'pack_price_paise' => null,
                'pack_message_count' => null,
                'pack_validity_days' => null,
                'policy_meta' => null,
            ];
        }

        $policyMeta = match ($featureKey) {
            PlanFeatureKeys::CHAT_SEND_LIMIT => ['chat_initiate_new_chats_only' => false],
            default => null,
        };

        return [
            'is_enabled' => false,
            'refresh_type' => self::REFRESH_MONTHLY_30D_IST,
            'limit_value' => 0,
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'grace_percent_of_plan' => 10,
            'overuse_mode' => self::OVERUSE_BLOCK,
            'pack_price_paise' => null,
            'pack_message_count' => null,
            'pack_validity_days' => null,
            'policy_meta' => $policyMeta,
        ];
    }
}
