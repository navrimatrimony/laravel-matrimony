<?php

namespace App\Support;

use App\Models\MatrimonyProfile;
use App\Services\FeatureFlagService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Showcase-module consumer of the generic FeatureFlagService.
 * Identity checks (is_showcase) stay on the profile; this only answers
 * "is the Showcase module allowed to run?".
 */
final class ShowcaseFeatureGate
{
    public static function isEnabled(): bool
    {
        return app(FeatureFlagService::class)->isEnabled(FeatureFlagKey::SHOWCASE_PROFILES);
    }

    public static function abortIfDisabledProfile(?MatrimonyProfile $profile): void
    {
        if ($profile && $profile->isShowcaseProfile() && ! self::isEnabled()) {
            abort(404, 'Feature Disabled');
        }
    }

    public static function excludeShowcaseWhenDisabled(Builder $query): void
    {
        if (! self::isEnabled()) {
            $query->whereNonShowcase();
        }
    }
}
