<?php

namespace App\Services\Interest;

use App\Models\Block;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Models\UserMatchBehavior;
use App\Notifications\InterestAcceptedNotification;
use App\Notifications\InterestRejectedNotification;
use App\Notifications\InterestSentNotification;
use App\Services\AdminActivityNotificationGate;
use App\Services\InterestPriorityService;
use App\Services\InterestSendLimitService;
use App\Services\ProfileLifecycleService;
use App\Services\RuleEngineService;
use App\Services\Showcase\ShowcaseInterestPolicyService;
use App\Support\ErrorFactory;
use App\Support\SafeNotifier;
use App\Support\SchemaPresence;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The ONE engine for member interest actions (send / accept / reject / withdraw).
 *
 * Why this exists: the web controller and the mobile API controller each carried their own
 * copy of these rules and drifted apart — the API never notified anybody, never checked blocks,
 * never granted contact visibility on accept and never enforced the reveal gate. Interests sent
 * from the app therefore looked ignored ("nobody ever replies"). Both controllers now call this
 * service and only render {@see InterestActionOutcome}; the showcase auto-responder shares the
 * same accept/reject side effects via {@see applyAcceptEffects()} / {@see applyRejectEffects()}.
 *
 * Guard order is the web order (web was the correct surface). Callers must not re-implement any
 * of these checks — per the frozen no-duplicate rule, one fact has one home.
 *
 * Suchak routing: when the receiving profile is managed by a Suchak, the interest is ALSO placed
 * in the Suchak's existing request pipeline by {@see SuchakRoutedInterestService}. That is a pure
 * add-on — nothing below changes for an ordinary member-to-member interest.
 */
class InterestActionService
{
    public function __construct(
        private readonly InterestSendLimitService $interestSendLimit,
        private readonly InterestPriorityService $interestPriority,
        private readonly ShowcaseInterestPolicyService $showcaseInterestPolicy,
        private readonly RuleEngineService $ruleEngine,
        private readonly SuchakRoutedInterestService $routedInterests,
    ) {}

    // -------------------------------------------------------------------------
    // Send
    // -------------------------------------------------------------------------

    public function send(User $senderUser, MatrimonyProfile $receiverProfile): InterestActionOutcome
    {
        $senderProfile = $senderUser->matrimonyProfile;
        if (! $senderProfile) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        if ((int) $senderProfile->id === (int) $receiverProfile->id) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiCannotSendToSelf(), 403);
        }

        // Receiver has blocked the sender → refuse without revealing that a block exists.
        if ($this->blockExists($receiverProfile->id, $senderProfile->id)) {
            return InterestActionOutcome::denied(ErrorFactory::interestReceiverBlockedSender(), 403);
        }

        // Sender has blocked the receiver → tell them to unblock first.
        if ($this->blockExists($senderProfile->id, $receiverProfile->id)) {
            return InterestActionOutcome::denied(ErrorFactory::interestSenderHasBlockedReceiver(), 403);
        }

        if (! ProfileLifecycleService::canInitiateInteraction($senderProfile)) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiSenderLifecycleBlocked(), 403);
        }

        $senderRule = $this->ruleEngine->checkInterestMandatoryCoreForSender($senderProfile);
        if (! $senderRule->allowed) {
            return InterestActionOutcome::denied($senderRule);
        }

        $targetRule = $this->ruleEngine->checkInterestMandatoryCoreForSendTarget($receiverProfile);
        if (! $targetRule->allowed) {
            return InterestActionOutcome::denied($targetRule);
        }

        if (! ProfileLifecycleService::canReceiveInterest($receiverProfile)) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiReceiverLifecycleBlocked(), 403);
        }

        // Already sent: no new row, no notification, no quota — both surfaces agree on the state,
        // they only differ in how they announce it (web: success flash, API: 409 + existing row).
        $existing = Interest::query()
            ->where('sender_profile_id', $senderProfile->id)
            ->where('receiver_profile_id', $receiverProfile->id)
            ->first();
        if ($existing !== null) {
            // Re-tapping the heart after a Suchak-routed approach closed on SLA is how the member
            // sends a "fresh one" (PO decision). The interest row is unique per pair, so the SAME
            // row is re-offered through a NEW pipeline request — the member never sees two.
            $this->routeToSuchakIfNeeded($senderUser, $senderProfile, $receiverProfile, $existing);

            return InterestActionOutcome::alreadyExists($existing, 'interest.interest_sent_successfully');
        }

        $sendEval = $this->showcaseInterestPolicy->evaluateSendInterest($senderProfile, $receiverProfile);
        if (! ($sendEval['ok'] ?? false)) {
            $policyMsg = trim((string) ($sendEval['message'] ?? ''));

            return InterestActionOutcome::denied(
                $policyMsg !== ''
                    ? ErrorFactory::deny('INTEREST_SHOWCASE_POLICY', $policyMsg, null)
                    : ErrorFactory::interestSendBlocked()
            );
        }

        $bypassQuota = (bool) ($sendEval['bypass_plan_quota'] ?? false);

        if (! $bypassQuota) {
            try {
                $this->interestSendLimit->assertCanSend($senderUser);
            } catch (HttpException $e) {
                return InterestActionOutcome::denied(
                    ErrorFactory::interestSendLimitHttp($e->getStatusCode(), $e->getMessage()),
                    $e->getStatusCode()
                );
            }
        }

        $interest = Interest::firstOrCreate(
            [
                'sender_profile_id' => $senderProfile->id,
                'receiver_profile_id' => $receiverProfile->id,
            ],
            [
                'status' => 'pending',
                'priority_score' => $this->interestPriority->baseScoreForSender($senderUser),
            ]
        );

        // Lost a race with a concurrent send: treat exactly like the duplicate branch above.
        if (! $interest->wasRecentlyCreated) {
            $this->routeToSuchakIfNeeded($senderUser, $senderProfile, $receiverProfile, $interest);

            return InterestActionOutcome::alreadyExists($interest, 'interest.interest_sent_successfully');
        }

        if (! $bypassQuota) {
            $this->interestSendLimit->recordSuccessfulSend($senderUser);
        }

        $receiverOwner = $receiverProfile->user;
        if ($receiverOwner && AdminActivityNotificationGate::allowsPeerActivityNotification($senderUser)) {
            SafeNotifier::notify($receiverOwner, new InterestSentNotification($senderProfile));
        }

        $this->recordMatchBehavior($senderUser, $receiverProfile, 'interest_sent');

        // LAST, and deliberately after the quota/notification work: a Suchak-routed target also
        // gets this approach in the Suchak's existing inbox. The heart is the primary action on
        // every card, so without this the pipeline is bypassed in the common case and the
        // opportunity dies silently.
        $this->routeToSuchakIfNeeded($senderUser, $senderProfile, $receiverProfile, $interest);

        return InterestActionOutcome::success($interest, 'interest.interest_sent_successfully');
    }

    // -------------------------------------------------------------------------
    // Accept / Reject / Withdraw
    // -------------------------------------------------------------------------

    public function accept(User $actor, Interest $interest): InterestActionOutcome
    {
        $receiverProfile = $actor->matrimonyProfile;
        if (! $receiverProfile) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        if ((int) $interest->receiver_profile_id !== (int) $receiverProfile->id) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiOnlyReceiver(), 403);
        }

        if ($interest->status !== 'pending') {
            return InterestActionOutcome::denied(ErrorFactory::interestApiAlreadyProcessed(), 403);
        }

        // Paid reveal gate: an interest the receiver's plan has not revealed cannot be accepted.
        // (Reject is deliberately allowed without reveal.)
        if (! $this->interestSendLimit->isIncomingInterestUnlocked($actor, $interest)) {
            return InterestActionOutcome::denied(ErrorFactory::interestAcceptRequiresReveal(), 403);
        }

        if ($msg = $this->showcaseInterestPolicy->validateAcceptInterest($receiverProfile, $interest)) {
            return InterestActionOutcome::denied(ErrorFactory::deny('INTEREST_SHOWCASE_POLICY', $msg, null));
        }

        $acceptRule = $this->ruleEngine->checkInterestMandatoryCoreForAccept($receiverProfile);
        if (! $acceptRule->allowed) {
            return InterestActionOutcome::denied($acceptRule);
        }

        $this->applyAcceptEffects($interest, $receiverProfile, $actor);

        $senderProfile = $interest->senderProfile;
        if ($senderProfile) {
            $this->recordMatchBehavior($actor, $senderProfile, 'interest_accepted');
        }

        return InterestActionOutcome::success($interest, 'interest.interest_accepted');
    }

    public function reject(User $actor, Interest $interest): InterestActionOutcome
    {
        $receiverProfile = $actor->matrimonyProfile;
        if (! $receiverProfile) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        if ((int) $interest->receiver_profile_id !== (int) $receiverProfile->id) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiOnlyReceiver(), 403);
        }

        if ($interest->status !== 'pending') {
            return InterestActionOutcome::denied(ErrorFactory::interestApiAlreadyProcessed(), 403);
        }

        if ($msg = $this->showcaseInterestPolicy->validateRejectInterest($receiverProfile, $interest)) {
            return InterestActionOutcome::denied(ErrorFactory::deny('INTEREST_SHOWCASE_POLICY', $msg, null));
        }

        $this->applyRejectEffects($interest, $receiverProfile, $actor);

        return InterestActionOutcome::success($interest, 'interest.interest_rejected');
    }

    public function withdraw(User $actor, Interest $interest): InterestActionOutcome
    {
        $senderProfile = $actor->matrimonyProfile;
        if (! $senderProfile) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        if ((int) $interest->sender_profile_id !== (int) $senderProfile->id) {
            return InterestActionOutcome::denied(ErrorFactory::interestApiOnlySenderWithdraw(), 403);
        }

        if ($interest->status !== 'pending') {
            return InterestActionOutcome::denied(ErrorFactory::interestApiOnlyPendingWithdraw(), 403);
        }

        if ($msg = $this->showcaseInterestPolicy->validateWithdrawInterest($senderProfile, $interest)) {
            return InterestActionOutcome::denied(ErrorFactory::deny('INTEREST_SHOWCASE_POLICY', $msg, null));
        }

        $interest->delete();

        return InterestActionOutcome::success(null, 'interest.interest_withdrawn_successfully');
    }

    // -------------------------------------------------------------------------
    // Side effects — shared with the showcase auto-responder (guards already passed)
    // -------------------------------------------------------------------------

    /**
     * Status flip + contact unlock + sender notification for an accepted interest.
     *
     * @param  User|null  $actor  The user performing the accept. Null (a showcase profile with no
     *                            owner account) still applies the state change but sends no peer
     *                            notification, matching the pre-existing auto-responder behaviour.
     */
    public function applyAcceptEffects(Interest $interest, MatrimonyProfile $receiverProfile, ?User $actor): void
    {
        $interest->update(['status' => 'accepted']);

        $senderProfile = $interest->senderProfile;

        // Phase-5: grant contact visibility via the normalized table (replaces contact_visible_to JSON).
        if ($senderProfile && $receiverProfile->contact_unlock_mode === 'after_interest_accepted') {
            DB::table('profile_contact_visibility')->insertOrIgnore([
                'owner_profile_id' => $receiverProfile->id,
                'viewer_profile_id' => $senderProfile->id,
                'granted_via' => 'interest_accept',
                'granted_at' => now(),
                'revoked_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('contact_access_log')->insert([
                'owner_profile_id' => $receiverProfile->id,
                'viewer_profile_id' => $senderProfile->id,
                'source' => 'interest',
                'unlocked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $senderOwner = $senderProfile?->user;
        if ($senderOwner && $actor && AdminActivityNotificationGate::allowsPeerActivityNotification($actor)) {
            SafeNotifier::notify($senderOwner, new InterestAcceptedNotification($receiverProfile));
        }
    }

    /**
     * Status flip + sender notification for a rejected interest.
     *
     * @param  User|null  $actor  See {@see applyAcceptEffects()}.
     */
    public function applyRejectEffects(Interest $interest, MatrimonyProfile $receiverProfile, ?User $actor): void
    {
        $interest->update(['status' => 'rejected']);

        $senderOwner = $interest->senderProfile?->user;
        if ($senderOwner && $actor && AdminActivityNotificationGate::allowsPeerActivityNotification($actor)) {
            SafeNotifier::notify($senderOwner, new InterestRejectedNotification($receiverProfile));
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Hands a settled interest to the Suchak pipeline when — and only when — the receiving profile
     * is Suchak-routed. A non-routed target returns immediately, so ordinary member-to-member
     * interests keep byte-identical behaviour.
     */
    private function routeToSuchakIfNeeded(
        User $senderUser,
        MatrimonyProfile $senderProfile,
        MatrimonyProfile $receiverProfile,
        Interest $interest,
    ): void {
        $this->routedInterests->routeInterest($senderUser, $senderProfile, $receiverProfile, $interest);
    }

    private function blockExists(int|string $blockerProfileId, int|string $blockedProfileId): bool
    {
        return Block::query()
            ->where('blocker_profile_id', $blockerProfileId)
            ->where('blocked_profile_id', $blockedProfileId)
            ->exists();
    }

    /**
     * Feeds the matching engine so it learns from interest activity (optional table).
     */
    private function recordMatchBehavior(User $actor, MatrimonyProfile $targetProfile, string $action): void
    {
        if (! SchemaPresence::hasTable('user_match_behaviors')) {
            return;
        }

        // Historical shape: the row points at the target OWNER's own matrimony profile.
        $targetOwnProfile = $targetProfile->user?->matrimonyProfile;
        if (! $targetOwnProfile) {
            return;
        }

        UserMatchBehavior::query()->create([
            'actor_user_id' => $actor->id,
            'target_profile_id' => $targetOwnProfile->id,
            'action' => $action,
            'created_at' => now(),
        ]);
    }
}
