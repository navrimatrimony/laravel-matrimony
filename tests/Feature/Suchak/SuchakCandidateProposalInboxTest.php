<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCandidateMaskingService;
use App\Modules\Suchak\Services\SuchakCandidateProposalInboxService;
use App\Modules\Suchak\Services\SuchakCrossSearchService;
use App\Modules\Suchak\Services\SuchakMarketplaceChallengeService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * THE PER-CANDIDATE PROPOSAL INBOX (blueprint phase 5, §16).
 *
 * One of my candidates; every proposal anyone has made against him, across every challenge I
 * published for him. The existing read is challenge-by-challenge, so a candidate published twice
 * has his answers split across two lists and the Suchak compares them from memory.
 *
 * Two properties carry the whole feature, and both are negatives:
 *
 *  1. EVERY ROW IS A CROSS-SUCHAK DISCLOSURE. The proposed candidate belongs to somebody else, so
 *     the inbox may show exactly what the marketplace listing already shows and not one field more.
 *  2. COMPARING MUST NOT BECOME AN ORACLE. A filter or a sort that narrows on a hidden attribute
 *     reads it back by counting rows — the hole this codebase has already closed twice
 *     (`SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS`, trap 14's score channel).
 */
class SuchakCandidateProposalInboxTest extends TestCase
{
    use RefreshDatabase;

    // ── The read itself ───────────────────────────────────────────────────────────────────────

    public function test_one_candidates_inbox_gathers_the_proposals_from_every_challenge_he_was_published_under(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$secondHelperUser, $secondHelper] = $this->verifiedSuchakActor();

        [$male, $female] = $this->genders();
        [$candidate] = $this->publishableCandidate($publisher, $publisherUser, $male);

        // First challenge at 30%, answered, then WITHDRAWN — the answer still stands (A8).
        $first = $this->challengeService()->publish($publisher, $publisherUser, $candidate, $this->percentTerms(30));
        $this->challengeService()->proposeCandidate(
            $first,
            $helper,
            $helperUser,
            $this->helperCandidate($helper, $female, 'Rahul Kadam'),
        );
        $this->challengeService()->withdraw($first, $publisher, $publisherUser, 'Rate too low.');

        // Second challenge at 40%, answered by somebody else.
        $second = $this->challengeService()->publish($publisher, $publisherUser, $candidate, $this->percentTerms(40));
        $this->challengeService()->proposeCandidate(
            $second,
            $secondHelper,
            $secondHelperUser,
            $this->helperCandidate($secondHelper, $female, 'Vaishali Jadhav'),
        );

        $inbox = $this->inboxService()->inboxFor($candidate, $publisher);

        $this->assertSame(2, $inbox['totals']['proposals'], 'The inbox lost a proposal made under the withdrawn challenge.');
        $this->assertSame(2, $inbox['totals']['proposing_suchaks']);
        $this->assertCount(2, $inbox['proposals']);
        $this->assertCount(2, $inbox['challenges']);

        // The candidate header is the caller's OWN candidate and is therefore unmasked.
        $this->assertSame((int) $candidate->id, $inbox['candidate']['representation_id']);
        $this->assertSame('Sunita Gaikwad', $inbox['candidate']['display_name']);
        $this->assertSame(2, $inbox['candidate']['challenges_published']);
        $this->assertSame(1, $inbox['candidate']['open_challenges']);

        // Each challenge column carries ITS OWN declared share, which is the reason the inbox is
        // worth reading: the two answers were given under different terms.
        $shares = collect($inbox['challenges'])->pluck('declared_share.display')->sort()->values()->all();
        $this->assertSame(['30%', '40%'], $shares);
        $this->assertSame(
            ['₹30,000', '₹40,000'],
            collect($inbox['challenges'])->pluck('declared_share.estimated_share_display')->sort()->values()->all(),
        );
    }

    public function test_the_declared_share_is_published_once_per_challenge_and_never_on_a_proposal_row(): void
    {
        // proposalsFor() records the rule — the share is the challenge's, one per listing, and
        // repeating it per row invites the two to disagree. This read has the most reason to obey
        // it, because its rows genuinely come from challenges at different shares.
        [$inbox] = $this->oneAnsweredChallenge();

        foreach ($inbox['proposals'] as $row) {
            $this->assertArrayNotHasKey('declared_share', $row);
        }

        $this->assertArrayHasKey('declared_share', $inbox['challenges'][0]);
    }

    // ── Negative: the inbox leaks nothing the marketplace listing does not already show ───────

    public function test_the_inbox_shows_no_unmasked_identity_of_a_proposed_candidate(): void
    {
        [$inbox, $context] = $this->oneAnsweredChallenge();
        $candidate = $inbox['proposals'][0]['proposed_candidate'];

        // D19a's four defaults, each checked on the payload rather than on the intention.
        $this->assertSame('Rahul K.', $candidate['display_name'], 'The full name reached another Suchak.');
        $this->assertSame('Shirur', $candidate['location']['city'], 'The village went out under the `city` key (S5).');
        $this->assertTrue($candidate['location']['is_broad']);
        $this->assertNull($candidate['location']['exact_address']);
        $this->assertTrue($candidate['contact']['is_masked']);
        $this->assertNull($candidate['contact']['phone']);
        $this->assertNull($candidate['contact']['address_line']);

        // And the one thing D19a insists IS shown: a matchmaker who cannot see a face cannot
        // propose a match. Withholding it would be the opposite bug.
        $this->assertArrayHasKey('photo', $candidate);

        // The blunt scan. Whatever any single key is called, none of the four hidden values may
        // appear anywhere in the whole response — including inside a fit reason, a warning, a
        // challenge column or the candidate header.
        $encoded = json_encode($inbox, JSON_UNESCAPED_UNICODE);
        $secrets = [
            $context['proposed_full_name'],
            $context['proposed_surname'],
            $context['proposed_village'],
            $context['proposed_mobile'],
        ];

        if (Schema::hasColumn('matrimony_profiles', 'address_line')) {
            $secrets[] = $context['proposed_address_line'];
        }

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                (string) $encoded,
                'A masked value reached the per-candidate proposal inbox: '.$secret
            );
        }
    }

    public function test_the_inbox_candidate_payload_is_byte_for_byte_the_marketplaces_own_masked_summary(): void
    {
        // The strongest form of "leaks nothing the listing does not already show": not a list of
        // fields that happen to match, but the SAME presenter's output, unmodified. The moment this
        // read starts deciding what another Suchak may see there are two masking rules in the
        // codebase and D19a is enforced by whichever one the caller happened to reach.
        [$inbox, $context] = $this->oneAnsweredChallenge();

        $expected = app(SuchakCandidateMaskingService::class)->maskedSummary(
            $context['proposed_representation']->matrimonyProfile,
            $context['proposed_representation'],
        );

        $this->assertSame($expected, $inbox['proposals'][0]['proposed_candidate']);
    }

    public function test_the_detailed_address_reveal_is_structurally_dead_on_this_schema(): void
    {
        // A FINDING, pinned rather than papered over. D19a names four hidden facts and gives the
        // originating Suchak a per-candidate switch for each. Three work. The fourth cannot:
        // `SuchakCandidateMaskingService::locationSlot()` reads `$profile->address_line`, and that
        // column was DROPPED when residence moved to `profile_addresses`
        // (2026_05_11_200000_backfill_self_current_residence…). Eloquent returns null for a missing
        // attribute, so `shares_detailed_address` is a switch wired to nothing.
        //
        // It fails CLOSED — the address is withheld, never leaked — which is why this test asserts
        // the safe behaviour rather than the feature. The fix belongs to the masking service and to
        // whoever decides WHICH `profile_addresses` row is "the" address, not to this read.
        $this->assertFalse(
            Schema::hasColumn('matrimony_profiles', 'address_line'),
            'address_line is back on matrimony_profiles — re-check whether the reveal now works, and delete this test.'
        );

        [, $context] = $this->oneAnsweredChallenge();

        $context['proposed_representation']->forceFill(['shares_detailed_address' => true])->save();

        $inbox = $this->inboxService()->inboxFor($context['candidate'], $context['publisher']);
        $this->assertNull($inbox['proposals'][0]['proposed_candidate']['location']['exact_address']);
    }

    public function test_a_reveal_by_the_proposing_suchak_reaches_the_inbox_without_a_second_masking_path(): void
    {
        // `shares_village` has ONE reader (revealsVillage). If the inbox had its own masking, the
        // proposing Suchak's decision about his own candidate would stop at this screen.
        [, $context] = $this->oneAnsweredChallenge();

        $context['proposed_representation']->forceFill([
            'shares_village' => true,
            'shares_name' => true,
        ])->save();

        $inbox = $this->inboxService()->inboxFor($context['candidate'], $context['publisher']);
        $candidate = $inbox['proposals'][0]['proposed_candidate'];

        $this->assertSame($context['proposed_full_name'], $candidate['display_name']);
        $this->assertSame($context['proposed_village'], $candidate['location']['city']);
        $this->assertFalse($candidate['location']['is_broad']);
    }

    // ── Negative: comparing must not become an oracle ─────────────────────────────────────────

    public function test_an_income_filter_cannot_narrow_the_inbox(): void
    {
        // No income figure appears on a masked card at all, so the filter would be the ONLY
        // channel and the row count binary-searches the salary. OWN_BOOK_ONLY_FILTERS is the rule
        // and it is reached through the one filter owner, not restated here.
        $this->assertContains('income_min', SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS);
        $this->assertContains('income_max', SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS);

        [$inbox, $context] = $this->twoAnsweredProposalsWithDifferentIncomes();
        $this->assertSame(2, $inbox['meta']['total']);

        foreach ([
            ['income_min' => 900000],
            ['income_max' => 300000],
            ['income_min' => 400000, 'income_max' => 600000],
        ] as $probe) {
            $narrowed = $this->inboxService()->inboxFor($context['candidate'], $context['publisher'], $probe);
            $this->assertSame(
                2,
                $narrowed['meta']['total'],
                'An income bound narrowed a cross-Suchak inbox: '.json_encode($probe)
            );
        }
    }

    public function test_a_name_filter_cannot_narrow_the_inbox(): void
    {
        $this->assertContains('name', SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS);

        [$inbox, $context] = $this->twoAnsweredProposalsWithDifferentIncomes();
        $this->assertSame(2, $inbox['meta']['total']);

        // Peeling the mask one letter at a time is the attack. Every prefix must return the same
        // page, so counting tells the prober nothing.
        foreach (['R', 'Ra', 'Rah', 'Rahul', 'Kadam'] as $probe) {
            $narrowed = $this->inboxService()->inboxFor(
                $context['candidate'],
                $context['publisher'],
                ['name' => $probe],
            );
            $this->assertSame(2, $narrowed['meta']['total'], 'A name prefix narrowed the inbox: '.$probe);
        }
    }

    public function test_a_village_id_cannot_narrow_the_inbox_although_a_taluka_id_can(): void
    {
        // The level rule, not a blanket refusal: the masked card prints the district always and the
        // taluka in the `city` slot, so filtering by those discloses nothing it did not already
        // show. An id BELOW that line recovers the hidden village by counting rows.
        $this->assertSame('taluka', SuchakCrossSearchService::CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY);

        [$inbox, $context] = $this->twoProposalsInDifferentVillagesOfOneTaluka();
        $this->assertSame(2, $inbox['meta']['total']);

        $villageProbe = $this->inboxService()->inboxFor(
            $context['candidate'],
            $context['publisher'],
            ['taluka_id' => $context['village_of_one_proposal']],
        );
        $this->assertSame(
            2,
            $villageProbe['meta']['total'],
            'A village id narrowed the inbox and therefore named D19a hidden village.'
        );

        // The same key, one level up, is honoured — the reader may narrow by what he was shown.
        $talukaProbe = $this->inboxService()->inboxFor(
            $context['candidate'],
            $context['publisher'],
            ['taluka_id' => $context['shared_taluka']],
        );
        $this->assertSame(2, $talukaProbe['meta']['total']);

        $elsewhere = $this->inboxService()->inboxFor(
            $context['candidate'],
            $context['publisher'],
            ['taluka_id' => $context['unrelated_taluka']],
        );
        $this->assertSame(0, $elsewhere['meta']['total'], 'The taluka filter did not filter at all.');
    }

    public function test_the_sort_allow_list_carries_only_facts_the_masked_card_already_prints(): void
    {
        // An ordering is a comparison, so a sort is the same oracle asked sideways. The list is
        // closed on purpose; this pins it so a future "sort by income" has to argue with a test.
        $this->assertSame(
            ['recent', 'oldest', 'fit_desc', 'age_asc', 'age_desc'],
            SuchakCandidateProposalInboxService::SORTS,
        );

        foreach (SuchakCandidateProposalInboxService::SORTS as $sort) {
            $this->assertStringNotContainsString('income', $sort);
            $this->assertStringNotContainsString('name', $sort);
            $this->assertStringNotContainsString('village', $sort);
            $this->assertStringNotContainsString('location', $sort);
        }

        [, $context] = $this->twoAnsweredProposalsWithDifferentIncomes();

        // An unknown sort falls back silently rather than 422ing — a refusal that names the refused
        // key is a map of what is worth probing for.
        $probed = $this->inboxService()->inboxFor(
            $context['candidate'],
            $context['publisher'],
            ['sort' => 'income_desc'],
        );
        $this->assertSame(SuchakCandidateProposalInboxService::DEFAULT_SORT, $probed['meta']['sort']);
        $this->assertSame(2, $probed['meta']['total']);
    }

    public function test_the_fit_score_beside_a_masked_row_cannot_resolve_finer_than_the_card(): void
    {
        // Trap 14: the card masked the village and the SCORE beside it confirmed it. The inbox
        // prints a fit score per proposal, so it inherits that risk in full — and inherits the fix,
        // because the proposed candidate's representation travels into fit() as the masked side.
        [$inbox, $context] = $this->twoProposalsInDifferentVillagesOfOneTaluka();

        $scores = collect($inbox['proposals'])->pluck('fit.match_score')->unique()->values()->all();
        $this->assertCount(
            1,
            $scores,
            'Two proposals identical except for their village scored differently, so the score names the village.'
        );

        // NON-VACUITY. Two zeroes are also "one distinct value", and a test that passes because the
        // engine returned nothing proves nothing about the cap.
        $this->assertGreaterThan(0, (int) $scores[0], 'The fit engine scored nothing, so this test asserted nothing.');

        // And the reasons must not resolve below the taluka either — the second channel trap 14
        // found (a kilometre figure measured from the village leaf).
        foreach ($inbox['proposals'] as $row) {
            foreach ($row['fit']['reasons'] as $reason) {
                $this->assertStringNotContainsString($context['village_name_of_one_proposal'], (string) $reason);
            }
        }

        // The per-field arithmetic is deliberately not emitted at all: capped it is safe, but the
        // narrowest payload that does the job is the one that cannot leak when the engine widens.
        $this->assertArrayNotHasKey('match_field_points', $inbox['proposals'][0]['fit']);
    }

    // ── Negative: whose inbox is it ───────────────────────────────────────────────────────────

    public function test_a_suchak_may_read_only_his_own_candidates_inbox(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [, $stranger] = $this->verifiedSuchakActor();
        [$male] = $this->genders();
        [$candidate] = $this->publishableCandidate($publisher, $publisherUser, $male);

        try {
            $this->inboxService()->inboxFor($candidate, $stranger);
            $this->fail('A Suchak read another Suchak candidate proposal inbox.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('तुमच्या खात्याचे नाही', $exception->getMessage());
        }
    }

    public function test_the_marketplace_badge_gates_the_inbox_even_for_the_owner_of_the_candidate(): void
    {
        // Owning the candidate is not a substitute for holding the badge. proposalsFor() records
        // the proven version of this hole — the browse list 422'd while the proposals door returned
        // another Suchak's masked candidate with a 200.
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$male] = $this->genders();
        [$candidate] = $this->publishableCandidate($publisher, $publisherUser, $male);

        $publisher->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();

        try {
            $this->inboxService()->inboxFor($candidate->fresh(), $publisher->fresh());
            $this->fail('An unverified Suchak read a marketplace inbox.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('पडताळणी', $exception->getMessage());
        }
    }

    public function test_no_new_table_or_column_stores_what_this_read_derives(): void
    {
        // Derive, never denormalize: a stored proposal count is wrong the moment a proposal is
        // withdrawn, and a stored candidate fact is wrong the moment the originating Suchak
        // changes what he reveals.
        foreach (['proposal_count', 'proposals_received', 'inbox_count', 'last_proposal_at'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('suchak_marketplace_challenges', $forbidden),
                'suchak_marketplace_challenges stores a figure the inbox derives: '.$forbidden
            );
            $this->assertFalse(
                Schema::hasColumn('suchak_profile_representations', $forbidden),
                'suchak_profile_representations stores a figure the inbox derives: '.$forbidden
            );
        }

        $this->assertFalse(Schema::hasTable('suchak_candidate_proposal_inboxes'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function inboxService(): SuchakCandidateProposalInboxService
    {
        return $this->app->make(SuchakCandidateProposalInboxService::class);
    }

    private function challengeService(): SuchakMarketplaceChallengeService
    {
        return $this->app->make(SuchakMarketplaceChallengeService::class);
    }

    /**
     * One publisher, one candidate, one open challenge, one proposal from one helper.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function oneAnsweredChallenge(): array
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$male, $female] = $this->genders();

        [$candidate] = $this->publishableCandidate($publisher, $publisherUser, $male);
        $challenge = $this->challengeService()->publish($publisher, $publisherUser, $candidate, $this->percentTerms(30));

        $proposed = $this->helperCandidate(
            $helper,
            $female,
            'Rahul Kadam',
            village: 'Hiddenwadi',
            addressLine: 'Plot 12, Hidden Lane',
            mobile: '9812345678',
        );

        $this->challengeService()->proposeCandidate($challenge, $helper, $helperUser, $proposed);

        $context = [
            'publisher' => $publisher,
            'candidate' => $candidate,
            'challenge' => $challenge,
            'proposed_representation' => $proposed->fresh(['matrimonyProfile']),
            'proposed_full_name' => 'Rahul Kadam',
            'proposed_surname' => 'Kadam',
            'proposed_village' => 'Hiddenwadi',
            'proposed_address_line' => 'Plot 12, Hidden Lane',
            'proposed_mobile' => '9812345678',
        ];

        return [$this->inboxService()->inboxFor($candidate, $publisher), $context];
    }

    /**
     * Two proposals whose ONLY interesting difference is an income no card prints.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function twoAnsweredProposalsWithDifferentIncomes(): array
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$otherHelperUser, $otherHelper] = $this->verifiedSuchakActor();
        [$male, $female] = $this->genders();

        [$candidate] = $this->publishableCandidate($publisher, $publisherUser, $male);
        $challenge = $this->challengeService()->publish($publisher, $publisherUser, $candidate, $this->percentTerms(30));

        $rich = $this->helperCandidate($helper, $female, 'Rahul Kadam', annualIncome: 1200000);
        $poor = $this->helperCandidate($otherHelper, $female, 'Rekha Kadam', annualIncome: 200000);

        $this->challengeService()->proposeCandidate($challenge, $helper, $helperUser, $rich);
        $this->challengeService()->proposeCandidate($challenge, $otherHelper, $otherHelperUser, $poor);

        return [
            $this->inboxService()->inboxFor($candidate, $publisher),
            ['publisher' => $publisher, 'candidate' => $candidate, 'challenge' => $challenge],
        ];
    }

    /**
     * Two proposals in two different villages of ONE taluka — the location oracle's shape.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function twoProposalsInDifferentVillagesOfOneTaluka(): array
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$helperUser, $helper] = $this->verifiedSuchakActor();
        [$otherHelperUser, $otherHelper] = $this->verifiedSuchakActor();
        [$male, $female] = $this->genders();

        $state = $this->address('Maharashtra', 'state', 1, null);
        $district = $this->address('Pune', 'district', 2, $state);
        $taluka = $this->address('Shirur', 'taluka', 3, $district);
        $villageA = $this->address('Alphawadi', 'village', 4, $taluka, 'rural');
        $villageB = $this->address('Betawadi', 'village', 4, $taluka, 'rural');

        $farTaluka = $this->address('Baramati', 'taluka', 3, $district);
        $this->address('Faraway', 'village', 4, $farTaluka, 'rural');

        [$candidate] = $this->publishableCandidate($publisher, $publisherUser, $male);
        $challenge = $this->challengeService()->publish($publisher, $publisherUser, $candidate, $this->percentTerms(30));

        $inA = $this->helperCandidate($helper, $female, 'Rahul Kadam', locationId: $villageA);
        $inB = $this->helperCandidate($otherHelper, $female, 'Rekha Kadam', locationId: $villageB);

        $this->challengeService()->proposeCandidate($challenge, $helper, $helperUser, $inA);
        $this->challengeService()->proposeCandidate($challenge, $otherHelper, $otherHelperUser, $inB);

        return [
            $this->inboxService()->inboxFor($candidate, $publisher),
            [
                'publisher' => $publisher,
                'candidate' => $candidate,
                'shared_taluka' => $taluka,
                'unrelated_taluka' => $farTaluka,
                'village_of_one_proposal' => $villageA,
                'village_name_of_one_proposal' => 'Alphawadi',
            ],
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function genders(): array
    {
        $male = MasterGender::query()->firstOrCreate(['key' => 'male'], ['label' => 'Male', 'is_active' => true]);
        $female = MasterGender::query()->firstOrCreate(['key' => 'female'], ['label' => 'Female', 'is_active' => true]);

        return [(int) $male->id, (int) $female->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function percentTerms(float $percent): array
    {
        return [
            'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            'declared_share_percent' => $percent,
        ];
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
     * @return array{0: SuchakProfileRepresentation, 1: SuchakCustomerAgreement}
     */
    private function publishableCandidate(SuchakAccount $account, User $user, ?int $genderId = null): array
    {
        $profile = $this->activeProfile('Sunita Gaikwad', $genderId);

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
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
            'package_name' => 'Inbox fixture '.$representation->id,
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
            'agreement_snapshot_hash' => hash('sha256', 'inbox-'.$package->id),
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

    private function helperCandidate(
        SuchakAccount $account,
        ?int $genderId = null,
        string $fullName = 'Rahul Kadam',
        ?string $village = null,
        ?string $addressLine = null,
        ?string $mobile = null,
        ?int $annualIncome = null,
        ?int $locationId = null,
    ): SuchakProfileRepresentation {
        $profile = $this->activeProfile($fullName, $genderId, $village, $locationId);

        // `matrimony_profiles.address_line` was dropped when residence moved to `profile_addresses`
        // — see test_the_detailed_address_reveal_is_structurally_dead_on_this_schema. Written only
        // when the column is actually there, so this fixture keeps working either way.
        $updates = array_filter([
            'address_line' => Schema::hasColumn($profile->getTable(), 'address_line') ? $addressLine : null,
            'annual_income' => $annualIncome,
        ], static fn ($value): bool => $value !== null);

        if ($updates !== []) {
            DB::table($profile->getTable())->where('id', $profile->id)->update($updates);
            $profile->refresh();
        }

        // The candidate's own mobile lives in `profile_contacts`, never on the profile row
        // (FIELD-OWNERSHIP-MAP). It is stored here so the leak scan has a real number to look for.
        if ($mobile !== null) {
            DB::table('profile_contacts')->insert([
                'profile_id' => $profile->id,
                'contact_name' => $fullName,
                'phone_number' => $mobile,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /** @var SuchakProfileRepresentation $representation */
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        return $representation->fresh(['suchakAccount', 'matrimonyProfile.gender']);
    }

    private function activeProfile(
        string $fullName,
        ?int $genderId,
        ?string $villageName = null,
        ?int $locationId = null,
    ): MatrimonyProfile {
        if ($locationId === null) {
            $state = $this->address('Maharashtra', 'state', 1, null);
            $district = $this->address('Pune', 'district', 2, $state);
            $taluka = $this->address('Shirur', 'taluka', 3, $district);
            $locationId = $this->address($villageName ?? 'Ranjangaon', 'village', 4, $taluka, 'rural');
        }

        $profile = MatrimonyProfile::factory()->create(array_filter([
            'full_name' => $fullName,
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'gender_id' => $genderId,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], static fn ($value): bool => $value !== null));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $locationId]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $locationId, null, true, false);
        }

        $profile->update([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ]);

        return $profile->fresh();
    }

    private function address(string $name, string $hierarchy, int $level, ?int $parent, ?string $tag = null): int
    {
        return DB::table('addresses')->insertGetId(array_filter([
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
}
