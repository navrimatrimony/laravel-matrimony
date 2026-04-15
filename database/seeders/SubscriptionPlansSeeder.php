<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanQuotaPolicy;
use App\Models\PlanTerm;
use App\Services\SubscriptionService;
use App\Support\PlanFeatureKeys;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resets catalog plans to gendered tiers: free/basic/silver/gold × male/female.
 * Slugs: {@code free_male}, {@code silver_female}, etc. Female numeric quotas are ~2× male (same prices).
 */
class SubscriptionPlansSeeder extends Seeder
{
    /**
     * @param  array<string, string>  $features
     * @return array<string, string>
     */
    private static function doublePositiveNumericQuotas(array $features): array
    {
        $skipKeys = [
            PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD,
        ];
        $out = $features;
        foreach ($out as $k => $v) {
            if (in_array($k, $skipKeys, true)) {
                continue;
            }
            $s = trim((string) $v);
            if ($s === '' || $s === '-1') {
                continue;
            }
            if (! preg_match('/^-?\d+$/', $s)) {
                continue;
            }
            $n = (int) $s;
            if ($n <= 0) {
                continue;
            }
            $out[$k] = (string) min(999999, $n * 2);
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function zeroFeatureBase(): array
    {
        return array_fill_keys(PlanFeatureKeys::all(), '0');
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function maleTierFeatures(): array
    {
        $z = self::zeroFeatureBase();

        $free = array_merge($z, [
            PlanFeatureKeys::CHAT_SEND_LIMIT => '5',
            PlanFeatureKeys::CHAT_CAN_READ => '0',
            PlanFeatureKeys::CHAT_INITIATE_NEW_CHATS_ONLY => '0',
            PlanFeatureKeys::INTEREST_SEND_LIMIT => '3',
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => '3',
            PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD => 'monthly',
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => '0',
            PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT => '5',
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => '2',
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => '0',
            PlanFeatureKeys::PHOTO_FULL_ACCESS => '0',
            PlanFeatureKeys::PHOTO_BLUR_LIMIT => '0',
            PlanFeatureKeys::ADVANCED_PROFILE_SEARCH => '0',
            PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT => '0',
            PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => '0',
            PlanFeatureKeys::PRIORITY_LISTING => '0',
            PlanFeatureKeys::REFERRAL_BONUS_DAYS => '0',
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => '50',
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '0',
        ]);

        $basic = array_merge($free, [
            PlanFeatureKeys::CHAT_SEND_LIMIT => '25',
            PlanFeatureKeys::INTEREST_SEND_LIMIT => '15',
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => '200',
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => '-1',
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '0',
            PlanFeatureKeys::PHOTO_FULL_ACCESS => '1',
            PlanFeatureKeys::CHAT_CAN_READ => '1',
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => '1',
            PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT => '0',
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => '12',
        ]);

        $silver = array_merge($free, [
            PlanFeatureKeys::CHAT_SEND_LIMIT => '100',
            PlanFeatureKeys::INTEREST_SEND_LIMIT => '50',
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => '-1',
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => '-1',
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
            PlanFeatureKeys::PHOTO_FULL_ACCESS => '1',
            PlanFeatureKeys::CHAT_CAN_READ => '1',
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => '7',
            PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT => '0',
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => '30',
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => '5',
            PlanFeatureKeys::ADVANCED_PROFILE_SEARCH => '1',
            PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT => '0',
        ]);

        $gold = array_merge($silver, [
            PlanFeatureKeys::CHAT_SEND_LIMIT => '-1',
            PlanFeatureKeys::INTEREST_SEND_LIMIT => '-1',
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT => '-1',
            PlanFeatureKeys::CONTACT_VIEW_LIMIT => '-1',
            PlanFeatureKeys::INTEREST_VIEW_LIMIT => '-1',
            SubscriptionService::FEATURE_CHAT_IMAGE_MESSAGES => '1',
            PlanFeatureKeys::PHOTO_FULL_ACCESS => '1',
            PlanFeatureKeys::PROFILE_BOOST_PER_WEEK => '1',
            PlanFeatureKeys::PRIORITY_LISTING => '1',
            PlanFeatureKeys::WHO_VIEWED_ME_DAYS => '999',
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH => '15',
            PlanFeatureKeys::ADVANCED_PROFILE_SEARCH => '1',
            PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT => '1',
        ]);

        return [
            'free' => $free,
            'basic' => $basic,
            'silver' => $silver,
            'gold' => $gold,
        ];
    }

    public function run(): void
    {
        if (Schema::hasTable('subscriptions')) {
            DB::table('subscriptions')->delete();
        }

        foreach (Plan::query()->cursor() as $existing) {
            $existing->delete();
        }

        $maleTiers = self::maleTierFeatures();

        $paidPrices = [
            'basic' => 999.0,
            'silver' => 2499.0,
            'gold' => 4999.0,
        ];

        $defs = [];
        foreach (['male', 'female'] as $gender) {
            $suffix = $gender;
            $applies = $gender;
            foreach (['free', 'basic', 'silver', 'gold'] as $tier) {
                $slug = $tier.'_'.$suffix;
                $name = ucfirst($tier).' ('.ucfirst($gender).')';
                $isFree = $tier === 'free';
                $price = $isFree ? 0.0 : ($paidPrices[$tier] ?? 0.0);
                $sort = ($gender === 'male' ? 0 : 100) + match ($tier) {
                    'free' => 10,
                    'basic' => 20,
                    'silver' => 30,
                    'gold' => 40,
                    default => 0,
                };
                $features = $maleTiers[$tier];
                if ($gender === 'female') {
                    $features = self::doublePositiveNumericQuotas($features);
                }
                $defs[] = [
                    'name' => $name,
                    'slug' => $slug,
                    'applies_to_gender' => $applies,
                    'price' => $price,
                    'list_price_rupees' => $isFree ? null : (int) round($price),
                    'discount_percent' => null,
                    'duration_days' => $isFree ? 0 : 30,
                    'grace_period_days' => 3,
                    'leftover_quota_carry_window_days' => null,
                    'sort_order' => $sort,
                    'highlight' => $tier === 'gold',
                    'is_active' => true,
                    'is_visible' => true,
                    'gst_inclusive' => true,
                    'default_billing_key' => $isFree ? null : PlanTerm::BILLING_MONTHLY,
                    'marketing_badge' => $tier === 'gold' ? 'recommended' : null,
                    'features' => $features,
                ];
            }
        }

        foreach ($defs as $row) {
            $features = $row['features'];
            unset($row['features']);

            $plan = Plan::query()->create($row);

            foreach ($features as $key => $value) {
                PlanFeature::query()->create([
                    'plan_id' => $plan->id,
                    'key' => $key,
                    'value' => (string) $value,
                ]);
            }

            PlanQuotaPolicy::query()->where('plan_id', $plan->id)->delete();
            PlanQuotaPolicy::ensureAllForPlan($plan->fresh('features'));

            if (! Plan::isFreeCatalogSlug((string) $plan->slug)) {
                PlanTerm::syncDefaultsForPlan($plan->fresh());
            } else {
                PlanTerm::query()->where('plan_id', $plan->id)->delete();
            }

            $plan->forgetCachedPlanFeatures();
        }
    }
}
