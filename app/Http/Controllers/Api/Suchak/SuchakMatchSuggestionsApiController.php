<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakMatchSuggestion;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAccessService;
use App\Modules\Suchak\Services\SuchakCandidateMaskingService;
use App\Modules\Suchak\Services\SuchakMatchSuggestionLogService;
use App\Modules\Suchak\Services\SuchakSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Thin mobile adapter over the two committed engines:
 *   - {@see SuchakSuggestionService} ranks + masks (owns scoring and masking),
 *   - {@see SuchakMatchSuggestionLogService} remembers what was SHOWN and what
 *     the Suchak DECIDED (owns the cooling window and the learning log).
 *
 * This controller only composes them: authorise → rank → drop what is still
 * inside the cooling window → record the impressions → flatten for the app.
 * No ranking, no masking and no log-writing rules live here.
 */
class SuchakMatchSuggestionsApiController extends Controller
{
    /** Over-fetch so the cooling-window filter still leaves a full page. */
    private const POOL_OVERSAMPLE = 4;

    private const MAX_LIMIT = 50;

    private const DEFAULT_LIMIT = 12;

    public function __construct(
        private readonly SuchakSuggestionService $suggestionService,
        private readonly SuchakMatchSuggestionLogService $logService,
        private readonly SuchakCandidateMaskingService $maskingService,
    ) {}

    /**
     * GET /api/v1/suchak/representations/{representation}/suggestions
     */
    public function index(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakAccessService $accessService,
    ): JsonResponse {
        $context = $this->authorizedContext($request, $representation, $accessService);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$account, $seeker] = $context;

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'include_seen' => ['nullable', 'boolean'],
        ], [
            'limit.integer' => __('suchak.match_suggestions.validation.limit_invalid'),
            'limit.min' => __('suchak.match_suggestions.validation.limit_invalid'),
            'limit.max' => __('suchak.match_suggestions.validation.limit_invalid'),
            'include_seen.boolean' => __('suchak.match_suggestions.validation.include_seen_invalid'),
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $includeSeen = $request->boolean('include_seen');

        $ranked = $this->suggestionService->suggestionsForRepresentation(
            $account,
            $representation,
            $limit * self::POOL_OVERSAMPLE,
        );

        [$visible, $showingCooledOff] = $this->applyCoolingWindow($ranked, $seeker, $includeSeen);
        $visible = $visible->take($limit)->values();

        // Impression point: exactly the rows this response returns, one run per
        // request. The service's default run key is the per-day bucket, so two
        // calls on the same day are one impression, a later day is a new one.
        if ($visible->isNotEmpty()) {
            $this->logService->recordSuggestions(
                $account,
                $seeker,
                $visible->map(static fn (array $row): array => [
                    'candidate_profile_id' => $row['candidate_profile_id'] ?? null,
                    'score' => $row['match_score'] ?? null,
                    'reasons' => $row['reasons'] ?? null,
                ])->all(),
                null,
                $representation,
            );
        }

        $decisions = $this->decisionsFor($seeker, $visible);
        $seekerSummary = $this->maskingService->maskedSummary($seeker, $representation);

        return response()->json([
            'success' => true,
            'message' => __('suchak.match_suggestions.loaded'),
            'data' => [
                'representation_id' => (int) $representation->id,
                'seeker' => [
                    'profile_id' => (int) $seeker->id,
                    'display_name' => $this->text($seeker->full_name),
                    'age_years' => $seekerSummary['basic']['age_years'] ?? null,
                    'gender' => $seekerSummary['basic']['gender'] ?? null,
                ],
                'count' => $visible->count(),
                'showing_cooled_off' => $showingCooledOff,
                'suggestions' => $visible
                    ->map(fn (array $row): array => $this->presentSuggestion($row, $decisions))
                    ->all(),
            ],
        ]);
    }

    /**
     * POST /api/v1/suchak/representations/{representation}/suggestions/{candidateProfile}/decision
     */
    public function decide(
        Request $request,
        SuchakProfileRepresentation $representation,
        MatrimonyProfile $candidateProfile,
        SuchakAccessService $accessService,
    ): JsonResponse {
        $context = $this->authorizedContext($request, $representation, $accessService);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [, $seeker] = $context;

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(SuchakMatchSuggestion::DECIDED_DECISIONS)],
            'rejection_reason_code' => [
                'nullable',
                'string',
                'required_if:decision,'.SuchakMatchSuggestion::DECISION_REJECTED,
                Rule::in(SuchakMatchSuggestion::REJECTION_REASON_CODES),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'decision.required' => __('suchak.match_suggestions.validation.decision_required'),
            'decision.in' => __('suchak.match_suggestions.validation.decision_invalid'),
            'rejection_reason_code.required_if' => __('suchak.match_suggestions.validation.rejection_reason_required'),
            'rejection_reason_code.in' => __('suchak.match_suggestions.validation.rejection_reason_invalid'),
            'note.max' => __('suchak.match_suggestions.validation.note_too_long'),
        ]);

        $decision = (string) $validated['decision'];
        $reasonCode = $decision === SuchakMatchSuggestion::DECISION_REJECTED
            ? (string) $validated['rejection_reason_code']
            : null;

        // Scoping: the pair is looked up under THIS seeker only, so a candidate
        // that was never suggested for this representation cannot be decided on.
        $row = $this->logService->recordDecisionForPair(
            $seeker,
            $candidateProfile,
            $decision,
            $reasonCode,
            $validated['note'] ?? null,
        );

        if (! $row instanceof SuchakMatchSuggestion) {
            return response()->json([
                'success' => false,
                'error_code' => 'suggestion_not_found',
                'message' => __('suchak.match_suggestions.not_suggested'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => __('suchak.match_suggestions.decision_saved'),
            'data' => [
                'profile_id' => (int) $candidateProfile->id,
                'decision' => $row->decision,
                'rejection_reason_code' => $row->rejection_reason_code,
                'decided_at' => $row->decided_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Product rule (PO): a person shown inside the cooling window (30 days) is
     * never repeated; once nothing NEW is left, people whose cooling period has
     * elapsed reappear instead of an empty screen, and the response says so.
     *
     * Three tiers, in order:
     *   1. suggestedRecently()      — hard exclusion, never returned.
     *   2. never suggested at all   — the preferred list.
     *   3. cooledOffCandidateIds()  — repeats, used ONLY when tier 2 is empty,
     *                                 and then `showing_cooled_off` is true.
     *
     * Tier 2 = (ranked − recent) − cooledOff, so once tier 2 is empty whatever
     * survived the recent-exclusion is by definition the cooled-off set.
     *
     * @param  Collection<int, array<string, mixed>>  $ranked
     * @return array{0: Collection<int, array<string, mixed>>, 1: bool}
     */
    private function applyCoolingWindow(Collection $ranked, MatrimonyProfile $seeker, bool $includeSeen): array
    {
        if ($includeSeen || $ranked->isEmpty()) {
            return [$ranked->values(), false];
        }

        $days = SuchakMatchSuggestion::DEFAULT_COOLING_PERIOD_DAYS;

        $recentIds = $this->logService->suggestedRecently($seeker, $days);
        $showable = $ranked
            ->reject(static fn (array $row): bool => in_array((int) ($row['candidate_profile_id'] ?? 0), $recentIds, true))
            ->values();

        if ($showable->isEmpty()) {
            return [$showable, false];
        }

        $cooledOffIds = $this->logService->cooledOffCandidateIds($seeker, $days);
        $neverSuggested = $showable
            ->reject(static fn (array $row): bool => in_array((int) ($row['candidate_profile_id'] ?? 0), $cooledOffIds, true))
            ->values();

        if ($neverSuggested->isNotEmpty()) {
            return [$neverSuggested, false];
        }

        return [$showable, true];
    }

    /**
     * Latest DECIDED state per candidate for this seeker. Not suchak-scoped on
     * purpose: it must mirror exactly what recordDecisionForPair() writes, which
     * resolves the pair's most recent row.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function decisionsFor(MatrimonyProfile $seeker, Collection $rows): array
    {
        $candidateIds = $rows
            ->map(static fn (array $row): int => (int) ($row['candidate_profile_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($candidateIds === []) {
            return [];
        }

        /** @var array<int, string> $map */
        $map = SuchakMatchSuggestion::query()
            ->forSeeker($seeker)
            ->whereIn('candidate_profile_id', $candidateIds)
            ->decided()
            ->orderBy('decided_at')
            ->orderBy('id')
            ->get(['candidate_profile_id', 'decision'])
            // Ascending order + pluck keeps the LAST (most recent) decision per candidate.
            ->pluck('decision', 'candidate_profile_id')
            ->all();

        return $map;
    }

    /**
     * Flatten one masked suggestion row into the app contract. Built field by
     * field on purpose: the contact block of the masked summary is never copied,
     * so no mobile number can reach the client even by accident.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $decisions
     * @return array<string, mixed>
     */
    private function presentSuggestion(array $row, array $decisions): array
    {
        $candidateId = (int) ($row['candidate_profile_id'] ?? 0);

        return [
            'profile_id' => $candidateId,
            'display_name' => $this->text($row['basic']['display_name'] ?? null),
            'age_years' => $row['basic']['age_years'] ?? null,
            'gender' => $row['basic']['gender'] ?? null,
            'location_label' => $this->locationLabel(is_array($row['location'] ?? null) ? $row['location'] : []),
            'photo_url' => $row['photo']['url'] ?? null,
            'match_score' => (int) ($row['match_score'] ?? 0),
            'fit_label' => $this->text($row['fit_label'] ?? null),
            'reasons' => $this->stringList($row['reasons'] ?? []),
            'warnings' => $this->stringList($row['warnings'] ?? []),
            'source' => $row['source'] ?? null,
            'target_suchak_label' => $row['target_suchak_label'] ?? null,
            'acting_actor' => $row['acting_actor'] ?? null,
            'decision' => $decisions[$candidateId] ?? SuchakMatchSuggestion::DECISION_PENDING,
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function locationLabel(array $location): ?string
    {
        $parts = array_values(array_filter([
            $this->text($location['city'] ?? null),
            $this->text($location['district'] ?? null),
        ]));

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $list = [];
        foreach ($values as $value) {
            $text = $this->text(is_scalar($value) ? (string) $value : null);
            if ($text !== null) {
                $list[] = $text;
            }
        }

        return array_values(array_unique($list));
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * Ownership + consent gate. The representation is always resolved from the
     * authenticated Suchak's own account — no client-sent suchak id is trusted.
     *
     * @return array{0: SuchakAccount, 1: MatrimonyProfile}|JsonResponse
     */
    private function authorizedContext(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakAccessService $accessService,
    ): array|JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null || ! $accessService->canOperate($account)) {
            return response()->json([
                'success' => false,
                'message' => __('suchak.match_suggestions.account_not_allowed'),
            ], 403);
        }

        if ((int) $representation->suchak_account_id !== (int) $account->id) {
            return response()->json([
                'success' => false,
                'message' => __('suchak.match_suggestions.representation_not_found'),
            ], 404);
        }

        $seeker = $representation->matrimonyProfile;
        if (! $seeker instanceof MatrimonyProfile) {
            return response()->json([
                'success' => false,
                'message' => __('suchak.match_suggestions.representation_not_found'),
            ], 404);
        }

        // A pending consent claim discloses nothing at all — not the person, and
        // certainly not who they could be matched with.
        if (! $representation->suchakMayReadProfile()) {
            return $this->consentRequiredResponse($representation);
        }

        return [$account, $seeker];
    }

    private function consentRequiredResponse(SuchakProfileRepresentation $representation): JsonResponse
    {
        $consentId = $representation->consents()
            ->whereIn('consent_status', SuchakConsent::PENDING_ACTION_STATUSES)
            ->latest('id')
            ->value('id');

        return response()->json([
            'success' => false,
            'error_code' => 'consent_required',
            'message' => __('suchak.match_suggestions.consent_required'),
            'data' => [
                'representation_id' => (int) $representation->id,
                'consent_required' => true,
                'consent_status' => $representation->consent_status,
                'representation_mode' => $representation->representation_mode,
                'consent_id' => $consentId !== null ? (int) $consentId : null,
            ],
        ], 403);
    }
}
