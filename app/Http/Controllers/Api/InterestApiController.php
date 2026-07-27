<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Interest;
use App\Models\MatrimonyProfile;
use App\Services\Interest\InterestActionService;
use App\Services\Interest\SuchakRoutedInterestService;
use App\Services\InterestSendLimitService;
use App\Support\ErrorFactory;
use App\Support\RuleResultResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Mobile surface for member interests. Every rule and side effect lives in
 * {@see InterestActionService}, shared with the web {@see \App\Http\Controllers\InterestController}
 * so the two surfaces cannot drift again.
 */
class InterestApiController extends Controller
{
    public function __construct(
        private readonly InterestActionService $interestActions,
        private readonly InterestSendLimitService $interestSendLimit,
        private readonly SuchakRoutedInterestService $routedInterests,
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

        $receiverProfile = MatrimonyProfile::find($request->receiver_profile_id);

        if (! $receiverProfile) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiProfilesMissing(), 403);
        }

        $outcome = $this->interestActions->send($user, $receiverProfile);

        if (! $outcome->ok) {
            return RuleResultResponder::toResponse($outcome->error, $outcome->status);
        }

        // Contract (shipped apps depend on it): an already-sent pair answers 409 + INTEREST_DUPLICATE.
        if ($outcome->duplicate && $outcome->interest !== null) {
            return response()->json(array_merge(
                ['success' => false],
                ErrorFactory::interestApiDuplicateInterest()->toArray(),
                [
                    'data' => [
                        'id' => $outcome->interest->id,
                        'status' => $outcome->interest->status,
                    ],
                ]
            ), 409);
        }

        return response()->json([
            'success' => true,
            'message' => $outcome->message(),
            'data' => $outcome->interest,
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

        // Additive `suchak_routing` block (null for ordinary interests): a member
        // who approached a Suchak-managed profile must see WHERE their one
        // interest actually is — "with <Suchak>" — instead of a bare "pending"
        // that reads as if nobody received it. Every string is the same one the
        // profile contact card and the Suchak app already use.
        $routingById = $this->routedInterests->sentListRoutingMap($sentInterests);

        $sentPayload = $sentInterests->map(function (Interest $interest) use ($routingById): array {
            $row = $interest->toArray();
            $row['suchak_routing'] = $routingById[$interest->id] ?? null;

            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'sent' => $sentPayload,
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
        return $this->respondToInterestAction($id, 'accept');
    }

    /**
     * Reject interest
     */
    public function reject($id)
    {
        return $this->respondToInterestAction($id, 'reject');
    }

    /**
     * Withdraw interest
     */
    public function withdraw($id)
    {
        return $this->respondToInterestAction($id, 'withdraw', withData: false);
    }

    /**
     * @param  'accept'|'reject'|'withdraw'  $action
     */
    private function respondToInterestAction(mixed $id, string $action, bool $withData = true): JsonResponse|RedirectResponse
    {
        $user = request()->user();

        if (! $user || ! $user->matrimonyProfile) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiMatrimonyProfileRequired(), 403);
        }

        $interest = Interest::find($id);

        if (! $interest) {
            return RuleResultResponder::toResponse(ErrorFactory::interestApiNotFound(), 404);
        }

        $outcome = match ($action) {
            'accept' => $this->interestActions->accept($user, $interest),
            'reject' => $this->interestActions->reject($user, $interest),
            'withdraw' => $this->interestActions->withdraw($user, $interest),
        };

        if (! $outcome->ok) {
            return RuleResultResponder::toResponse($outcome->error, $outcome->status);
        }

        $payload = [
            'success' => true,
            'message' => $outcome->message(),
        ];

        if ($withData) {
            $payload['data'] = $outcome->interest;
        }

        return response()->json($payload);
    }
}
