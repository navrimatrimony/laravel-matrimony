<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCrossSearchService;
use App\Modules\Suchak\Services\SuchakMarketplaceChallengeService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D7a — "that selection needs search and filters, not a list."
 *
 * D7 says a helping Suchak must NAME one of his own candidates to answer a challenge. Before this
 * slice both readers of a Suchak's own candidates took no filters at all
 * (SuchakCustomerListService::rowsForAccount, SuchakCrossSearchService::ownRepresentationOptions), so
 * a Suchak holding two hundred candidates could only scroll.
 *
 * What this class pins:
 *  - each of the three filters D7a names and that did not exist — NAME, LOCATION, INCOME — narrows;
 *  - the income filter finds a candidate whose ONLY income column is `annual_income`, which is every
 *    candidate the Suchak app creates and which a normalized-column-only filter would have missed;
 *  - no other Suchak's candidate can enter the list, on any filter;
 *  - `already_proposed` is true exactly where the pair guard would refuse the proposal;
 *  - the ranking puts the better fit first, across the WHOLE filtered set and not within a page;
 *  - the NAME filter is refused on the cross-Suchak search, because D19a hides that name.
 */
class SuchakOwnCandidateSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * MatrimonyProfile::$leafGeoBundleMemo is a STATIC lookaside keyed by `addresses.id`, which is
         * sound in production — what sits above an address never changes within a request — and is a
         * trap across tests, where RefreshDatabase hands the same id to a different place. Without
         * this flush the location half of the match score was computed from the PREVIOUS test's
         * geography, and the ranking assertion below passed in isolation while failing in the file.
         */
        MatrimonyProfile::flushLeafGeoMemo();
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    // ── The three filters D7a names ───────────────────────────────────────────────────────────

    public function test_the_name_filter_narrows_the_helpers_own_book(): void
    {
        [$helperUser, $helper, $challenge] = $this->marketplaceScene();

        $sunita = $this->ownCandidate($helper, ['full_name' => 'Sunita Gaikwad']);
        $rahul = $this->ownCandidate($helper, ['full_name' => 'Rahul Kadam']);

        $all = $this->candidateIds($this->myCandidates($helperUser, $challenge));
        $this->assertEqualsCanonicalizing(
            [(int) $sunita->id, (int) $rahul->id],
            $all,
            'The unfiltered list must show both of the helper own candidates.',
        );

        $narrowed = $this->myCandidates($helperUser, $challenge, ['q' => 'gaik']);
        $this->assertSame([(int) $sunita->id], $this->candidateIds($narrowed));
        $this->assertSame(1, $narrowed->json('data.meta.total'));
    }

    public function test_the_location_filter_narrows_by_district_and_by_taluka(): void
    {
        [$helperUser, $helper, $challenge] = $this->marketplaceScene();

        $pune = $this->district('Pune');
        $shirur = $this->taluka('Shirur', $pune);
        $ranjangaon = $this->village('Ranjangaon', $shirur);
        $haveli = $this->taluka('Haveli', $pune);
        $wagholi = $this->village('Wagholi', $haveli);

        $nashik = $this->district('Nashik');
        $sinnar = $this->taluka('Sinnar', $nashik);
        $panchale = $this->village('Panchale', $sinnar);

        $inShirur = $this->ownCandidate($helper, ['full_name' => 'Shirur Candidate'], $ranjangaon);
        $inHaveli = $this->ownCandidate($helper, ['full_name' => 'Haveli Candidate'], $wagholi);
        $inNashik = $this->ownCandidate($helper, ['full_name' => 'Nashik Candidate'], $panchale);

        // DISTRICT — the walk climbs village → taluka → district, so both Pune candidates match on a
        // district id that is two levels above the leaf either of them actually points at.
        $this->assertEqualsCanonicalizing(
            [(int) $inShirur->id, (int) $inHaveli->id],
            $this->candidateIds($this->myCandidates($helperUser, $challenge, ['district_id' => $pune])),
        );

        // TALUKA — one level down, and the sibling taluka in the same district drops out.
        $this->assertSame(
            [(int) $inShirur->id],
            $this->candidateIds($this->myCandidates($helperUser, $challenge, ['taluka_id' => $shirur])),
        );

        $this->assertSame(
            [(int) $inNashik->id],
            $this->candidateIds($this->myCandidates($helperUser, $challenge, ['district_id' => $nashik])),
        );
    }

    public function test_the_income_filter_narrows_and_finds_a_candidate_the_income_engine_never_touched(): void
    {
        [$helperUser, $helper, $challenge] = $this->marketplaceScene();

        // The member-app shape: the income engine ran, so the normalized column carries the figure.
        $engineWritten = $this->ownCandidate($helper, [
            'full_name' => 'Engine Written',
            'income_normalized_annual_amount' => 900000,
            'annual_income' => 900000,
        ]);

        /*
         * The SUCHAK-APP shape, and the reason this endpoint does not filter on the normalized column
         * alone. MatrimonyProfileApiController::mobileIncomeEngineCoreFromApi() writes
         * `annual_income = request('annual_income') ?: $normalized`, and the Suchak app sends the flat
         * figure only — so `income_normalized_annual_amount` is NULL for every candidate it creates,
         * which is the entire corpus this endpoint searches. A normalized-only filter would have
         * returned "nobody earns that much" rather than looking like a bug.
         */
        $flatOnly = $this->ownCandidate($helper, [
            'full_name' => 'Flat Column Only',
            'income_normalized_annual_amount' => null,
            'annual_income' => 400000,
        ]);

        $noIncome = $this->ownCandidate($helper, [
            'full_name' => 'No Income Recorded',
            'income_normalized_annual_amount' => null,
            'annual_income' => null,
        ]);

        $above = $this->myCandidates($helperUser, $challenge, ['income_min' => 500000]);
        $this->assertSame([(int) $engineWritten->id], $this->candidateIds($above));

        $below = $this->myCandidates($helperUser, $challenge, ['income_max' => 500000]);
        $this->assertSame(
            [(int) $flatOnly->id],
            $this->candidateIds($below),
            'A candidate whose only income column is annual_income must be findable by income.',
        );

        $band = $this->myCandidates($helperUser, $challenge, ['income_min' => 300000, 'income_max' => 1000000]);
        $this->assertEqualsCanonicalizing(
            [(int) $engineWritten->id, (int) $flatOnly->id],
            $this->candidateIds($band),
        );

        // A candidate with no figure at all is never swept in by an income filter, in either
        // direction — "unknown" is not "0" and it is not "any".
        $this->assertNotContains((int) $noIncome->id, $this->candidateIds($above));
        $this->assertNotContains((int) $noIncome->id, $this->candidateIds($below));
        $this->assertContains((int) $noIncome->id, $this->candidateIds($this->myCandidates($helperUser, $challenge)));
    }

    public function test_the_education_and_age_filters_narrow_on_the_same_owner(): void
    {
        [$helperUser, $helper, $challenge] = $this->marketplaceScene();

        $engineer = $this->ownCandidate($helper, [
            'full_name' => 'Educated Engineer',
            'highest_education' => 'B.Tech Computer',
            'date_of_birth' => now()->subYears(27)->toDateString(),
        ]);
        $teacher = $this->ownCandidate($helper, [
            'full_name' => 'Educated Teacher',
            'highest_education' => 'B.Ed',
            'date_of_birth' => now()->subYears(40)->toDateString(),
        ]);

        $this->assertSame(
            [(int) $engineer->id],
            $this->candidateIds($this->myCandidates($helperUser, $challenge, ['education' => 'B.Tech'])),
        );

        $this->assertSame(
            [(int) $teacher->id],
            $this->candidateIds($this->myCandidates($helperUser, $challenge, ['age_min' => 35])),
        );

        $this->assertSame(
            [(int) $engineer->id],
            $this->candidateIds($this->myCandidates($helperUser, $challenge, ['age_max' => 30])),
        );
    }

    // ── Nobody else's candidate, ever ─────────────────────────────────────────────────────────

    public function test_no_other_suchak_candidate_can_enter_the_list(): void
    {
        [$helperUser, $helper, $challenge, $publisher, $publisherRepresentation] = $this->marketplaceScene();
        [, $stranger] = $this->verifiedSuchakActor();

        $mine = $this->ownCandidate($helper, ['full_name' => 'Helper Own Candidate']);
        $strangers = $this->ownCandidate($stranger, ['full_name' => 'Stranger Candidate']);

        $ids = $this->candidateIds($this->myCandidates($helperUser, $challenge));
        $this->assertSame([(int) $mine->id], $ids);
        $this->assertNotContains((int) $strangers->id, $ids);
        // Not even the challenge's own candidate, who is the one profile this screen is ranking
        // AGAINST and would be the easiest to leak in by accident.
        $this->assertNotContains((int) $publisherRepresentation->id, $ids);

        // And no filter is a way back in: a name that matches only the stranger returns nothing
        // rather than confirming that a stranger by that name exists.
        $byName = $this->myCandidates($helperUser, $challenge, ['q' => 'Stranger']);
        $this->assertSame([], $this->candidateIds($byName));
        $this->assertSame(0, $byName->json('data.meta.total'));

        $this->assertNotSame((int) $helper->id, (int) $publisher->id);
    }

    // ── already_proposed — the pair guard, shown instead of discovered ────────────────────────

    public function test_already_proposed_is_true_for_a_candidate_that_already_answers_this_challenge(): void
    {
        [$helperUser, $helper, $challenge] = $this->marketplaceScene();

        $proposed = $this->ownCandidate($helper, ['full_name' => 'Already Proposed']);
        $free = $this->ownCandidate($helper, ['full_name' => 'Still Available']);

        $this->challengeService()->proposeCandidate($challenge, $helper, $helperUser, $proposed);

        $rows = $this->rowsByRepresentation($this->myCandidates($helperUser, $challenge));

        $this->assertTrue($rows[(int) $proposed->id]['already_proposed']);
        $this->assertFalse($rows[(int) $free->id]['already_proposed']);

        // The flag must predict the refusal, not merely decorate it: this is exactly the request the
        // app would fire if it let the Suchak tap the greyed-out card.
        Sanctum::actingAs($helperUser);
        $this->postJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/proposals', [
            'representation_id' => $proposed->id,
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'हे स्थळ या आव्हानासाठी तुम्ही आधीच सुचवले आहे.']);
    }

    public function test_already_proposed_stays_true_after_the_publisher_rejected_the_proposal(): void
    {
        [$helperUser, $helper, $challenge, , , $publisherUser] = $this->marketplaceScene();

        $candidate = $this->ownCandidate($helper, ['full_name' => 'Rejected Once']);
        $proposal = $this->challengeService()->proposeCandidate($challenge, $helper, $helperUser, $candidate);

        Sanctum::actingAs($publisherUser);
        $this->postJson('/api/v1/suchak/collaborations/'.$proposal['request']->id.'/reject')->assertOk();

        // assertNotAlreadyProposed() is status-blind, so a rejected proposal is still refused if
        // re-sent. A card that flipped back to "available" would be promising a request that fails.
        $rows = $this->rowsByRepresentation($this->myCandidates($helperUser, $challenge));
        $this->assertTrue($rows[(int) $candidate->id]['already_proposed']);
    }

    // ── Ranking ───────────────────────────────────────────────────────────────────────────────

    public function test_the_better_fit_is_returned_first_and_a_weaker_one_is_kept_not_dropped(): void
    {
        [$helperUser, $helper, $challenge] = $this->marketplaceScene();

        // Same religion, same caste, same village as the challenge's candidate.
        $strong = $this->ownCandidate($helper, [
            'full_name' => 'Strong Fit',
            'religion_id' => $this->sharedReligion()->id,
            'caste_id' => $this->sharedCaste()->id,
            'date_of_birth' => now()->subYears(30)->toDateString(),
        ]);

        // Different community, different district, wider age gap.
        $weak = $this->ownCandidate($helper, [
            'full_name' => 'Weak Fit',
            'religion_id' => $this->otherReligion()->id,
            'caste_id' => $this->otherCaste()->id,
            'date_of_birth' => now()->subYears(45)->toDateString(),
        ], $this->village('Panchale', $this->taluka('Sinnar', $this->district('Nashik'))));

        $response = $this->myCandidates($helperUser, $challenge);
        $ids = $this->candidateIds($response);

        $this->assertSame((int) $strong->id, $ids[0], 'The better fit must be first.');
        $this->assertContains((int) $weak->id, $ids, 'A weak or unscored candidate is kept — the propose call applies no fit floor.');

        $rows = $this->rowsByRepresentation($response);
        $this->assertGreaterThan(
            (int) $rows[(int) $weak->id]['match_score'],
            (int) $rows[(int) $strong->id]['match_score'],
        );
        $this->assertGreaterThan(0, (int) $rows[(int) $strong->id]['match_score']);
    }

    public function test_the_ranking_is_over_the_whole_filtered_set_and_not_within_one_page(): void
    {
        [$helperUser, $helper, $challenge] = $this->marketplaceScene();

        // Six weak candidates created FIRST, then the strong one. The own-book query orders newest
        // consent first, so a naive "page then rank" would put the strong candidate on page 1 by
        // accident; `per_page=1` with an ASCENDING creation order is what makes the difference
        // visible — page 1 must be the strong candidate even though six rows were written before it.
        for ($i = 1; $i <= 6; $i++) {
            $this->ownCandidate($helper, [
                'full_name' => 'Filler '.$i,
                'religion_id' => $this->otherReligion()->id,
                'caste_id' => $this->otherCaste()->id,
                'date_of_birth' => now()->subYears(46)->toDateString(),
            ], $this->village('Panchale', $this->taluka('Sinnar', $this->district('Nashik'))));
        }

        $strong = $this->ownCandidate($helper, [
            'full_name' => 'Best Of Seven',
            'religion_id' => $this->sharedReligion()->id,
            'caste_id' => $this->sharedCaste()->id,
            'date_of_birth' => now()->subYears(30)->toDateString(),
        ]);

        $page1 = $this->myCandidates($helperUser, $challenge, ['per_page' => 1, 'page' => 1]);
        $this->assertSame([(int) $strong->id], $this->candidateIds($page1));
        $this->assertSame(7, $page1->json('data.meta.total'));
        $this->assertSame(1, $page1->json('data.meta.per_page'));
        $this->assertSame(1, $page1->json('data.meta.page'));

        // And page 2 does not repeat page 1 — the tiebreak among equal scores is stable.
        $page2 = $this->myCandidates($helperUser, $challenge, ['per_page' => 1, 'page' => 2]);
        $this->assertNotSame([(int) $strong->id], $this->candidateIds($page2));
        $this->assertSame(2, $page2->json('data.meta.page'));
    }

    // ── The response shape, unmasked ──────────────────────────────────────────────────────────

    public function test_the_row_carries_the_contract_keys_and_is_not_masked(): void
    {
        [$helperUser, $helper, $challenge] = $this->marketplaceScene();

        $pune = $this->district('Pune');
        $shirur = $this->taluka('Shirur', $pune);

        $candidate = $this->ownCandidate($helper, [
            'full_name' => 'Rahul Shrikant Kadam',
            'highest_education' => 'M.Com',
            'date_of_birth' => now()->subYears(31)->toDateString(),
            'annual_income' => 540000,
            'income_normalized_annual_amount' => null,
        ], $this->village('Ranjangaon', $shirur));

        $row = $this->rowsByRepresentation($this->myCandidates($helperUser, $challenge))[(int) $candidate->id];

        $this->assertSame([
            'representation_id',
            'candidate_profile_id',
            'display_name',
            'age',
            'gender',
            'district',
            'taluka',
            'education',
            'annual_income',
            'income_display',
            'photo_url',
            'match_score',
            'fit_label',
            'reasons',
            'already_proposed',
        ], array_keys($row));

        // NOT masked: these are the helper's own candidates. CandidateNameMask ("रा. क.") is for
        // another Suchak's candidate (D19a) and must not appear on a Suchak's own book.
        $this->assertSame('Rahul Shrikant Kadam', $row['display_name']);
        $this->assertSame(31, $row['age']);
        $this->assertSame('Pune', $row['district']);
        $this->assertSame('Shirur', $row['taluka']);
        $this->assertSame('M.Com', $row['education']);
        $this->assertNull($row['photo_url'], 'No photograph on file must be null, never a placeholder URL.');
        $this->assertIsArray($row['reasons']);
        $this->assertFalse($row['already_proposed']);

        // Latin digits, Indian grouping, and the SAME figure the income filter compares on.
        $this->assertSame('₹5,40,000', $row['income_display']);
        $this->assertSame(540000.0, (float) $row['annual_income']);
        $this->assertSame(
            [(int) $candidate->id],
            $this->candidateIds($this->myCandidates($helperUser, $challenge, ['income_min' => 540000])),
            'The figure printed on the card must be the figure the filter matched.',
        );
    }

    // ── Authorisation (D18 badge, A2 own challenge, answerability) ────────────────────────────

    public function test_an_unverified_helper_cannot_read_the_list_at_all(): void
    {
        [$helperUser, $helper, $challenge] = $this->marketplaceScene();
        $this->ownCandidate($helper, ['full_name' => 'Hidden By Badge']);

        $helper->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();

        Sanctum::actingAs($helperUser);
        $this->getJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/my-candidates')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'बाजारपेठ फक्त पडताळणी झालेल्या सूचकांना दिसते.']);
    }

    public function test_a_publisher_cannot_open_his_own_challenge_candidate_picker(): void
    {
        [, , $challenge, $publisher, , $publisherUser] = $this->marketplaceScene();
        $this->ownCandidate($publisher, ['full_name' => 'Publisher Second Candidate']);

        Sanctum::actingAs($publisherUser);
        $this->getJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/my-candidates')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'स्वतःच्या आव्हानाला स्वतःच स्थळ सुचवता येत नाही.']);
    }

    public function test_a_withdrawn_challenge_offers_no_candidates_to_pick_from(): void
    {
        [$helperUser, $helper, $challenge, $publisher, , $publisherUser] = $this->marketplaceScene();
        $this->ownCandidate($helper, ['full_name' => 'Too Late']);

        $this->challengeService()->withdraw($challenge, $publisher, $publisherUser, 'ग्राहकाने थांबवले.');

        // The SAME gate propose() runs, so a candidate this list offers is one propose() accepts.
        Sanctum::actingAs($helperUser);
        $this->getJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/my-candidates')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ── The name filter must not reach a cross-Suchak read (D19a) ─────────────────────────────

    public function test_the_name_filter_is_own_book_only_and_never_narrows_the_cross_suchak_search(): void
    {
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [, $stranger] = $this->verifiedSuchakActor();

        $this->ownCandidate($stranger, ['full_name' => 'Sunita Gaikwad']);
        $this->ownCandidate($stranger, ['full_name' => 'Rahul Kadam']);

        $this->assertContains('name', SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS);

        Sanctum::actingAs($helperUser);

        $unfiltered = $this->getJson('/api/v1/suchak/search')->assertOk();
        $this->assertSame(2, $unfiltered->json('data.pagination.total'));

        // A `name` on a cross-Suchak read must change NOTHING. If it narrowed, a Suchak could confirm
        // a name D19a hides by sending 'sun', then 'suni', and watching the count move.
        $probed = $this->getJson('/api/v1/suchak/search?name=Gaikwad')->assertOk();
        $this->assertSame(2, $probed->json('data.pagination.total'));

        // And the same string on the OWN book does narrow — one filter owner, two answers, because
        // ownership is the difference and not the spelling.
        $mine = $this->ownCandidate($helper, ['full_name' => 'Sunita Gaikwad']);
        $this->ownCandidate($helper, ['full_name' => 'Rahul Kadam']);

        $own = $this->app->make(SuchakCrossSearchService::class)
            ->ownRepresentationsQuery($helper->fresh(), ['name' => 'Gaikwad'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([(int) $mine->id], $own);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function challengeService(): SuchakMarketplaceChallengeService
    {
        return $this->app->make(SuchakMarketplaceChallengeService::class);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function myCandidates(
        User $helperUser,
        SuchakMarketplaceChallenge $challenge,
        array $query = [],
    ): \Illuminate\Testing\TestResponse {
        Sanctum::actingAs($helperUser);

        $url = '/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/my-candidates';
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $this->getJson($url)->assertOk();
    }

    /**
     * @return list<int>
     */
    private function candidateIds(\Illuminate\Testing\TestResponse $response): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['representation_id'],
            $response->json('data.candidates'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsByRepresentation(\Illuminate\Testing\TestResponse $response): array
    {
        $rows = [];
        foreach ($response->json('data.candidates') as $row) {
            $rows[(int) $row['representation_id']] = $row;
        }

        return $rows;
    }

    /**
     * A publisher with an open challenge and a helper who may answer it.
     *
     * @return array{0: User, 1: SuchakAccount, 2: SuchakMarketplaceChallenge, 3: SuchakAccount, 4: SuchakProfileRepresentation, 5: User}
     */
    private function marketplaceScene(): array
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();

        [$publisherRepresentation] = $this->publishableCandidate($publisher, $publisherUser);

        $challenge = $this->challengeService()->publish(
            $publisher,
            $publisherUser,
            $publisherRepresentation,
            [
                'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
                'declared_share_percent' => 30,
            ],
        );

        return [$helperUser, $helper, $challenge, $publisher, $publisherRepresentation, $publisherUser];
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
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
     * The publisher's candidate: a FEMALE profile in Ranjangaon with an accepted agreement whose
     * package carries a fixed success fee, so a percent share has a base to sit on.
     *
     * @return array{0: SuchakProfileRepresentation, 1: SuchakCustomerAgreement}
     */
    private function publishableCandidate(SuchakAccount $account, User $user): array
    {
        $profile = $this->profile([
            'full_name' => 'Published Candidate',
            'gender_id' => $this->genderId('female'),
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'religion_id' => $this->sharedReligion()->id,
            'caste_id' => $this->sharedCaste()->id,
            'highest_education' => 'M.A.',
        ], $this->defaultVillage());

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        /** @var SuchakCustomerContext $context */
        $context = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'created_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        /** @var SuchakServicePackage $package */
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Own-candidate fixture '.$representation->id,
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            'post_marriage_fee_amount' => '100000',
        ]);

        /** @var SuchakCustomerAgreement $agreement */
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'own-candidate-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Accepted terms revision 1',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);

        return [$representation->fresh(), $agreement->fresh()];
    }

    /**
     * One of a Suchak's own candidates: MALE by default, so the pair with the published female
     * candidate is eligible and the engine actually returns a score.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function ownCandidate(
        SuchakAccount $account,
        array $attributes = [],
        ?int $leafId = null,
    ): SuchakProfileRepresentation {
        $profile = $this->profile(array_merge([
            'gender_id' => $this->genderId('male'),
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'religion_id' => $this->sharedReligion()->id,
            'caste_id' => $this->sharedCaste()->id,
            'highest_education' => 'B.Com',
        ], $attributes), $leafId ?? $this->defaultVillage());

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
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
            'full_name' => 'Own Book Candidate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes, [
            'lifecycle_state' => 'draft',
        ]));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $leafId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $leafId, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $profile->fresh();
    }

    private function defaultVillage(): int
    {
        return $this->village('Ranjangaon', $this->taluka('Shirur', $this->district('Pune')));
    }

    private function district(string $name): int
    {
        $state = $this->address('Maharashtra', 'state', 1, null);

        return $this->address($name, 'district', 2, $state);
    }

    private function taluka(string $name, int $districtId): int
    {
        return $this->address($name, 'taluka', 3, $districtId);
    }

    private function village(string $name, int $talukaId): int
    {
        return $this->address($name, 'village', 4, $talukaId, 'rural');
    }

    /** Idempotent: the same (name, hierarchy, parent) always resolves to the same row id. */
    private function address(string $name, string $hierarchy, int $level, ?int $parent, ?string $tag = null): int
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
            'level' => $level,
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
        return $this->religion('own_book_shared_religion', 'Own Book Shared Religion');
    }

    private function otherReligion(): Religion
    {
        return $this->religion('own_book_other_religion', 'Own Book Other Religion');
    }

    private function sharedCaste(): Caste
    {
        return $this->caste($this->sharedReligion(), 'own_book_shared_caste', 'Own Book Shared Caste');
    }

    private function otherCaste(): Caste
    {
        return $this->caste($this->otherReligion(), 'own_book_other_caste', 'Own Book Other Caste');
    }

    private function religion(string $key, string $label): Religion
    {
        return Religion::query()->firstOrCreate(
            ['key' => $key],
            ['label' => $label, 'label_en' => $label, 'is_active' => true],
        );
    }

    private function caste(Religion $religion, string $key, string $label): Caste
    {
        return Caste::query()->firstOrCreate(
            ['key' => $key],
            [
                'religion_id' => $religion->id,
                'label' => $label,
                'label_en' => $label,
                'is_active' => true,
            ],
        );
    }
}
