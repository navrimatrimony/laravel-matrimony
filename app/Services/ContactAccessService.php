<?php

namespace App\Services;

use App\Models\ContactRequest;
use App\Models\MatrimonyProfile;
use App\Models\MediationRequest;
use App\Models\User;
use App\Models\UserContactRevealLog;
use App\Support\PlanFeatureKeys;
use App\Support\UserFeatureUsageKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Contact visibility + unlock persistence ({@see UserContactRevealLog}).
 *
 * Monthly contact quota: {@see FeatureUsageService::canUse}(..., {@see FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT}) — do not duplicate limit math here.
 * Reveal charge: {@see FeatureUsageService::consume}(..., {@see FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT}) once per new viewer+profile+month (deduped via log).
 *
 * Visibility: paid_allowed (reveal/credits; may require assisted matchmaking "interested" — see config), request_only ({@see ContactRequestService}), no_one (mediator emphasis).
 */
class ContactAccessService
{
    public const CASE_PAID_ALLOWED = 'paid_allowed';

    public const CASE_REQUEST_ONLY = 'request_only';

    public const CASE_NO_ONE = 'no_one';

    public function __construct(
        protected EntitlementService $entitlements,
        protected UserFeatureUsageService $usage,
        protected FeatureUsageService $featureUsage,
        protected ContactRevealPolicyService $contactRevealPolicy,
    ) {}

    /**
     * Placeholder for own-profile views (no viewer gating).
     *
     * @return array<string, mixed>
     */
    public static function neutralForOwner(): array
    {
        return [
            'has_contact_unlock' => true,
            'blocked' => false,
            'visibility_case' => null,
            'allows_direct_contact_reveal' => false,
            'contact_view_limit' => -1,
            'contact_view_used' => 0,
            'contact_view_remaining' => null,
            'has_contact_view_credit' => true,
            'no_contact_credits_left' => false,
            'mediator_limit' => -1,
            'mediator_used' => 0,
            'mediator_remaining' => null,
            'has_mediator_credit' => true,
            'show_contact_request_rail' => false,
            'show_paid_reveal_button' => false,
            'show_mediator_cta' => false,
            'show_no_one_copy' => false,
            'needs_upgrade' => false,
            'needs_upgrade_for_mediator' => false,
            'paid_contact_phone' => null,
            'paid_contact_email' => null,
            'reveal_blocked_reason' => null,
            'plans_url' => route('plans.index'),
            'paid_reveal_blocked_pending_matchmaking' => false,
        ];
    }

    /**
     * @param  object|null  $visibilitySettings  Row from profile_visibility_settings
     * @param  array|null  $contactGrantReveal  ['phone' => ...] when grant valid
     * @return array<string, mixed>
     */
    public function resolveViewerContext(
        User $viewer,
        MatrimonyProfile $targetProfile,
        bool $interestAllowsContact,
        ?object $visibilitySettings,
        ?array $contactGrantReveal,
    ): array {
        $uid = (int) $viewer->id;
        $plansUrl = route('plans.index');

        if ($this->featureUsage->shouldBypassUsageLimits($viewer)) {
            $case = $this->contactRevealPolicy->resolveVisibilityCase($targetProfile, $visibilitySettings);
            $phone = trim((string) ($targetProfile->primary_contact_number ?? ''));
            $email = $this->revealedEmailForTarget($targetProfile);

            return [
                'has_contact_unlock' => true,
                'blocked' => false,
                'visibility_case' => $case,
                'allows_direct_contact_reveal' => $case === self::CASE_PAID_ALLOWED,
                'contact_view_limit' => -1,
                'contact_view_used' => 0,
                'contact_view_remaining' => null,
                'has_contact_view_credit' => true,
                'no_contact_credits_left' => false,
                'mediator_limit' => -1,
                'mediator_used' => 0,
                'mediator_remaining' => null,
                'has_mediator_credit' => true,
                'show_contact_request_rail' => $case !== self::CASE_NO_ONE,
                'show_paid_reveal_button' => false,
                'show_mediator_cta' => false,
                'show_no_one_copy' => $case === self::CASE_NO_ONE,
                'needs_upgrade' => false,
                'needs_upgrade_for_mediator' => false,
                'paid_contact_phone' => $phone !== '' ? $phone : null,
                'paid_contact_email' => $email,
                'reveal_blocked_reason' => null,
                'plans_url' => $plansUrl,
                'already_revealed_this_month' => true,
                'paid_reveal_blocked_pending_matchmaking' => false,
            ];
        }

        // Must match {@see FeatureUsageService::canUse}(..., FEATURE_CONTACT_VIEW_LIMIT): subscription plan limit ≠ 0 (entitlement row for contact_unlock may be missing while plan_features still grants contact_view_limit).
        $hasUnlock = $this->planGrantsContactRevealFromSubscription($viewer);

        if (! $hasUnlock) {
            $case = $this->contactRevealPolicy->resolveVisibilityCase($targetProfile, $visibilitySettings);

            return [
                'has_contact_unlock' => false,
                'blocked' => true,
                'visibility_case' => $case,
                'allows_direct_contact_reveal' => false,
                'contact_view_limit' => 0,
                'contact_view_used' => 0,
                'contact_view_remaining' => 0,
                'has_contact_view_credit' => false,
                'no_contact_credits_left' => false,
                'mediator_limit' => 0,
                'mediator_used' => 0,
                'mediator_remaining' => 0,
                'has_mediator_credit' => false,
                'show_contact_request_rail' => false,
                'show_paid_reveal_button' => false,
                'show_mediator_cta' => $this->contactRevealPolicy->allowsMediatorPath($targetProfile, $visibilitySettings),
                'show_no_one_copy' => $case === self::CASE_NO_ONE,
                'needs_upgrade' => true,
                'needs_upgrade_for_mediator' => true,
                'paid_contact_phone' => null,
                'paid_contact_email' => null,
                'reveal_blocked_reason' => null,
                'plans_url' => $plansUrl,
                'paid_reveal_blocked_pending_matchmaking' => false,
            ];
        }

        $case = $this->contactRevealPolicy->resolveVisibilityCase($targetProfile, $visibilitySettings);

        $mayRevealForPaid = $this->contactRevealPolicy->viewerMaySeePaidRevealButton(
            $interestAllowsContact,
            $visibilitySettings,
            $targetProfile
        );

        $cvLimit = $this->featureUsage->getPlanFeatureLimit($viewer, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
        $cvUsed = $this->usage->getUsage($uid, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT);
        $cvRemaining = $this->remaining($cvLimit, $cvUsed);

        $medLimit = $this->parseNumericLimit($uid, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH);
        $medUsed = $this->usage->getUsage($uid, UserFeatureUsageKeys::MEDIATOR_REQUEST);
        $medRemaining = $this->remaining($medLimit, $medUsed);

        $hasCvCredit = $this->featureUsage->canUse($uid, FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT);

        $hasMedCredit = $medLimit === -1 || ($medLimit > 0 && $medUsed < $medLimit);

        $periodStart = $this->usage->resolvePeriodStart(
            \App\Models\UserFeatureUsage::PERIOD_MONTHLY,
            Carbon::now()
        )->toDateString();

        $alreadyRevealedThisMonth = $this->hasRevealedThisMonth($uid, $targetProfile->id, $periodStart);

        $paidRevealNeedsMatchmaking = $this->contactRevealPolicy->requiresMatchmakingInterestedForPaidReveal($visibilitySettings);
        $matchmakingInterestedOk = ! $paidRevealNeedsMatchmaking || $this->viewerHasMatchmakingInterested($viewer, $targetProfile);

        $grantPhone = null;
        if (! empty($contactGrantReveal['phone'])) {
            $grantPhone = trim((string) $contactGrantReveal['phone']);
            if ($grantPhone === '') {
                $grantPhone = null;
            }
        }
        $grantEmail = null;
        if (! empty($contactGrantReveal['email'])) {
            $grantEmail = trim((string) $contactGrantReveal['email']);
            if ($grantEmail === '') {
                $grantEmail = null;
            }
        }

        $paidPhone = null;
        $paidEmail = null;
        $showPaidReveal = false;
        $showRequestRail = false;
        $showNoOne = false;

        $primaryPhone = trim((string) ($targetProfile->primary_contact_number ?? ''));
        $profileEmail = $this->revealedEmailForTarget($targetProfile);
        $hasAnyContactPayload = $primaryPhone !== '' || $profileEmail !== null;

        if ($case === self::CASE_NO_ONE) {
            $showNoOne = true;
            $showRequestRail = false;
        } elseif ($case === self::CASE_REQUEST_ONLY) {
            $showRequestRail = true;
        } else {
            // paid_allowed: show number/email only after billed reveal (or grant path below)
            if ($mayRevealForPaid && $alreadyRevealedThisMonth) {
                $paidPhone = $primaryPhone !== '' ? $primaryPhone : null;
                $paidEmail = $profileEmail;
            } elseif ($mayRevealForPaid && $hasCvCredit && $matchmakingInterestedOk && $hasAnyContactPayload) {
                $showPaidReveal = true;
            }
        }

        if ($grantPhone !== null || $grantEmail !== null) {
            $paidPhone = $grantPhone;
            $paidEmail = $grantEmail;
            $showPaidReveal = false;
        }

        $revealBlockedReason = $this->contactRevealPolicy->paidRevealBlockedReason(
            $interestAllowsContact,
            $viewer,
            $visibilitySettings,
            $targetProfile
        );
        if ($case === self::CASE_PAID_ALLOWED && $mayRevealForPaid && ! $revealBlockedReason && ! $hasAnyContactPayload) {
            $revealBlockedReason = 'no_contact';
        }

        $noContactCreditsLeft = $case === self::CASE_PAID_ALLOWED
            && $mayRevealForPaid
            && ! $alreadyRevealedThisMonth
            && $grantPhone === null
            && $grantEmail === null
            && ! $hasCvCredit
            && $hasAnyContactPayload;

        $paidRevealBlockedByMatchmaking = $case === self::CASE_PAID_ALLOWED
            && $mayRevealForPaid
            && $hasCvCredit
            && ! $matchmakingInterestedOk
            && $grantPhone === null
            && $grantEmail === null
            && ! $alreadyRevealedThisMonth
            && $hasAnyContactPayload;

        $showMediatorCta = $this->contactRevealPolicy->allowsMediatorPath($targetProfile, $visibilitySettings);

        return [
            'has_contact_unlock' => true,
            'blocked' => false,
            'visibility_case' => $case,
            'allows_direct_contact_reveal' => $case === self::CASE_PAID_ALLOWED && $mayRevealForPaid,
            'contact_view_limit' => $cvLimit,
            'contact_view_used' => $cvUsed,
            'contact_view_remaining' => $cvRemaining,
            'has_contact_view_credit' => $hasCvCredit,
            'no_contact_credits_left' => $noContactCreditsLeft,
            'mediator_limit' => $medLimit,
            'mediator_used' => $medUsed,
            'mediator_remaining' => $medRemaining,
            'has_mediator_credit' => $hasMedCredit,
            'show_contact_request_rail' => $showRequestRail,
            'show_paid_reveal_button' => $showPaidReveal,
            'show_mediator_cta' => $showMediatorCta,
            'show_no_one_copy' => $showNoOne,
            'needs_upgrade' => false,
            'needs_upgrade_for_mediator' => ! $hasMedCredit,
            'paid_contact_phone' => $paidPhone,
            'paid_contact_email' => $paidEmail,
            'reveal_blocked_reason' => $revealBlockedReason,
            'plans_url' => $plansUrl,
            'already_revealed_this_month' => $alreadyRevealedThisMonth,
            'paid_reveal_blocked_pending_matchmaking' => $paidRevealBlockedByMatchmaking,
        ];
    }

    /**
     * Validates paid reveal, creates {@see UserContactRevealLog} when needed; does not increment usage.
     * Call {@see FeatureUsageService::consume}(..., {@see FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT}) in the same DB transaction when {@code consume_credit} is true.
     *
     * @return array{phone: string, email: ?string, consume_credit: bool}
     *
     * @throws InvalidArgumentException
     */
    public function performPaidContactRevealBilling(User $viewer, MatrimonyProfile $targetProfile, ?object $visibilitySettings): array
    {
        $uid = (int) $viewer->id;
        $targetProfile->loadMissing('user');
        $phone = trim((string) ($targetProfile->primary_contact_number ?? ''));
        $email = $this->revealedEmailForTarget($targetProfile);

        if ($this->featureUsage->shouldBypassUsageLimits($viewer)) {
            if ($phone === '' && $email === null) {
                throw new InvalidArgumentException(__('contact_access.no_contact_on_profile'));
            }

            return ['phone' => $phone, 'email' => $email, 'consume_credit' => false];
        }

        if (! $this->planGrantsContactRevealFromSubscription($viewer)) {
            throw new InvalidArgumentException(__('contact_access.upgrade_required'));
        }

        $case = $this->contactRevealPolicy->resolveVisibilityCase($targetProfile, $visibilitySettings);
        if ($case !== self::CASE_PAID_ALLOWED) {
            throw new InvalidArgumentException(__('contact_access.reveal_not_allowed'));
        }

        if ($this->contactRevealPolicy->requiresPremiumViewer($visibilitySettings)
            && ! $this->contactRevealPolicy->viewerHasPaidSubscription($viewer)) {
            throw new InvalidArgumentException(__('contact_access.premium_viewer_required'));
        }

        if ($this->contactRevealPolicy->requiresAcceptedInterest($visibilitySettings)) {
            if (! $this->viewerHasAcceptedInterestWith($viewer, $targetProfile)) {
                throw new InvalidArgumentException(__('contact_access.interest_required'));
            }
            if ($this->contactRevealPolicy->requiresMatchmakingInterestedForPaidReveal($visibilitySettings)
                && ! $this->viewerHasMatchmakingInterested($viewer, $targetProfile)) {
                throw new InvalidArgumentException(__('contact_access.matchmaking_interested_required'));
            }
        }

        if ($phone === '' && $email === null) {
            throw new InvalidArgumentException(__('contact_access.no_contact_on_profile'));
        }

        $periodStart = $this->usage->resolvePeriodStart(
            \App\Models\UserFeatureUsage::PERIOD_MONTHLY,
            Carbon::now()
        )->toDateString();

        if ($this->hasRevealedThisMonth($uid, $targetProfile->id, $periodStart)) {
            return ['phone' => $phone, 'email' => $email, 'consume_credit' => false];
        }

        $limit = $this->featureUsage->getPlanFeatureLimit($viewer, PlanFeatureKeys::CONTACT_VIEW_LIMIT);

        $log = UserContactRevealLog::query()->firstOrCreate(
            [
                'viewer_user_id' => $uid,
                'viewed_profile_id' => $targetProfile->id,
                'period_start' => $periodStart,
            ],
            []
        );
        $consumeCredit = $log->wasRecentlyCreated && $limit !== -1;

        return ['phone' => $phone, 'email' => $email, 'consume_credit' => $consumeCredit];
    }

    /**
     * Consume one contact_view_limit credit and log dedup for this profile/month (usage + log in one transaction).
     *
     * @return array{phone: string, email: ?string}
     *
     * @throws InvalidArgumentException
     */
    public function consumePaidContactReveal(User $viewer, MatrimonyProfile $targetProfile, ?object $visibilitySettings): array
    {
        return DB::transaction(function () use ($viewer, $targetProfile, $visibilitySettings) {
            $result = $this->performPaidContactRevealBilling($viewer, $targetProfile, $visibilitySettings);
            if ($result['consume_credit']) {
                app(FeatureUsageService::class)->consume((int) $viewer->id, FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT);
            }

            return [
                'phone' => $result['phone'],
                'email' => $result['email'],
            ];
        });
    }

    /**
     * Assert viewer may use mediator quota (does not increment usage).
     *
     * @throws InvalidArgumentException
     */
    public function assertMediatorAllowed(User $viewer): void
    {
        $uid = (int) $viewer->id;
        if ($this->featureUsage->shouldBypassUsageLimits($viewer)) {
            return;
        }

        $medLimit = $this->parseNumericLimit($uid, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH);
        $contactRevealOk = $this->planGrantsContactRevealFromSubscription($viewer);
        $hasMediatorFeature = $medLimit !== 0;

        if (! $hasMediatorFeature && ! $contactRevealOk) {
            throw new InvalidArgumentException(__('contact_access.upgrade_required'));
        }

        if (! $hasMediatorFeature && $contactRevealOk) {
            throw new InvalidArgumentException(__('contact_access.no_mediator_credits'));
        }

        $used = $this->usage->getUsage($uid, UserFeatureUsageKeys::MEDIATOR_REQUEST);
        if ($medLimit !== -1 && $used >= $medLimit) {
            throw new InvalidArgumentException(__('contact_access.no_mediator_credits'));
        }
    }

    /**
     * Record one mediator credit use (no entitlement re-check). Call after a mediation row is created.
     */
    public function incrementMediatorUsage(User $viewer): void
    {
        if ($this->featureUsage->shouldBypassUsageLimits($viewer)) {
            return;
        }

        $limit = $this->parseNumericLimit((int) $viewer->id, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH);
        if ($limit !== -1) {
            $this->usage->incrementUsage((int) $viewer->id, UserFeatureUsageKeys::MEDIATOR_REQUEST);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function consumeMediatorRequest(User $viewer): void
    {
        $this->assertMediatorAllowed($viewer);
        $this->incrementMediatorUsage($viewer);
    }

    public function resolveVisibilityCase(MatrimonyProfile $target, ?object $visibilitySettings): string
    {
        return $this->contactRevealPolicy->resolveVisibilityCase($target, $visibilitySettings);
    }

    /**
     * Paid contact reveal (credit) may require assisted matchmaking: viewer sent type=mediator and receiver chose interested.
     */
    private function viewerHasMatchmakingInterested(User $viewer, MatrimonyProfile $targetProfile): bool
    {
        $tid = (int) $targetProfile->id;

        return MediationRequest::query()
            ->where('sender_id', $viewer->id)
            ->where(function ($q) use ($tid) {
                $q->where('receiver_profile_id', $tid)
                    ->orWhere('subject_profile_id', $tid);
            })
            ->where('status', ContactRequest::STATUS_INTERESTED)
            ->exists();
    }

    private function viewerHasAcceptedInterestWith(User $viewer, MatrimonyProfile $target): bool
    {
        $vp = $viewer->matrimonyProfile;
        if (! $vp) {
            return false;
        }
        $a = (int) $vp->id;
        $b = (int) $target->id;

        return \App\Models\Interest::query()
            ->where('status', 'accepted')
            ->where(function ($q) use ($a, $b) {
                $q->where(function ($q2) use ($a, $b) {
                    $q2->where('sender_profile_id', $a)->where('receiver_profile_id', $b);
                })->orWhere(function ($q2) use ($a, $b) {
                    $q2->where('sender_profile_id', $b)->where('receiver_profile_id', $a);
                });
            })
            ->exists();
    }

    private function hasRevealedThisMonth(int $viewerUserId, int $viewedProfileId, string $periodStart): bool
    {
        return UserContactRevealLog::query()
            ->where('viewer_user_id', $viewerUserId)
            ->where('viewed_profile_id', $viewedProfileId)
            ->whereDate('period_start', $periodStart)
            ->exists();
    }

    /**
     * True when the viewer's effective plan grants contact reveals (same source as {@see FeatureUsageService::getPlanFeatureLimit}(..., {@see PlanFeatureKeys::CONTACT_VIEW_LIMIT})).
     * 0 = no access; positive cap or -1 unlimited.
     */
    private function planGrantsContactRevealFromSubscription(User $viewer): bool
    {
        if ($this->featureUsage->shouldBypassUsageLimits($viewer)) {
            return true;
        }

        $lim = $this->featureUsage->getPlanFeatureLimit($viewer, PlanFeatureKeys::CONTACT_VIEW_LIMIT);

        return $lim !== 0;
    }

    /**
     * -1 = unlimited, 0 = none, n = cap.
     */
    private function parseNumericLimit(int $userId, string $featureKey): int
    {
        $raw = $this->entitlements->getValue($userId, $featureKey, '0');
        $s = strtolower(trim((string) $raw));
        if ($s === '' || $s === '-1' || $s === 'unlimited') {
            return -1;
        }

        return max(0, (int) $raw);
    }

    /**
     * @return int|null null means unlimited
     */
    private function remaining(int $limit, int $used): ?int
    {
        if ($limit === -1) {
            return null;
        }

        return max(0, $limit - $used);
    }

    /**
     * Login email for the profile owner (same trust boundary as paid phone reveal). Omits synthetic system addresses.
     */
    private function revealedEmailForTarget(MatrimonyProfile $target): ?string
    {
        $target->loadMissing('user');
        $e = trim((string) ($target->user?->email ?? ''));
        if ($e === '' || str_ends_with($e, '@system.local')) {
            return null;
        }

        return $e;
    }
}
