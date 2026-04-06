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
 * Contact access via {@see EntitlementService} + {@see UserFeatureUsageService}.
 *
 * Eligibility: {@see EntitlementService::hasFeature}(userId, {@see PlanFeatureKeys::CONTACT_UNLOCK}) — if false, viewer is blocked (upgrade).
 * Quota: {@see EntitlementService::getValue}(userId, {@see PlanFeatureKeys::CONTACT_VIEW_LIMIT}, '0') vs {@see UserFeatureUsageService::getUsage}(userId, {@see UserFeatureUsageKeys::CONTACT_VIEW_LIMIT}).
 * Reveal charge: {@see UserFeatureUsageService::incrementUsage} only once per viewer+profile+calendar month ({@see UserContactRevealLog}).
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

        if ($viewer->isAnyAdmin()) {
            $case = $this->resolveVisibilityCase($targetProfile, $visibilitySettings);
            $phone = trim((string) ($targetProfile->primary_contact_number ?? ''));

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
                'plans_url' => $plansUrl,
                'already_revealed_this_month' => true,
                'paid_reveal_blocked_pending_matchmaking' => false,
            ];
        }

        $hasUnlock = $this->entitlements->hasFeature($uid, PlanFeatureKeys::CONTACT_UNLOCK);

        if (! $hasUnlock) {
            return [
                'has_contact_unlock' => false,
                'blocked' => true,
                'visibility_case' => null,
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
                'show_mediator_cta' => false,
                'show_no_one_copy' => false,
                'needs_upgrade' => true,
                'needs_upgrade_for_mediator' => false,
                'paid_contact_phone' => null,
                'plans_url' => $plansUrl,
                'paid_reveal_blocked_pending_matchmaking' => false,
            ];
        }

        $case = $this->resolveVisibilityCase($targetProfile, $visibilitySettings);

        $cvLimit = $this->parseNumericLimit($uid, PlanFeatureKeys::CONTACT_VIEW_LIMIT);
        $cvUsed = $this->usage->getUsage($uid, UserFeatureUsageKeys::CONTACT_VIEW_LIMIT);
        $cvRemaining = $this->remaining($cvLimit, $cvUsed);

        $medLimit = $this->parseNumericLimit($uid, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH);
        $medUsed = $this->usage->getUsage($uid, UserFeatureUsageKeys::MEDIATOR_REQUEST);
        $medRemaining = $this->remaining($medLimit, $medUsed);

        // Limit 0 or used >= limit => no credits (plan may still include contact_unlock).
        $hasCvCredit = $cvLimit === -1 || ($cvLimit > 0 && $cvUsed < $cvLimit);

        $hasMedCredit = $medLimit === -1 || ($medLimit > 0 && $medUsed < $medLimit);

        $periodStart = $this->usage->resolvePeriodStart(
            \App\Models\UserFeatureUsage::PERIOD_MONTHLY,
            Carbon::now()
        )->toDateString();

        $alreadyRevealedThisMonth = $this->hasRevealedThisMonth($uid, $targetProfile->id, $periodStart);

        $paidRevealNeedsMatchmaking = (bool) config('communication.paid_contact_reveal_requires_matchmaking_interested', true);
        $matchmakingInterestedOk = ! $paidRevealNeedsMatchmaking || $this->viewerHasMatchmakingInterested($viewer, $targetProfile);

        $grantPhone = null;
        if (! empty($contactGrantReveal['phone'])) {
            $grantPhone = trim((string) $contactGrantReveal['phone']);
            if ($grantPhone === '') {
                $grantPhone = null;
            }
        }

        $paidPhone = null;
        $showPaidReveal = false;
        $showRequestRail = false;
        $showNoOne = false;

        if ($case === self::CASE_NO_ONE) {
            $showNoOne = true;
            $showRequestRail = false;
        } elseif ($case === self::CASE_REQUEST_ONLY) {
            $showRequestRail = true;
        } else {
            // paid_allowed: show number only after billed reveal (or unlimited grant path below)
            if ($interestAllowsContact && $alreadyRevealedThisMonth) {
                $primary = trim((string) ($targetProfile->primary_contact_number ?? ''));
                $paidPhone = $primary !== '' ? $primary : null;
            } elseif ($interestAllowsContact && $hasCvCredit && $matchmakingInterestedOk) {
                $primary = trim((string) ($targetProfile->primary_contact_number ?? ''));
                if ($primary !== '') {
                    $showPaidReveal = true;
                }
            }
        }

        if ($grantPhone !== null) {
            $paidPhone = $grantPhone;
            $showPaidReveal = false;
        }

        $noContactCreditsLeft = $case === self::CASE_PAID_ALLOWED
            && $interestAllowsContact
            && ! $alreadyRevealedThisMonth
            && $grantPhone === null
            && ! $hasCvCredit
            && trim((string) ($targetProfile->primary_contact_number ?? '')) !== '';

        $paidRevealBlockedByMatchmaking = $case === self::CASE_PAID_ALLOWED
            && $interestAllowsContact
            && $hasCvCredit
            && ! $matchmakingInterestedOk
            && $grantPhone === null
            && ! $alreadyRevealedThisMonth
            && trim((string) ($targetProfile->primary_contact_number ?? '')) !== '';

        return [
            'has_contact_unlock' => true,
            'blocked' => false,
            'visibility_case' => $case,
            'allows_direct_contact_reveal' => $case === self::CASE_PAID_ALLOWED && $interestAllowsContact,
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
            'show_mediator_cta' => true,
            'show_no_one_copy' => $showNoOne,
            'needs_upgrade' => false,
            'needs_upgrade_for_mediator' => ! $hasMedCredit,
            'paid_contact_phone' => $paidPhone,
            'plans_url' => $plansUrl,
            'already_revealed_this_month' => $alreadyRevealedThisMonth,
            'paid_reveal_blocked_pending_matchmaking' => $paidRevealBlockedByMatchmaking,
        ];
    }

    /**
     * Validates paid reveal, creates {@see UserContactRevealLog} when needed; does not increment usage.
     * Call {@see FeatureUsageService::consume}(..., {@see FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT}) in the same DB transaction when {@code consume_credit} is true.
     *
     * @return array{phone: string, consume_credit: bool}
     *
     * @throws InvalidArgumentException
     */
    public function performPaidContactRevealBilling(User $viewer, MatrimonyProfile $targetProfile, ?object $visibilitySettings): array
    {
        $uid = (int) $viewer->id;
        if ($viewer->isAnyAdmin()) {
            $p = trim((string) ($targetProfile->primary_contact_number ?? ''));
            if ($p === '') {
                throw new InvalidArgumentException(__('contact_access.no_phone_on_profile'));
            }

            return ['phone' => $p, 'consume_credit' => false];
        }

        if (! $this->entitlements->hasFeature($uid, PlanFeatureKeys::CONTACT_UNLOCK)) {
            throw new InvalidArgumentException(__('contact_access.upgrade_required'));
        }

        $case = $this->resolveVisibilityCase($targetProfile, $visibilitySettings);
        if ($case !== self::CASE_PAID_ALLOWED) {
            throw new InvalidArgumentException(__('contact_access.reveal_not_allowed'));
        }

        $interest = $this->viewerHasAcceptedInterestWith($viewer, $targetProfile);
        if (! $interest) {
            throw new InvalidArgumentException(__('contact_access.interest_required'));
        }

        if (config('communication.paid_contact_reveal_requires_matchmaking_interested', true) && ! $this->viewerHasMatchmakingInterested($viewer, $targetProfile)) {
            throw new InvalidArgumentException(__('contact_access.matchmaking_interested_required'));
        }

        $phone = trim((string) ($targetProfile->primary_contact_number ?? ''));
        if ($phone === '') {
            throw new InvalidArgumentException(__('contact_access.no_phone_on_profile'));
        }

        $periodStart = $this->usage->resolvePeriodStart(
            \App\Models\UserFeatureUsage::PERIOD_MONTHLY,
            Carbon::now()
        )->toDateString();

        if ($this->hasRevealedThisMonth($uid, $targetProfile->id, $periodStart)) {
            return ['phone' => $phone, 'consume_credit' => false];
        }

        if (! app(FeatureUsageService::class)->canUse($uid, FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT)) {
            throw new InvalidArgumentException(__('contact_access.no_contact_credits'));
        }

        $limit = $this->parseNumericLimit($uid, PlanFeatureKeys::CONTACT_VIEW_LIMIT);

        $log = UserContactRevealLog::query()->firstOrCreate(
            [
                'viewer_user_id' => $uid,
                'viewed_profile_id' => $targetProfile->id,
                'period_start' => $periodStart,
            ],
            []
        );
        $consumeCredit = $log->wasRecentlyCreated && $limit !== -1;

        return ['phone' => $phone, 'consume_credit' => $consumeCredit];
    }

    /**
     * Consume one contact_view_limit credit and log dedup for this profile/month (usage + log in one transaction).
     *
     * @throws InvalidArgumentException
     */
    public function consumePaidContactReveal(User $viewer, MatrimonyProfile $targetProfile, ?object $visibilitySettings): string
    {
        return DB::transaction(function () use ($viewer, $targetProfile, $visibilitySettings) {
            $result = $this->performPaidContactRevealBilling($viewer, $targetProfile, $visibilitySettings);
            if ($result['consume_credit']) {
                app(FeatureUsageService::class)->consume((int) $viewer->id, FeatureUsageService::FEATURE_CONTACT_VIEW_LIMIT);
            }

            return $result['phone'];
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
        if ($viewer->isAnyAdmin()) {
            return;
        }

        if (! $this->entitlements->hasFeature($uid, PlanFeatureKeys::CONTACT_UNLOCK)) {
            throw new InvalidArgumentException(__('contact_access.upgrade_required'));
        }

        $limit = $this->parseNumericLimit($uid, PlanFeatureKeys::MEDIATOR_REQUESTS_PER_MONTH);
        $used = $this->usage->getUsage($uid, UserFeatureUsageKeys::MEDIATOR_REQUEST);
        if ($limit !== -1 && ($limit === 0 || $used >= $limit)) {
            throw new InvalidArgumentException(__('contact_access.no_mediator_credits'));
        }
    }

    /**
     * Record one mediator credit use (no entitlement re-check). Call after a mediation row is created.
     */
    public function incrementMediatorUsage(User $viewer): void
    {
        if ($viewer->isAnyAdmin()) {
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
        $mode = $target->contact_unlock_mode ?? 'after_interest_accepted';
        if (in_array($mode, ['never', 'admin_only'], true)) {
            return self::CASE_NO_ONE;
        }

        $showTo = $visibilitySettings->show_contact_to ?? 'accepted_interest';

        return $showTo === 'unlock_only'
            ? self::CASE_REQUEST_ONLY
            : self::CASE_PAID_ALLOWED;
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
}
