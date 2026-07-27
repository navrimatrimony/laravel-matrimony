<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakChatThreadService;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Modules\Suchak\Services\SuchakRequestPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Suchak side of the EXISTING request pipeline, opened to the Suchak app.
 *
 * Until now a Suchak only learned that a member had approached one of their
 * customers if they opened the website dashboard. Every action below routes
 * through SuchakRequestPipelineService — the same service the web reply
 * controller uses — so an app reply lands in the member↔candidate chat with the
 * identical "सूचकांकडून संदेश (…)" prefix and the identical audit trail.
 */
class SuchakProfileRequestsApiController extends Controller
{
    private const FILTER_OPEN = 'open';
    private const FILTER_ANSWERED = 'answered';
    private const FILTER_ALL = 'all';

    public function __construct(
        private readonly SuchakRequestPipelineService $pipelineService,
        private readonly SuchakRequestPresenter $presenter,
        private readonly SuchakChatThreadService $chatThreads,
    ) {
    }

    /**
     * GET /api/v1/suchak/profile-requests
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        $validated = $request->validate([
            'filter' => ['nullable', 'string', Rule::in([self::FILTER_OPEN, self::FILTER_ANSWERED, self::FILTER_ALL])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Reuse the existing SLA expiry rather than a second timer: anything past
        // its window closes here, before the Suchak is shown a request they can
        // no longer answer.
        $this->pipelineService->expireDuePipelinesForAccount($account);

        $filter = (string) ($validated['filter'] ?? self::FILTER_OPEN);
        $limit = (int) ($validated['limit'] ?? 50);

        $query = SuchakProfileRequest::query()
            ->with([
                'pipeline',
                'representation',
                'requestingMatrimonyProfile.gender',
                'requestingMatrimonyProfile.religion',
                'requestingMatrimonyProfile.caste',
                'requestingMatrimonyProfile.location',
                'targetMatrimonyProfile.user',
            ])
            ->where('selected_suchak_account_id', $account->id);

        if ($filter === self::FILTER_OPEN) {
            $query->whereIn('request_status', SuchakProfileRequest::OPEN_STATUSES);
        } elseif ($filter === self::FILTER_ANSWERED) {
            // "Answered" means the Suchak has acted on it — which includes their
            // OWN reply, not only the candidate's final yes/no. Without
            // accepted_by_suchak here the tab stayed empty right after a Suchak
            // replied, so the reply looked lost. Forwarding is an action too.
            $query->whereIn('request_status', [
                SuchakProfileRequest::STATUS_ACCEPTED_BY_SUCHAK,
                SuchakProfileRequest::STATUS_FORWARDED_TO_CANDIDATE,
                SuchakProfileRequest::STATUS_CANDIDATE_INTERESTED,
                SuchakProfileRequest::STATUS_CANDIDATE_NOT_INTERESTED,
            ]);
        }

        $rows = $query->orderByDesc('id')->limit($limit)->get();

        $pendingCount = SuchakProfileRequest::query()
            ->where('selected_suchak_account_id', $account->id)
            ->whereIn('request_status', [
                SuchakProfileRequest::STATUS_PENDING,
                SuchakProfileRequest::STATUS_VIEWED_BY_SUCHAK,
            ])
            ->count();

        return response()->json([
            'success' => true,
            'message' => __('profile.suchak_request_list_loaded'),
            'data' => [
                'filter' => $filter,
                'count' => $rows->count(),
                'awaiting_action_count' => $pendingCount,
                'profile_requests' => $rows
                    ->map(fn (SuchakProfileRequest $row): array => $this->presenter->suchakRequestPayload($row))
                    ->values()
                    ->all(),
                'decision_options' => $this->decisionOptions(),
            ],
        ]);
    }

    /**
     * GET /api/v1/suchak/profile-requests/{profileRequest}
     * Opening a request is what moves pending → viewed_by_suchak.
     */
    public function show(Request $request, SuchakProfileRequest $profileRequest): JsonResponse
    {
        $account = $this->account($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        if ((int) $profileRequest->selected_suchak_account_id !== (int) $account->id) {
            return $this->error(__('profile.suchak_request_not_yours'), 403);
        }

        $this->pipelineService->expireDuePipelinesForAccount($account);
        $profileRequest->refresh();

        try {
            $profileRequest = $this->pipelineService->markViewedBySuchak(
                $profileRequest,
                $account,
                $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('profile.suchak_request_marked_viewed'),
            'data' => [
                'profile_request' => $this->presenter->suchakRequestPayload($profileRequest),
                'decision_options' => $this->decisionOptions(),
                // The exchange, not just the opening line. A Suchak who replied
                // could previously see neither their own reply nor anything the
                // member wrote afterwards — the request read as a one-shot card.
                // Null when nothing has been written yet.
                'chat' => $this->chatThreads->threadForRequest($account, $profileRequest),
            ],
        ]);
    }

    /**
     * POST /api/v1/suchak/profile-requests/{profileRequest}/reply
     * Identical service call to the web reply controller.
     */
    public function reply(Request $request, SuchakProfileRequest $profileRequest): JsonResponse
    {
        $account = $this->account($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        $validated = $request->validate([
            'reply_message' => ['required', 'string', 'max:1600'],
        ]);

        try {
            $result = $this->pipelineService->replyThroughExistingChat(
                $profileRequest,
                $account,
                $request->user(),
                (string) $validated['reply_message'],
                $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('profile.suchak_request_reply_sent'),
            'data' => [
                'profile_request' => $this->presenter->suchakRequestPayload($result['request']),
                'chat_conversation_id' => (int) $result['message']->conversation_id,
                'chat_message_id' => (int) $result['message']->id,
                // The thread the reply just landed in, so the screen shows the
                // Suchak their own words immediately instead of a blank card.
                'chat' => $this->chatThreads->threadForRequest($account, $result['request']),
            ],
        ]);
    }

    /**
     * POST /api/v1/suchak/profile-requests/{profileRequest}/forward
     * "I have put this in front of the family" — the half of the lifecycle that
     * had a declared status but no code that ever set it.
     */
    public function forward(Request $request, SuchakProfileRequest $profileRequest): JsonResponse
    {
        $account = $this->account($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->pipelineService->forwardToCandidate(
                $profileRequest,
                $account,
                $request->user(),
                $validated['note'] ?? null,
                $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('profile.suchak_request_forwarded'),
            'data' => [
                'profile_request' => $this->presenter->suchakRequestPayload($result['request']),
            ],
        ]);
    }

    /**
     * POST /api/v1/suchak/profile-requests/{profileRequest}/decision
     * The Suchak relaying the family's yes/no. Races the candidate's own answer;
     * first one wins, the loser gets a clean "already answered by …".
     */
    public function decide(Request $request, SuchakProfileRequest $profileRequest): JsonResponse
    {
        $account = $this->account($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(SuchakRequestPipelineService::DECISIONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->pipelineService->recordCandidateDecision(
                $profileRequest,
                $request->user(),
                (string) $validated['decision'],
                $validated['note'] ?? null,
                $account,
                $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json(
            $this->presenter->decisionResponse(
                $result,
                fn (SuchakProfileRequest $row): array => $this->presenter->suchakRequestPayload($row),
            ),
        );
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function decisionOptions(): array
    {
        return [
            [
                'key' => SuchakRequestPipelineService::DECISION_INTERESTED,
                'label' => (string) __('profile.suchak_request_status_candidate_interested'),
            ],
            [
                'key' => SuchakRequestPipelineService::DECISION_NOT_INTERESTED,
                'label' => (string) __('profile.suchak_request_status_candidate_not_interested'),
            ],
        ];
    }

    private function account(Request $request): SuchakAccount|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $account = $user->suchakAccount;
        if (! $account instanceof SuchakAccount) {
            return $this->error('Suchak account is required to access this section.', 403);
        }

        return $account;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
