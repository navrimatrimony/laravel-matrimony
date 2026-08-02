<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\Location;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCrossSearchService;
use App\Modules\Suchak\Services\SuchakMatchFitService;
use App\Services\Matching\MatchingService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D19a — a masked value must not be recoverable from ANY answer the cross-Suchak surface gives back.
 *
 * Three attacks were proven, and all three are kept here as the refusal, not as history. The first
 * two read the value out of a RESULT COUNT; the third reads it out of the FIT EXPLANATION, which is
 * why this file is not named after filters.
 *
 *  1. LOCATION. scopeWhereResidenceUnderAncestor() walks `addresses.parent_id` upward and accepts an
 *     ancestor at ANY depth, so a VILLAGE id sent as `district_id` (or `taluka_id`) narrowed the page
 *     to the candidates in that village — reading back the village D19a hides while the card, quite
 *     correctly, printed only the taluka. Fixed by constraining the LEVEL of the submitted id, not by
 *     removing location filtering: district and taluka are on the card already and must keep working.
 *
 *  2. INCOME. No income figure appears anywhere on a masked card, so the filter was the only channel
 *     and 23 requests of binary search over `income_min` walked out an exact salary. There is no
 *     visible version of this fact to fall back to, so there is no level to constrain: income is
 *     OWN-BOOK ONLY, the rule SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS already applied to name.
 *
 * A single-request test would not have caught either one — what leaks is the SEQUENCE. So both
 * attacks keep their sweep: the assertion is that every probe in the sweep returns the same corpus.
 *
 *  3. LOCATION AGAIN, THROUGH THE SCORE — and this one needs no counting at all. The response that
 *     masks the village also carries the fit explanation, and the explanation confirmed it:
 *     MatchingService::scoreLocationPart() gave an exact `location_id` match the FULL location weight
 *     with the reason "same city", and a taluka-only match 90% with "same taluka". So one own
 *     candidate per village under the shown taluka, each passed as `requesting_representation_id`,
 *     and `match_field_points['location']` names the hidden village — one request per guess, no
 *     sweep of the corpus. Hiding a value on the card while the score printed beside it resolves
 *     finer is the same defect as filtering by it.
 *
 *     Fixed by CAPPING PRECISION, never by deleting the signal: the exact-match tier collapses into
 *     the taluka tier, so location still scores and still ranks — it simply stops resolving below
 *     what the reader was shown. Where the originating Suchak set `shares_village` the village is on
 *     the card, so full precision is correct and is asserted to come back.
 *
 * D19b is the boundary that third fix must not cross, and the last two tests hold it: the MEMBER
 * path keeps the exact-village tier, because a member is choosing for themselves rather than sourcing
 * matches, and their own matching must not be degraded to protect a matchmaker's corpus.
 *
 * D7a is not weakened either. Its own test pins that the own-book picker still filters by name,
 * location and income, at every location level. Its HTTP surface (`GET .../my-candidates`) is covered
 * by SuchakOwnCandidateSearchTest; what is asserted here is the one query behind it.
 */
class SuchakCrossSearchOracleTest extends TestCase
{
    use RefreshDatabase;

    /** The salary the probe recovered. Nothing in a masked read may narrow towards it. */
    private const SECRET_INCOME = 743000;

    private const OTHER_INCOME = 220000;

    /** The sweep ceiling. A binary search that learns nothing must run all the way up to it. */
    private const INCOME_SWEEP_CEILING = 5000000;

    protected function setUp(): void
    {
        parent::setUp();

        // MatrimonyProfile::$leafGeoBundleMemo is a static lookaside keyed by `addresses.id`, and
        // RefreshDatabase hands the same id to a different place in the next test.
        MatrimonyProfile::flushLeafGeoMemo();
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    // ── Attack 1: the village oracle ──────────────────────────────────────────────────────────

    public function test_a_village_id_never_narrows_the_cross_suchak_search_under_either_key(): void
    {
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [, $stranger] = $this->verifiedSuchakActor();

        $pune = $this->district('Pune');
        $shirur = $this->taluka('Shirur', $pune);
        $ranjangaon = $this->village('Ranjangaon', $shirur);
        $kendur = $this->village('Kendur', $shirur);
        $haveli = $this->taluka('Haveli', $pune);
        $wagholi = $this->village('Wagholi', $haveli);
        $nashik = $this->district('Nashik');
        $panchale = $this->village('Panchale', $this->taluka('Sinnar', $nashik));

        $this->representedCandidate($stranger, 'Sunita Gaikwad', $ranjangaon);
        $this->representedCandidate($stranger, 'Rahul Kadam', $kendur);
        $this->representedCandidate($stranger, 'Nilam Shinde', $wagholi);
        $this->representedCandidate($stranger, 'Amit Pawar', $panchale);

        Sanctum::actingAs($helperUser);

        $this->assertSame(4, $this->crossTotal());

        // The premise. The card carries the TALUKA in `city` and says so with is_broad — the village
        // is the thing D19a withholds, which is the only reason a village-level filter is an oracle.
        $card = $this->getJson('/api/v1/suchak/search?taluka_id='.$shirur)->assertOk()->json('data.results.0');
        $this->assertSame('Shirur', $card['location']['city'] ?? null);
        $this->assertTrue($card['location']['is_broad'] ?? false);
        $this->assertNotSame('Ranjangaon', $card['location']['city'] ?? null);

        // THE ATTACK. Both keys reach the same ancestor walk, and the walk climbs from the leaf, so
        // either one used to accept a village id and cut the page down to that village.
        foreach (['district_id', 'taluka_id'] as $key) {
            foreach ([$ranjangaon, $kendur, $wagholi, $panchale] as $villageId) {
                $this->assertSame(
                    4,
                    $this->crossTotal([$key => $villageId]),
                    "ORACLE: a village id under `{$key}` narrowed a cross-Suchak read.",
                );
            }
        }

        // An id that resolves to nothing must not fall through as "allowed" either.
        $this->assertSame(4, $this->crossTotal(['district_id' => 99999999]));

        // AND THE FILTER IS NOT DEAD. District and taluka are printed on the card, so both still
        // narrow — the fix is a level rule, not the removal of location search.
        $this->assertSame(3, $this->crossTotal(['district_id' => $pune]));
        $this->assertSame(1, $this->crossTotal(['district_id' => $nashik]));
        $this->assertSame(2, $this->crossTotal(['taluka_id' => $shirur]));
        $this->assertSame(1, $this->crossTotal(['taluka_id' => $haveli]));

        // A refused id drops ITSELF, never the legitimate filter sent beside it.
        $this->assertSame(3, $this->crossTotal(['district_id' => $pune, 'taluka_id' => $ranjangaon]));

        $this->assertSame('taluka', SuchakCrossSearchService::CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY);

        // AND THE OWNER REFUSES IT, not merely the route that happened to be called. search() takes
        // the filter array straight, so this covers every cross-Suchak entrance at once — the mobile
        // API, the web page at GET /suchak/search, and whatever is wired to it next.
        $this->assertSame($this->crossSearchTotalViaService($helper, ['district_id' => $ranjangaon]), 4);
        $this->assertSame($this->crossSearchTotalViaService($helper, ['taluka_id' => $ranjangaon]), 4);
        $this->assertSame($this->crossSearchTotalViaService($helper, ['taluka_id' => $shirur]), 2);
    }

    public function test_a_village_id_cannot_be_walked_down_to_by_sweeping_ids_around_a_taluka(): void
    {
        [$helperUser] = $this->verifiedSuchakActor();
        [, $stranger] = $this->verifiedSuchakActor();

        $shirur = $this->taluka('Shirur', $this->district('Pune'));
        $villages = [
            $this->village('Ranjangaon', $shirur),
            $this->village('Kendur', $shirur),
            $this->village('Nimgaon', $shirur),
            $this->village('Talegaon', $shirur),
        ];

        // One candidate, hidden among four villages of one taluka. The card says "Shirur"; a sweep of
        // the taluka's children is the cheapest possible walk from what is shown to what is not.
        $this->representedCandidate($stranger, 'Sunita Gaikwad', $villages[2]);

        Sanctum::actingAs($helperUser);
        $this->assertSame(1, $this->crossTotal());

        $counts = [];
        foreach ($villages as $villageId) {
            $counts[$villageId] = $this->crossTotal(['taluka_id' => $villageId]);
        }

        // Every child answers identically, so the sweep separates nothing: the reader ends where he
        // started, knowing only the taluka the card told him.
        $this->assertSame(
            [1, 1, 1, 1],
            array_values($counts),
            'ORACLE: sweeping the villages under a shown taluka told the reader which one it is.',
        );
    }

    // ── Attack 2: the income binary search ────────────────────────────────────────────────────

    public function test_a_binary_search_over_income_cannot_recover_a_masked_salary(): void
    {
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [, $stranger] = $this->verifiedSuchakActor();

        $this->representedCandidate($stranger, 'Rich Candidate', $this->defaultVillage(), [
            'annual_income' => self::SECRET_INCOME,
            'income_private' => false,
        ]);
        $this->representedCandidate($stranger, 'Modest Candidate', $this->defaultVillage(), [
            'annual_income' => self::OTHER_INCOME,
            'income_private' => false,
        ]);

        Sanctum::actingAs($helperUser);
        $this->assertSame(2, $this->crossTotal());

        // The premise: the masked card prints no income at all. If that ever changes, this fails
        // first and the filter rule must be revisited before the card is — a value that becomes
        // readable may become filterable, never the other way round.
        $body = json_encode($this->getJson('/api/v1/suchak/search')->assertOk()->json('data.results'));
        $this->assertIsString($body);
        $this->assertStringNotContainsStringIgnoringCase('income', $body);

        // THE ATTACK, run against BOTH locks, because they are different locks and each can rot on
        // its own: the route (which no longer accepts the key at all) and the filter owner behind it
        // (reached with the key intact, exactly as the web page and any future caller reach it).
        // Each sweep chases the two thresholds a prober would: the highest bound at which BOTH
        // candidates survive — the lower salary — and the highest at which ANY does, the higher one.
        foreach ([
            'the route' => fn (int $bound): int => $this->crossTotal(['income_min' => $bound]),
            'the filter owner' => fn (int $bound): int => $this->crossSearchTotalViaService($helper, ['income_min' => $bound]),
        ] as $lock => $counter) {
            $observed = [];
            $bothSurvive = $this->highestIncomeBoundWhere($counter, $observed, static fn (int $n): bool => $n >= 2);
            $anySurvives = $this->highestIncomeBoundWhere($counter, $observed, static fn (int $n): bool => $n >= 1);

            $this->assertGreaterThan(20, count($observed), 'The sweep must actually have run.');
            $this->assertSame(
                [2],
                array_values(array_unique($observed)),
                "ORACLE: an income bound moved the result count through {$lock}.",
            );

            // Both searches run off the top of the range instead of settling on a salary.
            $this->assertSame(self::INCOME_SWEEP_CEILING, $bothSurvive);
            $this->assertSame(self::INCOME_SWEEP_CEILING, $anySurvives);
            $this->assertNotSame(self::SECRET_INCOME, $anySurvives);
            $this->assertNotSame(self::OTHER_INCOME, $bothSurvive);
        }

        // The other half of the range, in case only one bound was ever gated.
        foreach ([1, self::OTHER_INCOME, self::SECRET_INCOME, self::INCOME_SWEEP_CEILING] as $bound) {
            $this->assertSame(2, $this->crossTotal(['income_max' => $bound]));
            $this->assertSame(2, $this->crossTotal(['income_min' => $bound, 'income_max' => $bound]));
            $this->assertSame(2, $this->crossSearchTotalViaService($helper, ['income_max' => $bound]));
        }

        $this->assertContains('income_min', SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS);
        $this->assertContains('income_max', SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS);
    }

    public function test_the_income_refusal_does_not_depend_on_the_candidate_asking_for_privacy(): void
    {
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [, $stranger] = $this->verifiedSuchakActor();

        // The flag was the old guard, and it was the wrong one: it protected only the candidates who
        // had set it, while every other salary was equally unprinted and equally recoverable.
        $this->representedCandidate($stranger, 'Never Asked', $this->defaultVillage(), [
            'annual_income' => self::SECRET_INCOME,
            'income_private' => false,
        ]);

        Sanctum::actingAs($helperUser);
        $this->assertSame(1, $this->crossTotal());

        foreach ([100000, 700000, 742999, self::SECRET_INCOME, 743001, 2000000] as $bound) {
            $this->assertSame(
                1,
                $this->crossTotal(['income_min' => $bound]),
                "ORACLE: income_min={$bound} moved the route count for a candidate who never set income_private.",
            );

            // Through the owner as well: the route dropping the key would otherwise let this test
            // keep passing while the rule it names had been deleted.
            $this->assertSame(
                1,
                $this->crossSearchTotalViaService($helper, ['income_min' => $bound]),
                "ORACLE: income_min={$bound} moved the owner count for a candidate who never set income_private.",
            );
        }
    }

    // ── Attack 3: the village read out of the fit explanation ─────────────────────────────────

    public function test_a_village_cannot_be_read_out_of_the_fit_explanation(): void
    {
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [, $stranger] = $this->verifiedSuchakActor();

        $shirur = $this->taluka('Shirur', $this->district('Pune'));
        $villages = [
            'Ranjangaon' => $this->village('Ranjangaon', $shirur),
            'Kendur' => $this->village('Kendur', $shirur),
            'Nimgaon' => $this->village('Nimgaon', $shirur),
            'Talegaon' => $this->village('Talegaon', $shirur),
        ];

        // THE SECRET: one candidate of another Suchak, on D19a's defaults, living in Nimgaon.
        $target = $this->representedCandidate($stranger, 'Sunita Gaikwad', $villages['Nimgaon'], [
            'gender_id' => $this->genderId('female'),
        ]);

        // THE PROBES: one candidate of the ATTACKER's own, in every village under the taluka the card
        // does print. He creates these himself, so placing them costs him nothing.
        $probes = [];
        foreach ($villages as $name => $villageId) {
            $probes[$name] = $this->representedCandidate($helper, 'Probe '.$name, $villageId, [
                'gender_id' => $this->genderId('male'),
            ]);
        }

        Sanctum::actingAs($helperUser);
        $this->assertSame(1, $this->crossTotal());

        // The premise, again: the card places her in Shirur and says so. The village is the thing
        // being hunted, and it is not on the card.
        $card = $this->getJson('/api/v1/suchak/search')->assertOk()->json('data.results.0');
        $this->assertSame('Shirur', $card['location']['city'] ?? null);
        $this->assertTrue($card['location']['is_broad'] ?? false);

        // THE ATTACK. One request per village; the answer read is the location component of the fit.
        $signals = [];
        foreach ($probes as $name => $probe) {
            $signals[$name] = $this->fitSignal($target, $probe);
        }

        // The fit must actually have been computed — an engine that returned nothing would make every
        // assertion below pass while proving nothing.
        $this->assertGreaterThan(0, $signals['Nimgaon']['location_points']);
        $this->assertNotSame([], $signals['Nimgaon']['reasons']);

        // EVERY village answers identically — points, reason list and total score alike. The probe in
        // her actual village is indistinguishable from the probe three villages away.
        $this->assertCount(
            1,
            array_unique(array_map(static fn (array $s): string => (string) json_encode($s), $signals)),
            'ORACLE: the fit explanation told the reader which village under the shown taluka she lives in.',
        );

        foreach ($signals as $name => $signal) {
            $this->assertContains(
                __('matching.reason_same_taluka'),
                $signal['reasons'],
                "The location signal was destroyed rather than capped for the {$name} probe.",
            );
            $this->assertNotContains(__('matching.reason_same_city'), $signal['reasons']);
        }

        // AND AT THE OWNER, not merely the route — the web page at GET /suchak/search reaches
        // SuchakCrossSearchService::search() with the same filters and must get the same answer.
        $ownerSignals = [];
        foreach ($probes as $name => $probe) {
            $ownerSignals[$name] = $this->fitSignalViaService($helper, $target, $probe);
        }
        $this->assertCount(
            1,
            array_unique(array_map(static fn (array $s): string => (string) json_encode($s), $ownerSignals)),
            'ORACLE: the fit explanation leaked the village through the search service.',
        );

        // THE INVERSE PAIRING, which is the marketplace own-candidate picker (D7a): there the masked
        // side is the SEEKER — the challenge's candidate — and the rows being ranked are the reader's
        // own. Same collapse, or that screen names the village in one page load with no probing.
        $fitService = $this->app->make(SuchakMatchFitService::class);
        $inverse = $fitService->fit(
            $target->matrimonyProfile,
            $probes['Nimgaon']->matrimonyProfile,
            $target,
        );
        $this->assertIsArray($inverse);
        $this->assertContains(__('matching.reason_same_taluka'), $inverse['reasons']);
        $this->assertNotContains(__('matching.reason_same_city'), $inverse['reasons']);

        // AND THE DEFAULT IS THE SAFE ONE. A caller that says nothing about the masked side must lose
        // precision, never gain it — otherwise the next surface added leaks by omission.
        $unstated = $fitService->fit($probes['Nimgaon']->matrimonyProfile, $target->matrimonyProfile);
        $this->assertIsArray($unstated);
        $this->assertNotContains(__('matching.reason_same_city'), $unstated['reasons']);
    }

    public function test_the_fit_explanation_regains_village_precision_when_the_suchak_shares_it(): void
    {
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [, $stranger] = $this->verifiedSuchakActor();

        $shirur = $this->taluka('Shirur', $this->district('Pune'));
        $nimgaon = $this->village('Nimgaon', $shirur);
        $kendur = $this->village('Kendur', $shirur);

        $target = $this->representedCandidate($stranger, 'Sunita Gaikwad', $nimgaon, [
            'gender_id' => $this->genderId('female'),
        ]);
        $sameVillage = $this->representedCandidate($helper, 'Probe Nimgaon', $nimgaon, [
            'gender_id' => $this->genderId('male'),
        ]);
        $sameTaluka = $this->representedCandidate($helper, 'Probe Kendur', $kendur, [
            'gender_id' => $this->genderId('male'),
        ]);

        Sanctum::actingAs($helperUser);

        $maskedSame = $this->fitSignal($target, $sameVillage);
        $maskedNear = $this->fitSignal($target, $sameTaluka);
        $this->assertSame($maskedSame, $maskedNear);

        // The originating Suchak opens the village. He knows the family; the platform does not (D19a).
        // The card now prints Ranjangaon-level detail, so the score is entitled to as well — the fix
        // is a precision CAP tied to the reveal, not a permanent demotion of the location signal.
        $target->forceFill(['shares_village' => true])->save();

        $card = $this->getJson('/api/v1/suchak/search')->assertOk()->json('data.results.0');
        $this->assertSame('Nimgaon', $card['location']['city'] ?? null);
        $this->assertFalse($card['location']['is_broad'] ?? true);

        $revealedSame = $this->fitSignal($target, $sameVillage);
        $revealedNear = $this->fitSignal($target, $sameTaluka);

        $this->assertContains(__('matching.reason_same_city'), $revealedSame['reasons']);
        $this->assertGreaterThan($maskedSame['location_points'], $revealedSame['location_points']);

        // ...and only for the village that actually matches. The neighbour is unchanged, which is what
        // makes the precision real rather than a blanket bonus.
        $this->assertSame($maskedNear, $revealedNear);
        $this->assertContains(__('matching.reason_same_taluka'), $revealedNear['reasons']);
    }

    // ── D19b: the member path is NOT covered by D19a ──────────────────────────────────────────

    public function test_the_member_path_still_scores_the_exact_village(): void
    {
        $shirur = $this->taluka('Shirur', $this->district('Pune'));
        $nimgaon = $this->village('Nimgaon', $shirur);
        $kendur = $this->village('Kendur', $shirur);

        $she = $this->memberProfile('female', $nimgaon);
        $sameVillage = $this->memberProfile('male', $nimgaon);
        $sameTaluka = $this->memberProfile('male', $kendur);

        /** @var MatchingService $matching */
        $matching = $this->app->make(MatchingService::class);

        // The member entry point — MatchingExplainService, ContactVisibilityPolicyService and
        // RuleEngineService all call computeMatchBreakdown() exactly like this, with no cap.
        $exact = $this->locationSignalOf($matching->computeMatchBreakdown($she, $sameVillage));
        $near = $this->locationSignalOf($matching->computeMatchBreakdown($she, $sameTaluka));

        $this->assertContains(__('matching.reason_same_city'), $exact['reasons']);
        $this->assertContains(__('matching.reason_same_taluka'), $near['reasons']);
        $this->assertGreaterThan(
            $near['location_points'],
            $exact['location_points'],
            'D19b: a member choosing for themselves must keep the exact-village tier.',
        );

        // THE SAME PAIR, capped, ON THE SAME INSTANCE — the components are cached per pair for the
        // length of a run, and a cache key that ignored the cap would have served these precise
        // components straight back to the masked reader.
        $capped = $this->locationSignalOf($matching->computeMatchBreakdown($she, $sameVillage, false, true));
        $this->assertContains(__('matching.reason_same_taluka'), $capped['reasons']);
        $this->assertNotContains(__('matching.reason_same_city'), $capped['reasons']);
        $this->assertSame($near['location_points'], $capped['location_points']);

        // ...and the reverse direction of the same poisoning: the capped read must not have replaced
        // what the member surface gets when it asks again.
        $again = $this->locationSignalOf($matching->computeMatchBreakdown($she, $sameVillage));
        $this->assertSame($exact, $again);
    }

    // ── D7a is not weakened ───────────────────────────────────────────────────────────────────

    public function test_the_own_book_picker_still_filters_by_name_location_and_income(): void
    {
        [, $helper] = $this->verifiedSuchakActor();

        $shirur = $this->taluka('Shirur', $this->district('Pune'));
        $ranjangaon = $this->village('Ranjangaon', $shirur);
        $kendur = $this->village('Kendur', $shirur);
        $panchale = $this->village('Panchale', $this->taluka('Sinnar', $this->district('Nashik')));

        $sunita = $this->representedCandidate($helper, 'Sunita Gaikwad', $ranjangaon, [
            'annual_income' => self::SECRET_INCOME,
        ]);
        $rahul = $this->representedCandidate($helper, 'Rahul Kadam', $kendur, [
            'annual_income' => self::OTHER_INCOME,
        ]);
        $amit = $this->representedCandidate($helper, 'Amit Pawar', $panchale, [
            'annual_income' => self::OTHER_INCOME,
        ]);

        $ids = fn (array $filters): array => $this->ownBookIds($helper, $filters);

        $this->assertEqualsCanonicalizing(
            [(int) $sunita->id, (int) $rahul->id, (int) $amit->id],
            $ids([]),
        );

        // NAME — D7a's reason for existing: two hundred candidates, not a scroll.
        $this->assertSame([(int) $sunita->id], $ids(['name' => 'Gaikwad']));

        // LOCATION at every level, VILLAGE INCLUDED. The level rule is a cross-Suchak rule only:
        // nothing about his own candidates is hidden from a Suchak, so nothing here is an oracle.
        $this->assertSame([(int) $sunita->id], $ids(['district_id' => $ranjangaon]));
        $this->assertSame([(int) $rahul->id], $ids(['taluka_id' => $kendur]));
        $this->assertEqualsCanonicalizing([(int) $sunita->id, (int) $rahul->id], $ids(['taluka_id' => $shirur]));
        $this->assertSame([(int) $amit->id], $ids(['district_id' => $this->district('Nashik')]));

        // INCOME, including the same bound that must reveal nothing across the fence.
        $this->assertSame([(int) $sunita->id], $ids(['income_min' => 700000]));
        $this->assertEqualsCanonicalizing(
            [(int) $rahul->id, (int) $amit->id],
            $ids(['income_max' => 300000]),
        );
        $this->assertSame([(int) $sunita->id], $ids(['income_min' => self::SECRET_INCOME, 'income_max' => self::SECRET_INCOME]));
    }

    public function test_an_income_private_candidate_is_still_findable_by_his_own_suchak(): void
    {
        [, $helper] = $this->verifiedSuchakActor();

        // He typed the figure and sees it on his own edit screen; the flag governs what OTHER readers
        // are shown, and other readers no longer have an income filter at all.
        $private = $this->representedCandidate($helper, 'Private Earner', $this->defaultVillage(), [
            'annual_income' => self::SECRET_INCOME,
            'income_private' => true,
        ]);

        $this->assertSame([(int) $private->id], $this->ownBookIds($helper, ['income_min' => 700000]));
    }

    // ── Probe helpers ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $filters
     */
    private function crossTotal(array $filters = []): int
    {
        $url = '/api/v1/suchak/search';
        if ($filters !== []) {
            $url .= '?'.http_build_query($filters);
        }

        return (int) $this->getJson($url)->assertOk()->json('data.pagination.total');
    }

    /**
     * The prober's loop: the highest `income_min` at which the corpus still satisfies $stillThere.
     *
     * Against a working oracle this returns a salary. Against the refusal it runs to the ceiling,
     * because the count never moves — which is what $observed is collected to prove.
     *
     * @param  callable(int): int  $counter  how many rows survive this `income_min`
     * @param  array<int, int>  $observed
     * @param  callable(int): bool  $stillThere
     */
    private function highestIncomeBoundWhere(callable $counter, array &$observed, callable $stillThere): int
    {
        $low = 0;
        $high = self::INCOME_SWEEP_CEILING;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            $count = $counter($mid);
            $observed[] = $count;

            if ($stillThere($count)) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    /**
     * What the fit explanation says about WHERE this pair lives — the whole of attack 3's channel.
     *
     * The reason list is captured alongside the points on purpose: a fix that equalised the numbers
     * and left `reason_same_city` in the strings would be no fix at all, and the reason is the more
     * readable of the two ("Same city" is printed to the Suchak in as many words).
     *
     * @return array{location_points: int, match_score: int, reasons: list<string>}
     */
    private function fitSignal(
        SuchakProfileRepresentation $target,
        SuchakProfileRepresentation $probe,
    ): array {
        $row = $this->getJson('/api/v1/suchak/search?requesting_representation_id='.$probe->id)
            ->assertOk()
            ->json('data.results.0');

        $this->assertIsArray($row);
        $this->assertSame((int) $target->id, (int) ($row['representation']['id'] ?? 0));

        return $this->fitSignalOf($row);
    }

    /**
     * The same read at the SERVICE — the web page at GET /suchak/search reaches it this way, so a
     * route that merely stopped forwarding the key would not be enough to make this pass.
     */
    private function fitSignalViaService(
        SuchakAccount $account,
        SuchakProfileRepresentation $target,
        SuchakProfileRepresentation $probe,
    ): array {
        $rows = $this->app->make(SuchakCrossSearchService::class)
            ->search($account->fresh(), ['requesting_representation_id' => (int) $probe->id])
            ->items();

        $this->assertCount(1, $rows);
        $this->assertSame((int) $target->id, (int) ($rows[0]['representation']['id'] ?? 0));

        return $this->fitSignalOf($rows[0]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{location_points: int, match_score: int, reasons: list<string>}
     */
    private function fitSignalOf(array $row): array
    {
        $this->assertArrayHasKey('match_field_points', $row);

        return [
            'location_points' => (int) ($row['match_field_points']['location'] ?? -1),
            'match_score' => (int) ($row['match_score'] ?? -1),
            'reasons' => array_values(array_map('strval', $row['reasons'] ?? [])),
        ];
    }

    /**
     * The location component of a raw engine breakdown, for the member-path assertions.
     *
     * `field_parts` is positional and `field_points` is keyed — both come out of the one
     * computeMatchBreakdown() call, so the points are read from the keyed map and the reasons are
     * flattened from the parts rather than assuming location's index.
     *
     * @param  array<string, mixed>  $breakdown
     * @return array{location_points: int, reasons: list<string>}
     */
    private function locationSignalOf(array $breakdown): array
    {
        $reasons = [];
        foreach ($breakdown['field_parts'] ?? [] as $part) {
            foreach ($part['reasons'] ?? [] as $reason) {
                $reasons[] = (string) $reason;
            }
        }

        return [
            'location_points' => (int) ($breakdown['field_points']['location'] ?? -1),
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * The cross-Suchak search reached at the SERVICE, filters unstripped — every entrance at once.
     *
     * @param  array<string, mixed>  $filters
     */
    private function crossSearchTotalViaService(SuchakAccount $account, array $filters): int
    {
        return (int) $this->app->make(SuchakCrossSearchService::class)
            ->search($account->fresh(), $filters)
            ->total();
    }

    /**
     * The own-book picker's one query (D7a) — SuchakMarketplaceChallengeService::ownCandidatesFor()
     * reads exactly this, so what it accepts here is what that screen accepts.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function ownBookIds(SuchakAccount $account, array $filters): array
    {
        return $this->app->make(SuchakCrossSearchService::class)
            ->ownRepresentationsQuery($account->fresh(), $filters)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: SuchakAccount} */
    private function verifiedSuchakActor(): array
    {
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);

        return [$user, $account];
    }

    /**
     * One represented candidate on D19a's DEFAULTS — village and detailed address withheld, which is
     * the state both attacks are aimed at.
     *
     * @param  array<string, mixed>  $extra
     */
    private function representedCandidate(
        SuchakAccount $account,
        string $name,
        int $leafId,
        array $extra = [],
    ): SuchakProfileRepresentation {
        $profile = $this->profile(array_merge([
            'full_name' => $name,
            'gender_id' => $this->genderId('male'),
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'religion_id' => $this->sharedReligion()->id,
            'caste_id' => $this->sharedCaste()->id,
            'highest_education' => 'B.Com',
        ], $extra), $leafId);

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
            'shares_village' => false,
            'shares_detailed_address' => false,
        ]);

        return $representation->fresh();
    }

    /**
     * The residence SSOT observer refuses to let a profile leave draft without a canonical leaf, so
     * the two-step shape the other Suchak feature tests use is repeated here.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function profile(array $attributes, int $leafId): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes, ['lifecycle_state' => 'draft']));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        $profile->update(['lifecycle_state' => 'active', 'is_suspended' => false]);

        return $profile->fresh();
    }

    /**
     * A plain platform member — no Suchak, no representation, nothing masked. D19b's side of the
     * fence: this profile never passes through SuchakMatchFitService and must keep the exact tier.
     */
    private function memberProfile(string $genderKey, int $leafId): MatrimonyProfile
    {
        return $this->profile([
            'full_name' => 'Member '.$genderKey.' '.uniqid('', false),
            'gender_id' => $this->genderId($genderKey),
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'religion_id' => $this->sharedReligion()->id,
            'caste_id' => $this->sharedCaste()->id,
            'highest_education' => 'B.Com',
        ], $leafId);
    }

    private function defaultVillage(): int
    {
        return $this->village('Ranjangaon', $this->taluka('Shirur', $this->district('Pune')));
    }

    private function district(string $name): int
    {
        return $this->address($name, 'district', $this->address('Maharashtra', 'state', null));
    }

    private function taluka(string $name, int $districtId): int
    {
        return $this->address($name, 'taluka', $districtId);
    }

    private function village(string $name, int $talukaId): int
    {
        return $this->address($name, 'village', $talukaId, 'rural');
    }

    /**
     * Idempotent: the same (name, hierarchy, parent) always resolves to the same row id.
     *
     * `level` is written from Location::defaultLevelForHierarchy() — the same owner the production
     * fix reads the submitted id's level through — so a fixture can never disagree with the rule it
     * is testing about how deep a village is.
     */
    private function address(string $name, string $hierarchy, ?int $parent, ?string $tag = null): int
    {
        $existing = DB::table('addresses')
            ->where('name', $name)
            ->where('hierarchy', $hierarchy)
            ->when($parent === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parent))
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('addresses')->insertGetId(array_filter([
            'name' => $name,
            'slug' => strtolower($name).'-'.$hierarchy.'-'.uniqid('', true),
            'hierarchy' => $hierarchy,
            'level' => Location::defaultLevelForHierarchy($hierarchy),
            'parent_id' => $parent,
            'tag' => $tag,
            'created_at' => now(),
            'updated_at' => now(),
        ], static fn ($v): bool => $v !== null));
    }

    private function genderId(string $key): int
    {
        return (int) MasterGender::query()->firstOrCreate(
            ['key' => $key],
            ['label' => ucfirst($key), 'is_active' => true],
        )->id;
    }

    private function sharedReligion(): Religion
    {
        return Religion::query()->firstOrCreate(
            ['key' => 'oracle_probe_religion'],
            ['label' => 'Oracle Probe Religion', 'label_en' => 'Oracle Probe Religion', 'is_active' => true],
        );
    }

    private function sharedCaste(): Caste
    {
        return Caste::query()->firstOrCreate(
            ['key' => 'oracle_probe_caste'],
            [
                'religion_id' => $this->sharedReligion()->id,
                'label' => 'Oracle Probe Caste',
                'label_en' => 'Oracle Probe Caste',
                'is_active' => true,
            ],
        );
    }
}
