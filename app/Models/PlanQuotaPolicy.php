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

    public static function ensureAllForPlan(Plan $plan): void
    {
        $plan->loadMissing('features');
        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            static::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $featureKey],
                static::defaultsFromPlanFeatures($plan, $featureKey)
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultsFromPlanFeatures(Plan $plan, string $featureKey): array
    {
        $raw = $plan->getFeatureValue($featureKey);

        if (PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($featureKey)) {
            $on = $raw !== null && $raw !== '' && in_array((string) $raw, ['1', 'true', 'yes', 'on'], true);

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

        if ($featureKey === PlanFeatureKeys::WHO_VIEWED_ME_DAYS) {
            $access = (string) ($plan->getFeatureValue(FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS) ?? '0');
            $daysRaw = $plan->getFeatureValue(PlanFeatureKeys::WHO_VIEWED_ME_DAYS);
            $days = is_numeric($daysRaw) ? (int) $daysRaw : 0;
            $enabled = $access === '1' || $days > 0;
            $unlimited = $days >= 999;

            return [
                'is_enabled' => $enabled,
                'refresh_type' => $unlimited ? self::REFRESH_UNLIMITED : self::REFRESH_MONTHLY_30D_IST,
                'limit_value' => $unlimited ? null : ($enabled ? max(0, $days) : 0),
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

        $n = is_numeric($raw) ? (int) $raw : 0;
        $unlimited = $n === -1;
        $meta = null;
        if ($featureKey === PlanFeatureKeys::INTEREST_VIEW_LIMIT) {
            $p = $plan->getFeatureValue(PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD) ?? 'monthly';
            $meta = ['interest_view_reset_period' => (string) $p];
        }

        return [
            'is_enabled' => $n !== 0 || $unlimited,
            'refresh_type' => $unlimited ? self::REFRESH_UNLIMITED : self::REFRESH_MONTHLY_30D_IST,
            'limit_value' => $unlimited ? null : max(0, $n),
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'grace_percent_of_plan' => 10,
            'overuse_mode' => self::OVERUSE_BLOCK,
            'pack_price_paise' => null,
            'pack_message_count' => null,
            'pack_validity_days' => null,
            'policy_meta' => $meta,
        ];
    }

    /**
     * Defaults when creating a new plan (no feature rows yet).
     *
     * @return array<string, mixed>
     */
    public static function defaultsForNewPlan(string $featureKey): array
    {
        if (PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($featureKey)) {
            return static::defaultsFromPlanFeatures(new Plan, $featureKey);
        }

        $meta = $featureKey === PlanFeatureKeys::INTEREST_VIEW_LIMIT
            ? ['interest_view_reset_period' => 'monthly']
            : null;

        return [
            'is_enabled' => true,
            'refresh_type' => self::REFRESH_MONTHLY_30D_IST,
            'limit_value' => 0,
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'grace_percent_of_plan' => 10,
            'overuse_mode' => self::OVERUSE_BLOCK,
            'pack_price_paise' => null,
            'pack_message_count' => null,
            'pack_validity_days' => null,
            'policy_meta' => $meta,
        ];
    }
}
