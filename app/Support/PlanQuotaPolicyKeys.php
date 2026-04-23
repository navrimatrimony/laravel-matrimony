<?php

namespace App\Support;

use App\Services\FeatureUsageService;
use App\Services\SubscriptionService;

/**
 * Plan admin quota cards: single source for policy rows + mirrored {@see \App\Models\PlanFeature} values.
 *
 * @phpstan-import-type PlanFeatureKey from PlanFeatureKeys
 */
final class PlanQuotaPolicyKeys
{
    /**
     * Display / persistence order (matches product priority for admins).
     *
     * @return list<string>
     */
    public static function ordered(): array
    {
        return [
            PlanFeatureKeys::CHAT_SEND_LIMIT,
            PlanFeatureKeys::CHAT_CAN_READ,
            PlanFeatureKeys::CONTACT_VIEW_LIMIT,
            PlanFeatureKeys::INTEREST_SEND_LIMIT,
            PlanFeatureKeys::INTEREST_VIEW_LIMIT,
            SubscriptionService::FEATURE_DAILY_PROFILE_VIEW_LIMIT,
            PlanFeatureKeys::WHO_VIEWED_ME_PREVIEW_LIMIT,
            PlanFeatureKeys::PHOTO_FULL_ACCESS,
            PlanFeatureKeys::PROFILE_BOOST_PER_WEEK,
            PlanFeatureKeys::PRIORITY_LISTING,
            PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH,
            PlanFeatureKeys::ADVANCED_PROFILE_SEARCH,
            PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT,
        ];
    }

    /**
     * Admin uses the full quota card (refresh, limit, grace, pack) for every policy row.
     * Runtime {@see \App\Models\PlanFeature} for these keys stays boolean (0/1): only {@code is_enabled} is mirrored.
     */
    public static function mirrorsPlanFeatureAsBooleanOnly(string $featureKey): bool
    {
        return match ($featureKey) {
            PlanFeatureKeys::CHAT_CAN_READ,
            PlanFeatureKeys::PHOTO_FULL_ACCESS,
            PlanFeatureKeys::PRIORITY_LISTING,
            PlanFeatureKeys::ADVANCED_PROFILE_SEARCH,
            PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT => true,
            default => false,
        };
    }

    /**
     * Plan admin: rendered as one row of plain checkboxes (no refresh/limit/pack UI).
     *
     * @return list<string>
     */
    public static function adminSimpleBooleanToggleKeys(): array
    {
        return [
            PlanFeatureKeys::ADVANCED_PROFILE_SEARCH,
            PlanFeatureKeys::PROFILE_WHATSAPP_DIRECT,
        ];
    }

    /**
     * @return list<string>
     */
    public static function planFeatureKeysWrittenByPolicies(): array
    {
        $keys = self::ordered();
        $keys[] = PlanFeatureKeys::INTEREST_VIEW_RESET_PERIOD;
        $keys[] = FeatureUsageService::FEATURE_WHO_VIEWED_ME_ACCESS;
        $keys[] = PlanFeatureKeys::CHAT_INITIATE_NEW_CHATS_ONLY;

        return array_values(array_unique($keys));
    }

    /**
     * Keys that must never be resolved via {@see \App\Models\Plan::getFeatureValue} / {@see \App\Models\Plan::featureValue}.
     *
     * @return list<string>
     */
    public static function forbiddenPlanFeatureRowKeys(): array
    {
        return self::planFeatureKeysWrittenByPolicies();
    }

    public static function isForbiddenPlanFeatureRowKey(string $normalizedKey): bool
    {
        return in_array($normalizedKey, self::forbiddenPlanFeatureRowKeys(), true);
    }
}
