<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Services\InterestPriorityService;
use App\Services\InterestSendLimitService;
use App\Services\ProfileLifecycleService;
use App\Services\RuleEngineService;
use App\Services\Showcase\ShowcaseInterestPolicyService;
use App\Support\ErrorFactory;
use App\Support\RuleResultResponder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InterestApiController extends Controller
{
    public function __construct(
        private readonly InterestSendLimitService $interestSendLimit,
        private readonly InterestPriorityService $interestPriority,
        private readonly ShowcaseInterestPolicyService $showcaseInterestPolicy,
        private readonly RuleEngineService $ruleEngine,
    ) {}

    /**
     * Send interest to a matrimony profile
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Guard: MatrimonyProfile must exist
        if (! $user || ! $user->matrimonyProfile) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        // Validate receiver_profile_id
        $request->validate([
            'receiver_profile_id' => 'required|exists:matrimony_profiles,id',
        ]);

        $senderProfile = $user->matrimonyProfile;
        $receiverProfile = MatrimonyProfile::find($request->receiver_profile_id);

        // Guard: Cannot send interest to own profile
        if ($senderProfile->id === $receiverProfile->id) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiCannotSendToSelf(), 403);
        }

        // Safety check
        if (! $senderProfile || ! $receiverProfile) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiProfilesMissing(), 403);
        }

        // Day 7: Sender lifecycle — Archived/Suspended/Search-Hidden cannot send interest
        if (! ProfileLifecycleService::canInitiateInteraction($senderProfile)) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiSenderLifecycleBlocked(), 403);
        }

        // Day 7: Archived/Suspended/Search-Hidden → interest blocked (receiver)
        if (! ProfileLifecycleService::canReceiveInterest($receiverProfile)) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiReceiverLifecycleBlocked(), 403);
        }

        $senderRule = $this->ruleEngine->checkInterestMandatoryCoreForSender($senderProfile);
        if (! $senderRule->allowed) {
            return RuleResultResponder::toResponse($senderRule);
        }
        $targetRule = $this->ruleEngine->checkInterestMandatoryCoreForSendTarget($receiverProfile);
        if (! $targetRule->allowed) {
            return RuleResultResponder::toResponse($targetRule);
        }

        // Check if interest already exists
        $existingInterest = Interest::where('sender_profile_id', $senderProfile->id)
            ->where('receiver_profile_id', $receiverProfile->id)
            ->first();

        if ($existingInterest) {
            return response()->json(array_merge(
                ['success' => false],
                ErrorFactory::interestApiDuplicateInterest()->toArray(),
                [
                    'data' => [
                        'id' => $existingInterest->id,
                        'status' => $existingInterest->status,
                    ],
                ]
            ), 409);
        }

        $sendEval = $this->showcaseInterestPolicy->evaluateSendInterest($senderProfile, $receiverProfile);
        if (! $sendEval['ok']) {
            $policyMsg = trim((string) ($sendEval['message'] ?? ''));

            return RuleResultResponder::toResponse(
                $policyMsg !== ''
                    ? ErrorFactory::deny('INTEREST_SHOWCASE_POLICY', $policyMsg, null)
                    : ErrorFactory::interestSendBlocked(),
                422
            );
        }

        if (! ($sendEval['bypass_plan_quota'] ?? false)) {
            try {
                $this->interestSendLimit->assertCanSend($user);
            } catch (HttpException $e) {
                return RuleResultResponder::toResponse(
                    ErrorFactory::interestSendLimitHttp($e->getStatusCode(), $e->getMessage()),
                    $e->getStatusCode()
                );
            }
        }

        // Create new interest
        $interest = Interest::create([
            'sender_profile_id' => $senderProfile->id,
            'receiver_profile_id' => $receiverProfile->id,
            'status' => 'pending',
            'priority_score' => $this->interestPriority->baseScoreForSender($user),
        ]);

        if (! ($sendEval['bypass_plan_quota'] ?? false)) {
            $this->interestSendLimit->recordSuccessfulSend($user);
        }

        return response()->json([
            'success' => true,
            'message' => 'Interest sent successfully.',
            'data' => $interest,
        ], 200);
    }

    /**
     * Get sent interests
     */
    public function sent(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->matrimonyProfile) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        $myProfileId = $user->matrimonyProfile->id;

        $sentInterests = Interest::with('receiverProfile')
            ->where('sender_profile_id', $myProfileId)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'sent' => $sentInterests,
            ],
        ]);
    }

    /**
     * Get received interests
     */
    public function received(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->matrimonyProfile) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        $myProfileId = $user->matrimonyProfile->id;

        $receivedInterests = Interest::with(['senderProfile.gender'])
            ->where('receiver_profile_id', $myProfileId)
            ->receivedInboxOrder()
            ->get();

        $unlockById = $this->interestSendLimit->incomingInterestUnlockMap($user, $receivedInterests);

        $receivedPayload = $receivedInterests->map(function (Interest $interest) use ($unlockById) {
            $revealed = $unlockById[$interest->id] ?? true;
            $row = $interest->only(['id', 'sender_profile_id', 'receiver_profile_id', 'status', 'priority_score', 'created_at', 'updated_at']);
            if ($revealed && $interest->senderProfile) {
                $row['sender_profile'] = $interest->senderProfile->toArray();
            } elseif ($interest->senderProfile) {
                $row['sender_profile'] = [
                    'id' => $interest->senderProfile->id,
                    'revealed' => false,
                ];
            } else {
                $row['sender_profile'] = null;
            }
            $row['incoming_reveal_unlocked'] = $revealed;

            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'received' => $receivedPayload,
                'interest_view_limit' => $this->interestSendLimit->effectiveInterestViewLimit($user),
                'interest_view_reset_period' => $this->interestSendLimit->interestViewResetPeriodLabel($user),
                'interest_view_window_start' => $this->interestSendLimit->interestViewWindowStart($user)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Accept interest
     */
    public function accept($id)
    {
        $user = request()->user();

        if (! $user || ! $user->matrimonyProfile) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        $interest = Interest::find($id);

        if (! $interest) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiNotFound(), 404);
        }

        // Guard: Only receiver can accept
        if ($interest->receiver_profile_id !== $user->matrimonyProfile->id) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiOnlyReceiver(), 403);
        }

        // Guard: Only pending interest can be accepted
        if ($interest->status !== 'pending') {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiAlreadyProcessed(), 403);
        }

        if ($msg = $this->showcaseInterestPolicy->validateAcceptInterest($user->matrimonyProfile, $interest)) {
            return RuleResultResponder::toResponse(ErrorFactory::deny('INTEREST_SHOWCASE_POLICY', $msg, null), 422);
        }

        $receiverProfile = $user->matrimonyProfile;
        $acceptRule = $this->ruleEngine->checkInterestMandatoryCoreForAccept($receiverProfile);
        if (! $acceptRule->allowed) {
            return RuleResultResponder::toResponse($acceptRule);
        }

        $interest->update([
            'status' => 'accepted',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Interest accepted.',
            'data' => $interest,
        ]);
    }

    /**
     * Reject interest
     */
    public function reject($id)
    {
        $user = request()->user();

        if (! $user || ! $user->matrimonyProfile) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        $interest = Interest::find($id);

        if (! $interest) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiNotFound(), 404);
        }

        // Guard: Only receiver can reject
        if ($interest->receiver_profile_id !== $user->matrimonyProfile->id) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiOnlyReceiver(), 403);
        }

        // Guard: Only pending interest can be rejected
        if ($interest->status !== 'pending') {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiAlreadyProcessed(), 403);
        }

        if ($msg = $this->showcaseInterestPolicy->validateRejectInterest($user->matrimonyProfile, $interest)) {
            return RuleResultResponder::toResponse(ErrorFactory::deny('INTEREST_SHOWCASE_POLICY', $msg, null), 422);
        }

        $interest->update([
            'status' => 'rejected',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Interest rejected.',
            'data' => $interest,
        ]);
    }

    /**
     * Withdraw interest
     */
    public function withdraw($id)
    {
        $user = request()->user();

        if (! $user || ! $user->matrimonyProfile) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        $interest = Interest::find($id);

        if (! $interest) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiNotFound(), 404);
        }

        // Guard: Only sender can withdraw
        if ($interest->sender_profile_id !== $user->matrimonyProfile->id) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiOnlySenderWithdraw(), 403);
        }

        // Guard: Only pending interest can be withdrawn
        if ($interest->status !== 'pending') {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiOnlyPendingWithdraw(), 403);
        }

        if ($msg = $this->showcaseInterestPolicy->validateWithdrawInterest($user->matrimonyProfile, $interest)) {
            return RuleResultResponder::toResponse(ErrorFactory::deny('INTEREST_SHOWCASE_POLICY', $msg, null), 422);
        }

        $interest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Interest withdrawn successfully.',
        ]);
    }
}
