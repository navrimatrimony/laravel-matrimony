<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakRequestPipelineService;
use App\Modules\Suchak\Services\SuchakRequestPresenter;
use App\Services\Api\MobileDiscoveryFilterService;
use App\Services\Api\MobileProfileDisplayPresenter;
use App\Services\FeatureUsageService;
use App\Support\Suchak\SuchakContactRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Member side of the EXISTING Suchak request pipeline, opened to the app.
 *
 * Every write here goes through SuchakRequestPipelineService — the same service
 * the web routes use — so a request created from the app is indistinguishable
 * from one created on the website (same records, same attribution lock, same
 * SLA, same chat injection, same lead-limit accounting).
 *
 * Quota is deliberately untouched: a Suchak conversation is billed exactly like
 * any other conversation, through the existing chat-send feature usage path.
 */
class MemberSuchakRequestApiController extends Controller
{
    public function __construct(
        private readonly SuchakRequestPipelineService $pipelineService,
        private readonly SuchakRequestPresenter $presenter,
        private readonly MobileProfileDisplayPresenter $displayPresenter,
    ) {
    }

    /**
     * GET /api/v1/matrimony-profiles/{id}/suchak-requests
     * Who manages this profile, and where my own request with each of them stands.
     */
    public function showForProfile(Request $request, int $id, MobileDiscoveryFilterService $discovery): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$user, $viewerProfile] = $context;

        $profile = MatrimonyProfile::query()->with('user')->find($id);
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error(__('profile.suchak_request_not_found'), 404);
        }

        if ((int) $viewerProfile->id === (int) $profile->id) {
            return $this->error(__('profile.suchak_request_decision_not_allowed'), 403);
        }

        if (! $discovery->isAllowedTarget($user, $profile)) {
            return $this->error(__('profile.suchak_request_not_found'), 404);
        }

        // Close anything whose SLA ran out before reporting state, so the member
        // is never shown a "pending" request the Suchak can no longer answer.
        $this->pipelineService->expireDuePipelinesForRequestingProfile($viewerProfile);

        return response()->json([
            'success' => true,
            'message' => __('profile.suchak_request_list_loaded'),
            'data' => $this->routingPayload($profile, $viewerProfile),
        ]);
    }

    /**
     * POST /api/v1/matrimony-profiles/{id}/suchak-requests
     * Create a request. Mirrors PublicProfileRequestController (web) exactly.
     */
    public function store(Request $request, int $id, MobileDiscoveryFilterService $discovery): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$user, $viewerProfile] = $context;

        $profile = MatrimonyProfile::query()->with('user')->find($id);
        if (! $profile instanceof MatrimonyProfile) {
            return $this->error(__('profile.suchak_request_not_found'), 404);
        }

        if ((int) $viewerProfile->id === (int) $profile->id || (int) $profile->user_id === (int) $user->id) {
            return $this->error(__('profile.suchak_request_decision_not_allowed'), 403);
        }

        if (! $discovery->isAllowedTarget($user, $profile)) {
            return $this->error(__('profile.suchak_request_not_found'), 404);
        }

        $validated = $request->validate([
            'representation_id' => ['nullable', 'integer'],
            'request_reason' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        // An expired-SLA request must not block the fresh one the member is
        // entitled to send, so sweep before the duplicate-open check runs.
        $this->pipelineService->expireDuePipelinesForRequestingProfile($viewerProfile);

        $representation = SuchakContactRouting::routableRepresentationFor(
            $profile,
            isset($validated['representation_id']) ? (int) $validated['representation_id'] : null,
        );

        if (! $representation instanceof SuchakProfileRepresentation) {
            return $this->error(__('profile.suchak_request_no_suchak'), 422);
        }

        $featureUsage = app(FeatureUsageService::class);
        if (! $featureUsage->shouldBypassUsageLimits($user)
            && ! $featureUsage->canUse((int) $user->id, FeatureUsageService::FEATURE_CHAT_SEND_LIMIT)) {
            return $this->error(__('profile.suchak_contact_message_quota_empty'), 422);
        }

        try {
            $created = DB::transaction(function () use ($featureUsage, $user, $viewerProfile, $representation, $validated, $request): array {
                $result = $this->pipelineService->createRequest(
                    $user,
                    $viewerProfile,
                    $representation,
                    [
                        'request_reason' => $validated['request_reason'] ?? null,
                        'message' => $validated['message'] ?? null,
                    ],
                    $request->ip(),
                    $request->userAgent(),
                );

                if (! $featureUsage->shouldBypassUsageLimits($user)
                    && ! $featureUsage->consume((int) $user->id, FeatureUsageService::FEATURE_CHAT_SEND_LIMIT)) {
                    throw new InvalidArgumentException(__('profile.suchak_contact_message_quota_empty'));
                }

                return $result;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        /** @var SuchakProfileRequest $suchakRequest */
        $suchakRequest = $created['request'];
        $profile->refresh()->loadMissing('user');
        $display = $this->displayPresenter->forProfile($profile, $user);

        return response()->json([
            'success' => true,
            'message' => __('profile.suchak_contact_request_success'),
            'data' => [
                'suchak_request' => $this->presenter->memberRequestPayload($suchakRequest),
                'routing' => $this->routingPayload($profile, $viewerProfile),
            ],
            'display' => [
                'contact' => $display['contact'] ?? null,
            ],
        ], 201);
    }

    /**
     * GET /api/v1/suchak-requests
     * Both halves for this member: requests they sent, and — when they are the
     * candidate a Suchak represents — requests waiting on their own answer.
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [, $viewerProfile] = $context;

        $this->pipelineService->expireDuePipelinesForRequestingProfile($viewerProfile);
        $this->pipelineService->expireDuePipelinesForTargetProfile($viewerProfile);

        $relations = ['pipeline', 'representation.suchakAccount.contactNumbers', 'targetMatrimonyProfile'];

        $sent = SuchakProfileRequest::query()
            ->with($relations)
            ->where('requesting_matrimony_profile_id', $viewerProfile->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (SuchakProfileRequest $row): array => $this->presenter->memberRequestPayload($row))
            ->values()
            ->all();

        $received = SuchakProfileRequest::query()
            ->with(array_merge($relations, ['requestingMatrimonyProfile.gender', 'requestingMatrimonyProfile.religion', 'requestingMatrimonyProfile.caste', 'requestingMatrimonyProfile.location']))
            ->where('target_matrimony_profile_id', $viewerProfile->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (SuchakProfileRequest $row): array => $this->presenter->suchakRequestPayload($row))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => __('profile.suchak_request_list_loaded'),
            'data' => [
                'sent' => $sent,
                'received' => $received,
                'decision_options' => $this->decisionOptions(),
            ],
        ]);
    }

    /**
     * POST /api/v1/suchak-requests/{id}/decision
     *
     * The candidate answering for themselves. PO decision: the candidate and the
     * Suchak both see the request and EITHER may answer — first answer wins. The
     * race is settled inside the service under a row lock; a second answer comes
     * back here as a clean, localized "already answered by …", never an error and
     * never a silent overwrite.
     */
    public function decide(Request $request, int $id): JsonResponse
    {
        $context = $this->viewerContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$user, $viewerProfile] = $context;

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(SuchakRequestPipelineService::DECISIONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $suchakRequest = SuchakProfileRequest::query()
            ->with(['pipeline', 'representation', 'targetMatrimonyProfile', 'requestingMatrimonyProfile'])
            ->find($id);

        if (! $suchakRequest instanceof SuchakProfileRequest) {
            return $this->error(__('profile.suchak_request_not_found'), 404);
        }

        if ((int) $suchakRequest->target_matrimony_profile_id !== (int) $viewerProfile->id) {
            return $this->error(__('profile.suchak_request_decision_not_allowed'), 403);
        }

        $this->pipelineService->expireDuePipelinesForTargetProfile($viewerProfile);
        $suchakRequest->refresh();

        try {
            $result = $this->pipelineService->recordCandidateDecision(
                $suchakRequest,
                $user,
                (string) $validated['decision'],
                $validated['note'] ?? null,
                null,
                $request->ip(),
                $request->userAgent(),
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
     * @return array<string, mixed>
     */
    private function routingPayload(MatrimonyProfile $profile, MatrimonyProfile $viewerProfile): array
    {
        $representations = SuchakContactRouting::routableRepresentations($profile);

        $latestByRepresentation = SuchakProfileRequest::query()
            ->with(['pipeline', 'representation.suchakAccount.contactNumbers'])
            ->where('requesting_matrimony_profile_id', $viewerProfile->id)
            ->where('target_matrimony_profile_id', $profile->id)
            ->whereIn('representation_id', $representations->pluck('id')->all() ?: [0])
            ->orderByDesc('id')
            ->get()
            ->groupBy('representation_id')
            ->map(fn ($group) => $group->first());

        return [
            'is_suchak_routed' => SuchakContactRouting::isRouted($profile),
            'target_profile_id' => (int) $profile->id,
            'suchaks' => $representations
                ->map(function (SuchakProfileRepresentation $representation) use ($latestByRepresentation): array {
                    /** @var SuchakProfileRequest|null $latest */
                    $latest = $latestByRepresentation->get($representation->id);
                    $state = $this->presenter->contactStateFor($representation, $latest);

                    return array_merge($this->presenter->suchakBlock($representation), [
                        'state' => $state['state'],
                        'message' => $state['message'],
                        'can_request' => $latest === null || $this->presenter->canResend($latest),
                        'request' => $latest !== null
                            ? $this->presenter->memberRequestPayload($latest)
                            : null,
                    ]);
                })
                ->values()
                ->all(),
        ];
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

    /**
     * @return array{0: User, 1: MatrimonyProfile}|JsonResponse
     */
    private function viewerContext(Request $request): array|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $viewerProfile = $user->matrimonyProfile;
        if (! $viewerProfile instanceof MatrimonyProfile) {
            return $this->error('Please create your profile first.', 422);
        }

        return [$user, $viewerProfile];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
