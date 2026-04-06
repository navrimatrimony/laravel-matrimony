<?php

namespace App\Support;

/**
 * Keys for {@see \App\Models\UserFeatureUsage} (`user_feature_usages`) / {@see \App\Services\UserFeatureUsageService}.
 * Limit-style keys match {@see PlanFeatureKeys} (e.g. interest_send_limit, contact_view_limit).
 */
final class UserFeatureUsageKeys
{
    /** Monthly bucket; same string as {@see PlanFeatureKeys::CONTACT_VIEW_LIMIT}. */
    public const CONTACT_VIEW_LIMIT = PlanFeatureKeys::CONTACT_VIEW_LIMIT;

    public const MEDIATOR_REQUEST = 'mediator_request';

    /** Daily bucket; same string as {@see PlanFeatureKeys::INTEREST_SEND_LIMIT}. */
    public const INTEREST_SEND_LIMIT = PlanFeatureKeys::INTEREST_SEND_LIMIT;

    /** Daily counter: new profile rows recorded for {@see \App\Services\ProfilePhotoAccessService} (no-upload tier). */
    public const PHOTO_VIEW = 'photo_view';
}
