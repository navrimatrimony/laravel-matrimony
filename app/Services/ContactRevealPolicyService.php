<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ContactAccessService as CAS;

/**
 * Single source of truth for who may unlock contact (number/email) on a target profile and under what conditions.
 *
 * Drives {@see ContactAccessService} GET context + POST billing (no duplicate rules elsewhere).
 */
class ContactRevealPolicyService
{
    public const SHOW_CONTACT_EVERYONE = 'everyone';

    public const SHOW_CONTACT_PREMIUM_ONLY = 'premium_only';

    public const SHOW_CONTACT_ACCEPTED_INTEREST = 'accepted_interest';

    public const SHOW_CONTACT_UNLOCK_ONLY = 'unlock_only';

    public const SHOW_CONTACT_NO_ONE = 'no_one';

    public function __construct(
        protected SubscriptionService $subscriptions,
    ) {}

    /**
     * Normalize DB value; missing row ⇒ {@see self::SHOW_CONTACT_EVERYONE} (registration default intent).
     */
    public function normalizedShowContactTo(?object $visibilitySettings): string
    {
        $raw = $visibilitySettings->show_contact_to ?? self::SHOW_CONTACT_EVERYONE;
        $raw = is_string($raw) ? trim($raw) : '';

        return $raw !== '' ? $raw : self::SHOW_CONTACT_EVERYONE;
    }

    /**
     * Same case split as {@see ContactAccessService::CASE_*} — visibility-only (not quota).
     */
    public function resolveVisibilityCase(MatrimonyProfile $target, ?object $visibilitySettings): string
    {
        $mode = $target->contact_unlock_mode ?? 'after_interest_accepted';
        if (in_array($mode, ['never', 'admin_only'], true)) {
            return CAS::CASE_NO_ONE;
        }

        $showTo = $this->normalizedShowContactTo($visibilitySettings);

        if ($showTo === self::SHOW_CONTACT_NO_ONE) {
            return CAS::CASE_NO_ONE;
        }

        if ($showTo === self::SHOW_CONTACT_UNLOCK_ONLY) {
            return CAS::CASE_REQUEST_ONLY;
        }

        return CAS::CASE_PAID_ALLOWED;
    }

    public function requiresAcceptedInterest(?object $visibilitySettings): bool
    {
        return $this->normalizedShowContactTo($visibilitySettings) === self::SHOW_CONTACT_ACCEPTED_INTEREST;
    }

    public function requiresPremiumViewer(?object $visibilitySettings): bool
    {
        return $this->normalizedShowContactTo($visibilitySettings) === self::SHOW_CONTACT_PREMIUM_ONLY;
    }

    /**
     * Matchmaking “interested” gate applies only when interest is part of the policy (not for everyone/premium-only unlock paths).
     */
    public function requiresMatchmakingInterestedForPaidReveal(?object $visibilitySettings): bool
    {
        if (! $this->requiresAcceptedInterest($visibilitySettings)) {
            return false;
        }

        return (bool) config('communication.paid_contact_reveal_requires_matchmaking_interested', true);
    }

    /**
     * Viewer has an active paid subscription (not implicit free-plan fallback only).
     */
    public function viewerHasPaidSubscription(User $viewer): bool
    {
        return Subscription::query()
            ->where('user_id', $viewer->id)
            ->effectivelyActiveForAccess()
            ->exists();
    }

    /**
     * For UI: may we show the paid “View Contact” path (before quota), given interest state?
     */
    public function viewerMaySeePaidRevealButton(bool $hasAcceptedInterest, ?object $visibilitySettings, MatrimonyProfile $target): bool
    {
        if ($this->resolveVisibilityCase($target, $visibilitySettings) !== CAS::CASE_PAID_ALLOWED) {
            return false;
        }

        if ($this->requiresAcceptedInterest($visibilitySettings)) {
            return $hasAcceptedInterest;
        }

        return true;
    }

    /**
     * Assisted matchmaking is blocked only when the profile hard-disables all contact paths.
     */
    public function allowsMediatorPath(MatrimonyProfile $target, ?object $visibilitySettings): bool
    {
        $mode = $target->contact_unlock_mode ?? 'after_interest_accepted';

        return $mode !== 'never';
    }

    /**
     * Why the paid "View Contact" CTA is hidden while the case is still {@see CAS::CASE_PAID_ALLOWED} (for messaging).
     *
     * @return 'interest'|'premium'|null
     */
    public function paidRevealBlockedReason(
        bool $hasAcceptedInterest,
        User $viewer,
        ?object $visibilitySettings,
        MatrimonyProfile $target,
    ): ?string {
        if ($this->resolveVisibilityCase($target, $visibilitySettings) !== CAS::CASE_PAID_ALLOWED) {
            return null;
        }

        if (! $this->viewerMaySeePaidRevealButton($hasAcceptedInterest, $visibilitySettings, $target)) {
            return 'interest';
        }

        if ($this->requiresPremiumViewer($visibilitySettings) && ! $this->viewerHasPaidSubscription($viewer)) {
            return 'premium';
        }

        return null;
    }
}
