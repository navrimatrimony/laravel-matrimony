<?php

namespace App\Support;

/**
 * Keys for {@see \App\Models\UserFeatureUsage} / {@see \App\Services\UserFeatureUsageService}.
 */
final class UserFeatureUsageKeys
{
    public const CONTACT_VIEW = 'contact_view';

    public const MEDIATOR_REQUEST = 'mediator_request';

    /** Daily bucket; paired with {@see \App\Support\PlanFeatureKeys::INTEREST_SEND_LIMIT}. */
    public const INTEREST_SEND = 'interest_send';

    /** Daily counter: new profile rows recorded for {@see \App\Services\ProfilePhotoAccessService} (no-upload tier). */
    public const PHOTO_VIEW = 'photo_view';
}
