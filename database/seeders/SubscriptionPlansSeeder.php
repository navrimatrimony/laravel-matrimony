<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Services\FeatureUsageService;
use App\Services\PlanQuotaPolicyMirror;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use App\Support\PlanQuotaPolicyKeys;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resets catalog plans to gendered tiers: free/basic/silver/gold × male/female.
 *
 * Quota + pricing SSOT matches the approved local catalog (male values; female identical).
 * Do NOT run this seeder on production with live subscriptions — it deletes all plans/subs.
 */
class SubscriptionPlansSeeder extends Seeder
{
    /**
     * @return array<string, string>
     */
    private static function catalogPlanNameMr(string $tier, string $gender): string
    {
        $g = $gender === 'male' ? 'पुरुष' : 'स्त्री';

        return match ($tier) {
            'free' => 'फ्री ('.$g.')',
            'basic' => 'बेसिक ('.$g.')',
            'silver' => 'सिल्वर ('.$g.')',
            'gold' => 'गोल्ड ('.$g.')',
            default => ucfirst($tier).' ('.$g.')',
        };
    }

    /**
     * Non-quota legacy plan_features kept alongside quota mirrors.
     *
     * @return array<string, string>
     */
    private static function extraFeatureValues(string $tier): array
    {
        return match ($tier) {
            'free' => [
                PlanFeatureKeys::BIODATA_EXPORT_LIMIT => '1',
                PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES => '0',
                SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '0',
                PlanFeatureKeys::PHOTO_BLUR_LIMIT => '0',
                PlanFeatureKeys::REFERRAL_BONUS_DAYS => '0',
            ],
            'basic' => [
                PlanFeatureKeys::BIODATA_EXPORT_LIMIT => '5',
                PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES => '0',
                SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '0',
                PlanFeatureKeys::PHOTO_BLUR_LIMIT => '0',
                PlanFeatureKeys::REFERRAL_BONUS_DAYS => '0',
            ],
            'silver' => [
                PlanFeatureKeys::BIODATA_EXPORT_LIMIT => '20',
                PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES => '1',
                SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
                PlanFeatureKeys::PHOTO_BLUR_LIMIT => '0',
                PlanFeatureKeys::REFERRAL_BONUS_DAYS => '0',
            ],
            'gold' => [
                PlanFeatureKeys::BIODATA_EXPORT_LIMIT => '-1',
                PlanFeatureKeys::BIODATA_PREMIUM_TEMPLATES => '1',
                SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
                PlanFeatureKeys::PHOTO_BLUR_LIMIT => '0',
                PlanFeatureKeys::REFERRAL_BONUS_DAYS => '0',
            ],
            default => [],
        };
    }

    /**
     * @return array{is_enabled: bool, refresh_type: string, limit_value: int|null}
     */
    private static function q(bool $enabled, string $refresh, ?int $limit): array
    {
        return [
            'is_enabled' => $enabled,
            'refresh_type' => $refresh,
            'limit_value' => $limit,
        ];
    }

    /**
     * Catalog quotas captured from local male plans (female uses the same numbers).
     *
     * @return array<string, array<string, array{is_enabled: bool, refresh_type: string, limit_value: int|null}>>
     */
    private static function tierQuotaPolicies(): array
    {
        $lt = PlanQuotaPolicy::REFRESH_LIFETIME;
        $mo = PlanQuotaPolicy::REFRESH_MONTHLY_30D_IST;
        $un = PlanQuotaPolicy::REFRESH_UNLIMITED;
        $wk = PlanQuotaPolicy::REFRESH_WEEKLY;
        $dy = PlanQuotaPolicy::REFRESH_DAILY;

        $boolOff = self::q(false, $lt, null);
        $boolOn = self::q(true, $lt, null);

        return [
            'free' => [
                PlanFeatureKeys::CHAT_SEND_LIMIT => self::q(true, $mo, 100),
                PlanFeatureKeys::CHAT_CAN_READ => $boolOff,
                PlanFeatureKeys::CONTACT_VIEW_LIMIT => self::q(false, $lt, 0),
                PlanFeatureKeys::INTEREST_SEND_LIMIT => self::q(true, $mo, 100),
                PlanFeatureKeys::INTEREST_VIEW_LIMIT => self::q(false, $lt, 0),
                SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => self::q(true, $mo, 100),
                PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT => self::q(false, $lt, 0),
                PlanFeatureKeys::PHOTO_FULL_ACCESS => $boolOff,
                PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => self::q(false, $wk, 0),
                PlanFeatureKeys::PRIORITY_LISTING => $boolOff,
                PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => self::q(true, $mo, 10),
                PlanFeatureKeys::ADVANCED_PROFILE_SEARCH => $boolOff,
                PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT => $boolOff,
            ],
            'basic' => [
                PlanFeatureKeys::CHAT_SEND_LIMIT => self::q(true, $mo, 300),
                PlanFeatureKeys::CHAT_CAN_READ => $boolOn,
                PlanFeatureKeys::CONTACT_VIEW_LIMIT => self::q(true, $lt, 60),
                PlanFeatureKeys::INTEREST_SEND_LIMIT => self::q(true, $mo, 300),
                PlanFeatureKeys::INTEREST_VIEW_LIMIT => self::q(true, $lt, 100),
                SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => self::q(true, $un, null),
                PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT => self::q(true, $lt, 60),
                PlanFeatureKeys::PHOTO_FULL_ACCESS => $boolOn,
                PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => self::q(false, $wk, 0),
                PlanFeatureKeys::PRIORITY_LISTING => $boolOff,
                PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => self::q(true, $lt, 60),
                PlanFeatureKeys::ADVANCED_PROFILE_SEARCH => $boolOff,
                PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT => $boolOff,
            ],
            'silver' => [
                PlanFeatureKeys::CHAT_SEND_LIMIT => self::q(true, $un, null),
                PlanFeatureKeys::CHAT_CAN_READ => $boolOn,
                PlanFeatureKeys::CONTACT_VIEW_LIMIT => self::q(true, $lt, 200),
                PlanFeatureKeys::INTEREST_SEND_LIMIT => self::q(true, $un, null),
                PlanFeatureKeys::INTEREST_VIEW_LIMIT => self::q(true, $lt, 200),
                SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => self::q(true, $un, null),
                PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT => self::q(true, $lt, 300),
                PlanFeatureKeys::PHOTO_FULL_ACCESS => $boolOn,
                PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => self::q(true, $wk, 1),
                PlanFeatureKeys::PRIORITY_LISTING => $boolOn,
                PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => self::q(true, $lt, 100),
                PlanFeatureKeys::ADVANCED_PROFILE_SEARCH => $boolOn,
                PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT => $boolOff,
            ],
            'gold' => [
                PlanFeatureKeys::CHAT_SEND_LIMIT => self::q(true, $un, null),
                PlanFeatureKeys::CHAT_CAN_READ => $boolOn,
                PlanFeatureKeys::CONTACT_VIEW_LIMIT => self::q(true, $lt, 250),
                PlanFeatureKeys::INTEREST_SEND_LIMIT => self::q(true, $un, null),
                PlanFeatureKeys::INTEREST_VIEW_LIMIT => self::q(true, $un, null),
                SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => self::q(true, $un, null),
                PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT => self::q(true, $un, null),
                PlanFeatureKeys::PHOTO_FULL_ACCESS => $boolOn,
                PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => self::q(true, $dy, 1),
                PlanFeatureKeys::PRIORITY_LISTING => $boolOn,
                PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => self::q(true, $lt, 300),
                PlanFeatureKeys::ADVANCED_PROFILE_SEARCH => $boolOn,
                PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT => $boolOff,
            ],
        ];
    }

    /**
     * @return array{price: float, selling_price: float, duration_days: int, default_billing_key: ?string, grace_period_days: int, leftover_quota_carry_window_days: ?int, highlight: bool, marketing_badge: ?string}
     */
    private static function tierPlanAttributes(string $tier): array
    {
        return match ($tier) {
            'free' => [
                'price' => 0.0,
                'selling_price' => 0.0,
                'duration_days' => 0,
                'default_billing_key' => null,
                'grace_period_days' => 3,
                'leftover_quota_carry_window_days' => null,
                'highlight' => false,
                'marketing_badge' => null,
            ],
            'basic' => [
                'price' => 1499.0,
                'selling_price' => 999.0,
                'duration_days' => 30,
                'default_billing_key' => PlanTerm::BILLING_MONTHLY,
                'grace_period_days' => 3,
                'leftover_quota_carry_window_days' => 7,
                'highlight' => false,
                'marketing_badge' => null,
            ],
            'silver' => [
                'price' => 4999.0,
                'selling_price' => 2999.0,
                'duration_days' => 90,
                'default_billing_key' => PlanTerm::BILLING_QUARTERLY,
                'grace_period_days' => 5,
                'leftover_quota_carry_window_days' => 30,
                'highlight' => false,
                'marketing_badge' => null,
            ],
            'gold' => [
                'price' => 22999.0,
                'selling_price' => 5999.0,
                'duration_days' => 365,
                'default_billing_key' => PlanTerm::BILLING_YEARLY,
                'grace_period_days' => 30,
                'leftover_quota_carry_window_days' => 30,
                'highlight' => true,
                'marketing_badge' => 'recommended',
            ],
            default => throw new \InvalidArgumentException('Unknown tier: '.$tier),
        };
    }

    /**
     * @return list<array{billing_key: string, duration_days: int, price: float, selling_price: float, quota_bonus_percent: int, is_visible: bool}>
     */
    private static function tierTerms(string $tier): array
    {
        return match ($tier) {
            'basic' => [
                ['billing_key' => PlanTerm::BILLING_MONTHLY, 'duration_days' => 30, 'price' => 1499.0, 'selling_price' => 999.0, 'quota_bonus_percent' => 0, 'is_visible' => true],
                ['billing_key' => PlanTerm::BILLING_QUARTERLY, 'duration_days' => 90, 'price' => 3599.0, 'selling_price' => 1999.0, 'quota_bonus_percent' => 5, 'is_visible' => true],
                ['billing_key' => PlanTerm::BILLING_HALF_YEARLY, 'duration_days' => 180, 'price' => 6999.0, 'selling_price' => 2999.0, 'quota_bonus_percent' => 10, 'is_visible' => true],
                ['billing_key' => PlanTerm::BILLING_YEARLY, 'duration_days' => 365, 'price' => 10999.0, 'selling_price' => 3999.0, 'quota_bonus_percent' => 20, 'is_visible' => true],
            ],
            'silver' => [
                ['billing_key' => PlanTerm::BILLING_MONTHLY, 'duration_days' => 30, 'price' => 2999.0, 'selling_price' => 1999.0, 'quota_bonus_percent' => 0, 'is_visible' => true],
                ['billing_key' => PlanTerm::BILLING_QUARTERLY, 'duration_days' => 90, 'price' => 4999.0, 'selling_price' => 2999.0, 'quota_bonus_percent' => 5, 'is_visible' => true],
                ['billing_key' => PlanTerm::BILLING_HALF_YEARLY, 'duration_days' => 180, 'price' => 8999.0, 'selling_price' => 3999.0, 'quota_bonus_percent' => 10, 'is_visible' => true],
                ['billing_key' => PlanTerm::BILLING_YEARLY, 'duration_days' => 365, 'price' => 12999.0, 'selling_price' => 5999.0, 'quota_bonus_percent' => 20, 'is_visible' => true],
            ],
            'gold' => [
                ['billing_key' => PlanTerm::BILLING_MONTHLY, 'duration_days' => 30, 'price' => 4999.0, 'selling_price' => 2999.0, 'quota_bonus_percent' => 0, 'is_visible' => true],
                ['billing_key' => PlanTerm::BILLING_QUARTERLY, 'duration_days' => 90, 'price' => 8999.0, 'selling_price' => 3999.0, 'quota_bonus_percent' => 5, 'is_visible' => true],
                ['billing_key' => PlanTerm::BILLING_HALF_YEARLY, 'duration_days' => 180, 'price' => 14999.0, 'selling_price' => 4999.0, 'quota_bonus_percent' => 10, 'is_visible' => true],
                ['billing_key' => PlanTerm::BILLING_YEARLY, 'duration_days' => 365, 'price' => 22999.0, 'selling_price' => 5999.0, 'quota_bonus_percent' => 20, 'is_visible' => true],
            ],
            default => [],
        };
    }

    /**
     * @param  array{is_enabled: bool, refresh_type: string, limit_value: int|null}  $spec
     * @return array<string, mixed>
     */
    private static function policyAttributes(string $featureKey, array $spec): array
    {
        $attrs = [
            'is_enabled' => $spec['is_enabled'],
            'refresh_type' => PlanQuotaPolicy::normalizeRefreshType($spec['refresh_type']),
            'limit_value' => $spec['limit_value'],
            'daily_sub_cap' => null,
            'per_day_usage_limit_enabled' => false,
            'grace_percent_of_plan' => 10,
            'overuse_mode' => PlanQuotaPolicy::OVERUSE_BLOCK,
            'pack_price_paise' => null,
            'pack_message_count' => null,
            'pack_validity_days' => null,
            'policy_meta' => null,
        ];

        if ($featureKey === PlanFeatureKeys::CHAT_SEND_LIMIT) {
            $attrs['policy_meta'] = ['chat_initiate_new_chats_only' => false];
        }

        if (PlanQuotaPolicyKeys::mirrorsPlanFeatureAsBooleanOnly($featureKey)) {
            $attrs['limit_value'] = null;
        }

        return $attrs;
    }

    private static function syncQuotaPolicies(Plan $plan, array $quotaByKey): void
    {
        PlanQuotaPolicy::ensureAllKeysForPlan($plan);
        foreach (PlanQuotaPolicyKeys::ordered() as $featureKey) {
            $spec = $quotaByKey[$featureKey] ?? self::q(false, PlanQuotaPolicy::REFRESH_LIFETIME, null);
            PlanQuotaPolicy::query()->updateOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $featureKey],
                self::policyAttributes($featureKey, $spec),
            );
        }
    }

    private static function syncMirroredAndExtraFeatures(Plan $plan, string $tier): void
    {
        $rows = [];
        $plan->load('quotaPolicies');
        foreach ($plan->quotaPolicies as $policy) {
            $payload = PlanQuotaPolicyMirror::payloadFromModel($policy);
            foreach (PlanQuotaPolicyMirror::mirroredFeatureRowsFromPolicyPayload((string) $policy->feature_key, $payload) as $row) {
                $rows[$row['key']] = $row['value'];
            }
            if ((string) $policy->feature_key === PlanFeatureKeys::INTEREST_VIEW_LIMIT) {
                $rt = PlanQuotaPolicy::normalizeRefreshType((string) $policy->refresh_type);
                $rows[PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD] = match ($rt) {
                    PlanQuotaPolicy::REFRESH_WEEKLY => 'weekly',
                    PlanQuotaPolicy::REFRESH_QUARTERLY => 'quarterly',
                    PlanQuotaPolicy::REFRESH_LIFETIME => 'lifetime',
                    PlanQuotaPolicy::REFRESH_UNLIMITED => 'unlimited',
                    PlanQuotaPolicy::REFRESH_DAILY => 'daily',
                    default => 'monthly',
                };
            }
            if ((string) $policy->feature_key === PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT) {
                $rows[FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS] = $policy->is_enabled ? '1' : '0';
            }
        }

        foreach (self::extraFeatureValues($tier) as $key => $value) {
            $rows[$key] = $value;
        }

        PlanFeature::query()->where('plan_id', $plan->id)->delete();
        foreach ($rows as $key => $value) {
            PlanFeature::query()->create([
                'plan_id' => $plan->id,
                'key' => $key,
                'value' => (string) $value,
            ]);
        }
    }

    private static function syncTerms(Plan $plan, string $tier): void
    {
        PlanTerm::query()->where('plan_id', $plan->id)->delete();
        if (Plan::isFreeCatalogSlug((string) $plan->slug)) {
            return;
        }

        foreach (self::tierTerms($tier) as $term) {
            PlanTerm::query()->create([
                'plan_id' => $plan->id,
                'billing_key' => $term['billing_key'],
                'duration_days' => $term['duration_days'],
                'price' => $term['price'],
                'selling_price' => $term['selling_price'],
                'discount_percent' => null,
                'quota_bonus_percent' => $term['quota_bonus_percent'],
                'is_visible' => $term['is_visible'],
                'sort_order' => PlanTerm::defaultSortOrder($term['billing_key']),
            ]);
        }
    }

    public function run(): void
    {
        if (Schema::hasTable('subscriptions')) {
            DB::table('subscriptions')->delete();
        }

        foreach (Plan::query()->cursor() as $existing) {
            $existing->delete();
        }

        $quotas = self::tierQuotaPolicies();

        foreach (['male', 'female'] as $gender) {
            foreach (['free', 'basic', 'silver', 'gold'] as $tier) {
                $attrs = self::tierPlanAttributes($tier);
                $sort = ($gender === 'male' ? 0 : 100) + match ($tier) {
                    'free' => 10,
                    'basic' => 20,
                    'silver' => 30,
                    'gold' => 40,
                    default => 0,
                };

                $row = [
                    'name' => ucfirst($tier).' ('.ucfirst($gender).')',
                    'name_mr' => self::catalogPlanNameMr($tier, $gender),
                    'slug' => $tier.'_'.$gender,
                    'applies_to_gender' => $gender,
                    'price' => $attrs['price'],
                    'selling_price' => $attrs['selling_price'],
                    'discount_percent' => null,
                    'duration_days' => $attrs['duration_days'],
                    'grace_period_days' => $attrs['grace_period_days'],
                    'leftover_quota_carry_window_days' => $attrs['leftover_quota_carry_window_days'],
                    'sort_order' => $sort,
                    'highlight' => $attrs['highlight'],
                    'is_active' => true,
                    'is_visible' => true,
                    'gst_inclusive' => true,
                    'default_billing_key' => $attrs['default_billing_key'],
                    'marketing_badge' => $attrs['marketing_badge'],
                ];

                if (! Schema::hasColumn('plans', 'name_mr')) {
                    unset($row['name_mr']);
                }

                $plan = Plan::query()->create($row);
                self::syncQuotaPolicies($plan, $quotas[$tier]);
                self::syncMirroredAndExtraFeatures($plan->fresh(['quotaPolicies']), $tier);
                self::syncTerms($plan, $tier);
                $plan->forgetCachedPlanFeatures();
            }
        }
    }
}
