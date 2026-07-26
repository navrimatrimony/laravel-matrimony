<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Services\Matching\CandidatePoolStrategy;
use App\Services\Matching\MatchingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Ranked, masked match suggestions for one Suchak-represented candidate.
 *
 * This is the service layer the Suchak API/app will call. It owns no scoring and no masking of its
 * own — it composes the three existing engines:
 *   - {@see MatchingService} with {@see CandidatePoolStrategy::suchakUniverse()} for the pool + score,
 *   - {@see SuchakCandidateMaskingService} for the presentation (mobile number always null),
 *   - the {@see SuchakProfileRepresentation} consent scopes for who may be routed at all.
 */
class SuchakSuggestionService
{
    /** Over-fetch factor so masking/consent drops still leave a full page. */
    private const POOL_OVERSAMPLE = 3;

    public const SOURCE_PLATFORM_MEMBER = 'platform_member';

    public const SOURCE_OWN_CANDIDATE = 'own_candidate';

    public const SOURCE_SUCHAK_REPRESENTED = 'suchak_represented';

    public function __construct(
        private readonly MatchingService $matching,
        private readonly SuchakCandidateMaskingService $maskingService,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakMatchFitService $matchFitService,
    ) {}

    /**
     * Suggestions for `$representation` (which must belong to `$account`), drawn from the combined
     * universe: platform members + every Suchak's publicly routable candidates + this Suchak's own
     * other candidates. Ordered by the engine's score, descending.
     *
     * Every row is a {@see SuchakCandidateMaskingService::maskedSummary()} payload, so `contact.phone`
     * is always null — the mobile number is never returned regardless of who owns the candidate.
     *
     * @return Collection<int, array<string, mixed>>  Each row adds: `candidate_profile_id`, `source`,
     *         `acting_actor`, `representation_id`, `target_suchak_label`, `match_score`,
     *         `match_base_score`, `match_field_points`, `reasons`, `warnings`, `fit_label`,
     *         `fit_summary`, `reason`, `gunamilan`.
     */
    public function suggestionsForRepresentation(
        SuchakAccount $account,
        SuchakProfileRepresentation $representation,
        int $limit = 12,
    ): Collection {
        $limit = max(1, $limit);

        if (! $this->accessService->canOperate($account)) {
            return collect();
        }

        if ((int) $representation->suchak_account_id !== (int) $account->getKey()) {
            return collect();
        }

        $seeker = $representation->matrimonyProfile;
        if (! $seeker instanceof MatrimonyProfile) {
            return collect();
        }

        $rows = $this->matching->findMatchesForPool(
            $seeker,
            CandidatePoolStrategy::suchakUniverse((int) $account->getKey()),
            MatchingService::TAB_PERFECT,
            $limit * self::POOL_OVERSAMPLE,
        );

        if ($rows->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, MatrimonyProfile> $candidateProfiles */
        $candidateProfiles = $rows->map(static fn (array $row): MatrimonyProfile => $row['profile']);
        $representations = $this->visibleRepresentationsFor($account, $candidateProfiles);

        return $rows
            ->map(function (array $row) use ($account, $seeker, $representations): ?array {
                /** @var MatrimonyProfile $candidate */
                $candidate = $row['profile'];

                $candidateRepresentation = $representations->get((int) $candidate->getKey());
                $source = $this->sourceFor($account, $candidate, $candidateRepresentation);

                // A represented candidate with no routable/own representation must never surface.
                if ($source === null) {
                    return null;
                }

                $fit = $this->matchFitService->fit($seeker, $candidate);
                if ($fit === null) {
                    return null;
                }

                $summary = $this->maskingService->maskedSummary($candidate, $candidateRepresentation);
                $summary['basic']['display_name'] = $this->displayName($candidate);
                // Identity of the row. Needed by every consumer that must act on a
                // suggestion — the impression/decision log keys on it, and the API
                // decision endpoint addresses a candidate by it. Contact details
                // stay masked; only the identifier is exposed.
                $summary['candidate_profile_id'] = (int) $candidate->getKey();
                $summary['source'] = $source;
                // Decision C: a Suchak-initiated action on any of these is attributed to the Suchak,
                // except for a self-registered platform member, who acts as themselves.
                $summary['acting_actor'] = $source === self::SOURCE_PLATFORM_MEMBER ? 'member' : 'suchak';
                $summary['representation_id'] = $candidateRepresentation?->getKey();
                $summary['target_suchak_label'] = $this->suchakLabel($candidateRepresentation);
                $summary['reasons'] = $fit['reasons'];
                $summary['warnings'] = $fit['warnings'];
                $summary['fit_label'] = $fit['fit_label'];
                $summary['fit_summary'] = $fit['fit_summary'];
                $summary['reason'] = $fit['reason'];
                $summary['match_score'] = $fit['match_score'];
                $summary['match_base_score'] = $fit['match_base_score'];
                $summary['match_field_points'] = $fit['match_field_points'];
                // गुणमिलन: total/36, the eight kootas, Nadi + Bhakoot dosha and the separate Mangal
                // verdict, so the app can show the whole table. ADDITIVE — a new key only; both
                // Flutter apps' existing keys are untouched.
                $summary['gunamilan'] = $fit['gunamilan'];

                return $summary;
            })
            ->filter()
            ->sortByDesc(static fn (array $row): int => (int) ($row['match_score'] ?? 0))
            ->values()
            ->take($limit);
    }

    /**
     * One representation per candidate profile that this Suchak is allowed to see: its own book first,
     * otherwise a publicly routable representation. Profiles absent from the result are either plain
     * platform members or represented only in ways this Suchak may not route.
     *
     * @param  Collection<int, MatrimonyProfile>  $candidateProfiles
     * @return Collection<int, SuchakProfileRepresentation>  Keyed by matrimony_profile_id.
     */
    private function visibleRepresentationsFor(SuchakAccount $account, Collection $candidateProfiles): Collection
    {
        $profileIds = $candidateProfiles
            ->map(static fn (MatrimonyProfile $p): int => (int) $p->getKey())
            ->unique()
            ->values()
            ->all();

        if ($profileIds === []) {
            return collect();
        }

        $ownAccountId = (int) $account->getKey();

        return SuchakProfileRepresentation::query()
            ->with('suchakAccount')
            ->whereIn('matrimony_profile_id', $profileIds)
            ->where(function (Builder $visible) use ($ownAccountId): void {
                $visible
                    ->where(function (Builder $routable): void {
                        $routable->publiclyRoutable();
                    })
                    ->orWhere(function (Builder $own) use ($ownAccountId): void {
                        $own->where('suchak_account_id', $ownAccountId)
                            ->whereNull('revoked_at')
                            ->whereNull('candidate_deactivated_at')
                            ->excludingPendingConsentClaims();
                    });
            })
            ->orderByDesc('first_verified_consent_at')
            ->orderByDesc('id')
            ->get()
            ->sortByDesc(static fn (SuchakProfileRepresentation $r): int => (int) $r->suchak_account_id === $ownAccountId ? 1 : 0)
            ->keyBy(static fn (SuchakProfileRepresentation $r): int => (int) $r->matrimony_profile_id);
    }

    /**
     * @return string|null  Null means "represented but not visible to this Suchak" — drop the row.
     */
    private function sourceFor(
        SuchakAccount $account,
        MatrimonyProfile $candidate,
        ?SuchakProfileRepresentation $representation,
    ): ?string {
        if ($representation instanceof SuchakProfileRepresentation) {
            return (int) $representation->suchak_account_id === (int) $account->getKey()
                ? self::SOURCE_OWN_CANDIDATE
                : self::SOURCE_SUCHAK_REPRESENTED;
        }

        // No visible representation: only legitimate for a self-registered, activated platform member.
        $isActivatedMember = $candidate->lifecycle_state === 'active' && ! $candidate->is_suspended;

        return $isActivatedMember ? self::SOURCE_PLATFORM_MEMBER : null;
    }

    private function displayName(MatrimonyProfile $candidate): ?string
    {
        $name = trim((string) ($candidate->full_name ?? ''));

        return $name !== '' ? $name : null;
    }

    private function suchakLabel(?SuchakProfileRepresentation $representation): ?string
    {
        if (! $representation instanceof SuchakProfileRepresentation) {
            return null;
        }

        $name = trim((string) ($representation->suchakAccount?->suchak_name ?: 'Public Suchak'));

        return '#'.$representation->suchak_account_id.' '.Str::limit($name, 80, '');
    }
}
