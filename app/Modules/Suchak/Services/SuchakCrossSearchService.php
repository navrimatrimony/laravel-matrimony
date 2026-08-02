<?php

namespace App\Modules\Suchak\Services;

use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Services\IncomeEngineService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakCrossSearchService
{
    /**
     * The filters that are refused on a CROSS-SUCHAK read and honoured only on the caller's OWN book.
     *
     * `name` is here because D19a hides the candidate's name from another Suchak by default. A name
     * filter over masked rows does not show the name — it CONFIRMS it: send `name=sun`, count the
     * rows, send `name=suni`, count again, and the mask is peeled one letter at a time against a
     * corpus the originating Suchak deliberately did not open. Hiding a value while shipping an
     * oracle for it is not a privacy default, it is a slower one. D7a asks for name search when a
     * Suchak is picking from HIS OWN two hundred candidates, which is where it is granted and where
     * nothing is masked in the first place.
     *
     * `income_min` / `income_max` are here for the SAME reason, and were not, which was a hole. No
     * income figure appears anywhere on a masked card — SuchakCandidateMaskingService prints none —
     * so unlike location there is no visible version of the fact to fall back on: the filter was the
     * ONLY channel, and a binary search over the bounds walked an exact salary out of a corpus that
     * shows none. Gating it on the candidate's `income_private` flag was not enough either, because a
     * candidate who never asked for privacy still has an income another Suchak is not shown. Whether
     * a value is filterable follows from whether it is READABLE on that surface, not from a separate
     * flag.
     *
     * Location is deliberately NOT here, and is instead constrained by LEVEL — see
     * CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY. District and taluka ARE sent to another Suchak
     * today by SuchakCandidateMaskingService::locationSlot(), so filtering by them discloses nothing
     * the card does not; the village below them is one of D19a's four hidden items, so an id at that
     * level is refused exactly as a name is.
     *
     * @var list<string>
     */
    public const OWN_BOOK_ONLY_FILTERS = [
        'name',
        'income_min',
        'income_max',
    ];

    /**
     * The most precise `addresses` level a CROSS-SUCHAK read may be filtered by.
     *
     * SuchakCandidateMaskingService::locationSlot() sends another Suchak the district always and the
     * taluka in the `city` slot, and drops to the village only when the originating Suchak set
     * `shares_village`. So taluka is the deepest level the masked card is guaranteed to have already
     * printed, and an ancestor id at or above it can narrow nothing the reader was not shown. An id
     * BELOW it — a village — is an oracle: the search accepts an ancestor at any depth, so passing a
     * village id filters the page down to the candidates in that village and recovers, by counting
     * rows, the one location value D19a hides by default.
     *
     * The level is read from the row itself (`addresses.hierarchy`) and compared through
     * Location::defaultLevelForHierarchy(), the single owner of the country → state → district →
     * taluka → village scale. No level integer is written down here.
     */
    public const CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY = 'taluka';

    public function __construct(
        private readonly SuchakCandidateMaskingService $maskingService,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakMatchFitService $matchFitService,
        private readonly IncomeEngineService $incomeEngine,
    ) {
    }

    public function canSearch(SuchakAccount $account): bool
    {
        return $this->accessService->canOperate($account);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(SuchakAccount $actorAccount, array $filters = []): LengthAwarePaginator
    {
        $actorAccount->refresh();
        $this->accessService->assertCanOperate(
            $actorAccount,
            'Only verified Suchak accounts can use masked search.',
        );
        $selectedOwnRepresentation = $this->selectedOwnRepresentation($actorAccount, $filters);

        $query = SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.visibilitySetting',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
            ])
            ->publiclyRoutable()
            ->where('suchak_account_id', '!=', (int) $actorAccount->id)
            ->whereHas('matrimonyProfile', function (Builder $query) use ($filters): void {
                $this->applyProfileFilters($query, $filters);
            })
            ->orderByDesc('first_verified_consent_at')
            ->orderByDesc('id');

        return $query
            ->paginate(12)
            ->withQueryString()
            ->through(function (SuchakProfileRepresentation $representation) use ($selectedOwnRepresentation): array {
                /** @var MatrimonyProfile $profile */
                $profile = $representation->matrimonyProfile;
                $summary = $this->maskingService->maskedSummary($profile, $representation);
                $suchakName = trim((string) ($representation->suchakAccount?->suchak_name ?: 'Public Suchak'));
                $summary['target_suchak_label'] = '#'.$representation->suchak_account_id.' '.Str::limit($suchakName, 80, '');
                $summary = array_merge($summary, $this->fitSummary($selectedOwnRepresentation, $representation));

                return $summary;
            });
    }

    /**
     * The caller's OWN represented candidates, filtered — the one query behind every own-book read.
     *
     * Two readers took no filters at all before D7a: this class's own-profile picker and
     * SuchakCustomerListService::rowsForAccount(). D7a's ruling is that a working Suchak may hold two
     * hundred candidates and that scrolling is not a selection mechanism, so the filters live HERE,
     * on the same applyProfileFilters() the cross-Suchak search uses, rather than as a private copy
     * inside whichever screen asked for them second.
     *
     * `$filters` is passed with the own-book flag set, which is what unlocks the name and income
     * filters (OWN_BOOK_ONLY_FILTERS), lets an income filter see a candidate who marked his income
     * private — his own Suchak typed that number and already sees it on the edit screen — and lets
     * the location filter take an id at any level, village included.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<SuchakProfileRepresentation>
     */
    public function ownRepresentationsQuery(SuchakAccount $actorAccount, array $filters = []): Builder
    {
        return SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.visibilitySetting',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
            ])
            ->withValidConsent()
            ->where('suchak_account_id', (int) $actorAccount->id)
            ->whereHas('matrimonyProfile', function (Builder $query) use ($filters): void {
                $this->applyProfileFilters($query, $filters, true);
            })
            ->orderByDesc('first_verified_consent_at')
            ->orderByDesc('id');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function ownRepresentationOptions(SuchakAccount $actorAccount): Collection
    {
        return $this->ownRepresentationsQuery($actorAccount)
            ->get()
            ->map(function (SuchakProfileRepresentation $representation): array {
                /** @var MatrimonyProfile $profile */
                $profile = $representation->matrimonyProfile;
                $summary = $this->maskingService->maskedSummary($profile, $representation);
                $summary['own_profile'] = $this->ownRepresentationOptionMeta($profile, $summary);
                $summary['option_label'] = $this->ownRepresentationOptionLabel($profile, $summary);

                return $summary;
            });
    }

    /**
     * THE one candidate-filter owner. Both the cross-Suchak search and the own-book reads run through
     * here, so a filter added for one is available to the other and the two can never disagree about
     * what `age_min` means.
     *
     * `$ownBook` says the rows being filtered belong to the CALLER. It is not a permission flag —
     * ownership is established by the query that calls this (`where suchak_account_id = ...`) — it is
     * the answer to "is anything about these rows hidden from this reader?". Nothing is hidden from a
     * Suchak about his own candidates, so three filters behave differently under it: `name` and the
     * income bounds exist at all (see OWN_BOOK_ONLY_FILTERS), and the location filter accepts an
     * ancestor at any depth instead of stopping at CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY.
     *
     * The rule those three share, and the one to apply to the next filter added here: a filter may
     * narrow only by a fact the reader could already have READ on that surface. Otherwise the count
     * is a read channel for a value the card deliberately withheld, and the withholding is decorative.
     *
     * @param  Builder<MatrimonyProfile>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyProfileFilters(Builder $query, array $filters, bool $ownBook = false): void
    {
        $this->applyActiveProfileScope($query);

        $genderId = (int) ($filters['gender_id'] ?? 0);
        if ($genderId > 0) {
            $query->where('gender_id', $genderId);
        }

        $casteId = (int) ($filters['caste_id'] ?? 0);
        if ($casteId > 0) {
            $query->where('caste_id', $casteId);
        }

        $religionId = (int) ($filters['religion_id'] ?? 0);
        if ($religionId > 0) {
            $query->where('religion_id', $religionId);
        }

        $maritalStatusId = (int) ($filters['marital_status_id'] ?? 0);
        if ($maritalStatusId > 0) {
            $query->where('marital_status_id', $maritalStatusId);
        }

        $ageMin = (int) ($filters['age_min'] ?? 0);
        if ($ageMin >= 18) {
            $query->whereDate('date_of_birth', '<=', Carbon::now()->subYears($ageMin)->toDateString());
        }

        $ageMax = (int) ($filters['age_max'] ?? 0);
        if ($ageMax >= 18) {
            $query->whereDate('date_of_birth', '>=', Carbon::now()->subYears($ageMax + 1)->addDay()->toDateString());
        }

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = $this->likeTerm($term);
            $query->where(function (Builder $query) use ($like): void {
                $query
                    ->where('highest_education', 'like', $like)
                    ->orWhereHas('occupationMaster', function (Builder $query) use ($like): void {
                        $query->where('name', 'like', $like);
                    });
            });
        }

        // EDUCATION — the narrow half of `q`. `q` matches education OR occupation, which is right for
        // "find me something like this" and wrong for "everyone with a B.Ed", where the occupation
        // hits are noise the Suchak has to read past. Same column, one predicate, no second store.
        $education = trim((string) ($filters['education'] ?? ''));
        if ($education !== '') {
            $query->where('highest_education', 'like', $this->likeTerm($education));
        }

        // NAME (D7a). Own book only — see OWN_BOOK_ONLY_FILTERS for why it must not reach a
        // cross-Suchak read. `full_name` is a single column by design (D19d): there is no first/last
        // split to search separately, so a partial over the whole string is the only honest match.
        $name = trim((string) ($filters['name'] ?? ''));
        if ($ownBook && $name !== '') {
            $query->where('full_name', 'like', $this->likeTerm($name));
        }

        // LOCATION (D7a). The walk is scopeWhereResidenceUnderAncestor(), the recursive `addresses`
        // chain the member search, duplicate detection and profile reads all use; there is no second
        // walk here and no assumption about how many levels sit between the leaf and the ancestor.
        //
        // That depth-blindness is exactly why the id has to be checked: the scope accepts an ancestor
        // at ANY level, so on a cross-Suchak read a village id sent under either key narrows the page
        // to that village and reads back the value the mask refuses to print. Both keys go through
        // crossSearchableAncestorId(), which is the level rule
        // (CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY); a rejected id filters nothing, the same
        // silent refusal `name` gets, rather than a 422 that would confirm the level to the prober.
        // The own book skips the check entirely — nothing there is hidden from its own Suchak, so
        // D7a's location filter keeps working at every level, village included.
        $districtId = $this->crossSearchableAncestorId($filters['district_id'] ?? null, $ownBook);
        if ($districtId > 0) {
            $query->whereResidenceUnderAncestor($districtId);
        }

        $talukaId = $this->crossSearchableAncestorId($filters['taluka_id'] ?? null, $ownBook);
        if ($talukaId > 0) {
            $query->whereResidenceUnderAncestor($talukaId);
        }

        // INCOME (D7a) — OWN BOOK ONLY (OWN_BOOK_ONLY_FILTERS). A cross-Suchak card carries no income
        // at all, so a cross-Suchak income filter answered a question the surface never asked: the
        // count alone binary-searched the exact figure out. There is no `income_private` test left
        // here on purpose — it used to be the guard and it was the wrong one, because it protected
        // only the candidates who had set the flag while the rest were equally unreadable and equally
        // recoverable. The single rule is ownership.
        //
        // One resolution rule for the figure itself, IncomeEngineService::comparableAnnualSql(), so
        // this filter and the number printed beside its results are the same number. See that method
        // for why the normalized column alone would have returned nothing for the Suchak corpus.
        $incomeMin = $ownBook ? $this->positiveAmount($filters['income_min'] ?? null) : null;
        $incomeMax = $ownBook ? $this->positiveAmount($filters['income_max'] ?? null) : null;

        if ($incomeMin !== null || $incomeMax !== null) {
            $amountSql = $this->incomeEngine->comparableAnnualSql($query->getModel()->getTable());

            // NOT NULL on the RESOLVED figure, never on one column: a Suchak-created candidate has
            // the flat column only, and a NOT NULL on the normalized one would drop him silently.
            $query->whereRaw($amountSql.' IS NOT NULL');

            /*
             * CAST on the BOUND value, not decoration. PDO has no float parameter type, so Laravel
             * binds a PHP float as PDO::PARAM_STR — and SQLite then compares a NUMERIC column against
             * a TEXT parameter, where every number sorts below every string. `900000 >= '500000'` is
             * FALSE under that rule, so an uncast `>= ?` returned an empty list for a candidate who
             * plainly qualified: a filter that silently finds nothing, which reads as "nobody earns
             * that much" rather than as a bug. Caught by the income test.
             */
            if ($incomeMin !== null) {
                $query->whereRaw($amountSql.' >= CAST(? AS DECIMAL(14,2))', [$incomeMin]);
            }

            if ($incomeMax !== null) {
                $query->whereRaw($amountSql.' <= CAST(? AS DECIMAL(14,2))', [$incomeMax]);
            }
        }
    }

    /**
     * The submitted `addresses` id if this reader may filter by it, otherwise 0 (no location filter).
     *
     * On the own book every id passes: D7a's location filter is unrestricted over a Suchak's own
     * candidates. On a cross-Suchak read the id must sit at or above
     * CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY — country, state, district or taluka — because
     * those are the levels the masked card already prints.
     *
     * The level comes from the row's own `addresses.hierarchy`, run through
     * Location::defaultLevelForHierarchy(): that method is where the country → state → district →
     * taluka → village scale is defined and where the model writes `addresses.level` from on save, so
     * reading it here keeps ONE definition of "how deep is this" rather than a second copy that could
     * drift from the column. An id that does not exist, or whose hierarchy is not on that scale, is
     * refused — an unresolvable id must not fall through as "allowed".
     */
    private function crossSearchableAncestorId(mixed $value, bool $ownBook): int
    {
        $addressId = (int) ($value ?? 0);
        if ($addressId <= 0) {
            return 0;
        }

        if ($ownBook) {
            return $addressId;
        }

        $hierarchy = trim((string) Location::query()->whereKey($addressId)->value('hierarchy'));
        if ($hierarchy === '') {
            return 0;
        }

        try {
            $level = Location::defaultLevelForHierarchy($hierarchy);
            $narrowest = Location::defaultLevelForHierarchy(self::CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY);
        } catch (InvalidArgumentException) {
            return 0;
        }

        return $level <= $narrowest ? $addressId : 0;
    }

    /** One escaped LIKE term, so a `%` typed by a Suchak stays a literal `%` in every filter. */
    private function likeTerm(string $term): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
    }

    private function positiveAmount(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return $amount >= 0.0 ? $amount : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function selectedOwnRepresentation(SuchakAccount $actorAccount, array $filters): ?SuchakProfileRepresentation
    {
        $selectedId = (int) ($filters['requesting_representation_id'] ?? 0);
        if ($selectedId <= 0) {
            return null;
        }

        return SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.visibilitySetting',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
            ])
            ->withValidConsent()
            ->whereKey($selectedId)
            ->where('suchak_account_id', (int) $actorAccount->id)
            ->whereHas('matrimonyProfile', function (Builder $query): void {
                $this->applyActiveProfileScope($query);
            })
            ->first();
    }

    /**
     * Delegates entirely to {@see SuchakMatchFitService} — i.e. to the real matching engine. The key
     * set is unchanged so the existing search UI keeps rendering; the values are now the same weighted
     * score, field breakdown and explain reasons that members get.
     *
     * @return array{reasons: array<int, string>, warnings: array<int, string>, fit_label: string, fit_summary: string}
     */
    private function fitSummary(
        ?SuchakProfileRepresentation $selectedOwnRepresentation,
        SuchakProfileRepresentation $candidateRepresentation,
    ): array {
        if (! $selectedOwnRepresentation instanceof SuchakProfileRepresentation) {
            return [
                'reasons' => [],
                'warnings' => [],
                'fit_label' => 'Select your side profile',
                'fit_summary' => 'Select your represented profile above to compare fit signals.',
            ];
        }

        $ownProfile = $selectedOwnRepresentation->matrimonyProfile;
        $candidateProfile = $candidateRepresentation->matrimonyProfile;
        if (! $ownProfile instanceof MatrimonyProfile || ! $candidateProfile instanceof MatrimonyProfile) {
            return [
                'reasons' => [],
                'warnings' => ['Profile summary unavailable for comparison.'],
                'fit_label' => 'Review manually',
                'fit_summary' => 'Fit signals could not be calculated.',
            ];
        }

        // The candidate is the masked side — this whole method runs on rows drawn with
        // `where suchak_account_id != <caller>`. Passing its representation is what stops the fit
        // explanation resolving finer than the card built from the SAME row three lines earlier in
        // search(); see SuchakMatchFitService::fit().
        $fit = $this->matchFitService->fit($ownProfile, $candidateProfile, $candidateRepresentation);
        if ($fit === null) {
            return [
                'reasons' => [],
                'warnings' => ['No compatible match signal found.'],
                'fit_label' => 'Review manually',
                'fit_summary' => 'The matching engine found no compatible fit for the selected profile.',
            ];
        }

        return [
            'reasons' => $fit['reasons'],
            'warnings' => $fit['warnings'],
            'fit_label' => $fit['fit_label'],
            'fit_summary' => $fit['fit_summary'],
            'match_score' => $fit['match_score'],
            'match_base_score' => $fit['match_base_score'],
            'match_field_points' => $fit['match_field_points'],
        ];
    }

    private function applyActiveProfileScope(Builder $query): void
    {
        $query
            ->where('lifecycle_state', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('is_suspended')->orWhere('is_suspended', false);
            });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function ownRepresentationOptionLabel(MatrimonyProfile $profile, array $summary): string
    {
        $community = collect([
            $summary['community']['religion'] ?? null,
            $summary['community']['caste'] ?? null,
        ])->filter()->implode(' / ');
        $location = collect([
            $summary['location']['city'] ?? null,
            $summary['location']['district'] ?? null,
        ])->filter()->implode(', ');

        $age = isset($summary['basic']['age_years'])
            ? $summary['basic']['age_years'].' years'
            : null;

        return collect([
            trim((string) ($profile->full_name ?? '')) ?: ($summary['basic']['gender'] ?? 'Represented profile'),
            $age,
            $summary['basic']['gender'] ?? null,
            $community !== '' ? $community : null,
            $location !== '' ? $location : null,
        ])->filter()->implode(' · ');
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function ownRepresentationOptionMeta(MatrimonyProfile $profile, array $summary): array
    {
        $location = collect([
            $summary['location']['city'] ?? null,
            $summary['location']['district'] ?? null,
        ])->filter()->implode(', ');

        $educationJob = collect([
            $summary['education']['highest'] ?? null,
            $summary['occupation']['broad'] ?? null,
        ])->filter()->implode(' / ');

        $age = isset($summary['basic']['age_years'])
            ? $summary['basic']['age_years'].' years'
            : null;

        $name = trim((string) ($profile->full_name ?? ''));

        return [
            'name' => $name !== '' ? $name : 'Represented profile',
            'gender' => $summary['basic']['gender'] ?? null,
            'age' => $age,
            'location' => $location !== '' ? $location : null,
            'education_job' => $educationJob !== '' ? $educationJob : null,
            'photo_url' => $summary['photo']['url']
                ?? $summary['photo']['placeholder_url']
                ?? asset('images/placeholders/default-profile.svg'),
        ];
    }
}
