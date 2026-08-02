<?php

namespace Tests\Feature\Suchak;

use App\Models\MasterGender;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakMarketEconomicsService;
use App\Modules\Suchak\Services\SuchakMarketplaceChallengeService;
use App\Modules\Suchak\Services\SuchakReputationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * THE MARKET ECONOMICS VIEW (blueprint phase 5, §9 "another customer's fees — market economics").
 *
 * The only line in §9's visibility matrix that hands a Suchak anything about a customer who is not
 * his, and it hands it for one purpose: deciding whether to publish his own customer, and at what
 * share. Phase 5's gate is "the market can sort itself", which it cannot do while every publisher
 * guesses what a normal share is.
 *
 * The whole risk is that an aggregate over a thin population is not an aggregate. "The typical
 * share is 30%" computed over one challenge republishes that publisher's private terms under a
 * different sentence.
 */
class SuchakMarketEconomicsTest extends TestCase
{
    use RefreshDatabase;

    // ── Aggregate means aggregate ─────────────────────────────────────────────────────────────

    public function test_a_thin_market_withholds_every_derived_figure_rather_than_publishing_one_suchaks_terms(): void
    {
        [$viewerUser, $viewer] = $this->verifiedSuchakActor();

        // ONE publisher, TWO challenges. Both thresholds fail: too few observations AND — the half
        // that matters — too few people, so the "typical share" would be one man's terms.
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        $this->publishChallenge($publisher, $publisherUser, 30);
        $this->publishChallenge($publisher, $publisherUser, 70);

        $market = $this->service()->marketFor($viewer);

        foreach ([
            $market['supply'],
            $market['response']['answered'],
            $market['response']['speed'],
            $market['response']['depth'],
            $market['declared_share']['percent'],
            $market['outcomes']['marriage'],
        ] as $block) {
            $this->assertTrue($block['is_withheld'], 'A figure was published over a thin population.');
            $this->assertSame(SuchakMarketEconomicsService::REFUSAL_TOO_THIN, $block['withheld_reason']);
        }

        // Nothing may be published, and every figure key must still be there and null — a client
        // with a stable shape can say "too few"; a vanishing key reads as a server error and
        // invites a fallback that computes the figure itself.
        $this->assertNull($market['declared_share']['percent']['median_percent']);
        $this->assertNull($market['declared_share']['percent']['median_percent_display']);
        $this->assertNull($market['supply']['published_challenges']);
        $this->assertNull($market['outcomes']['marriage']['marriage_rate_percent']);

        // But HOW thin survives, because "withheld" and "empty" are different sentences and the
        // counts are participation, never terms.
        $this->assertSame(2, $market['declared_share']['percent']['observations']);
        $this->assertSame(1, $market['declared_share']['percent']['publishers']);

        // Every declared rupee figure is likewise absent rather than approximated.
        foreach ($market['declared_share']['value_by_currency'] as $currency) {
            $this->assertTrue($currency['is_withheld']);
            $this->assertNull($currency['median_share_display']);
        }
    }

    public function test_five_challenges_from_one_publisher_do_not_unlock_a_figure(): void
    {
        // The distinct-publisher half, on its own. Observations are cheap to manufacture — one
        // Suchak can publish twenty — and a threshold counted only in rows would let him talk the
        // market average onto his own terms while the figure still read as "typical".
        [, $viewer] = $this->verifiedSuchakActor();
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();

        foreach ([10, 20, 30, 40, 50] as $percent) {
            $this->publishChallenge($publisher, $publisherUser, $percent);
        }

        $share = $this->service()->marketFor($viewer)['declared_share']['percent'];

        $this->assertSame(5, $share['observations']);
        $this->assertSame(1, $share['publishers']);
        $this->assertTrue($share['is_withheld'], 'Five challenges from one Suchak published his own median as the market.');
        $this->assertNull($share['median_percent']);
    }

    public function test_the_typical_declared_share_is_published_once_five_separate_suchaks_have_declared_one(): void
    {
        [, $viewer] = $this->verifiedSuchakActor();

        foreach ([20, 25, 30, 35, 40] as $percent) {
            [$publisherUser, $publisher] = $this->verifiedSuchakActor();
            $this->publishChallenge($publisher, $publisherUser, $percent);
        }

        $market = $this->service()->marketFor($viewer);
        $share = $market['declared_share']['percent'];

        $this->assertSame(5, $share['observations']);
        $this->assertSame(5, $share['publishers']);
        $this->assertFalse($share['is_withheld']);
        $this->assertNull($share['withheld_reason']);

        // The MEDIAN, in Latin digits with no trailing zeros. Not the mean — over five values a
        // reader who knows four recovers the fifth from a mean exactly.
        $this->assertSame('30', $share['median_percent']);
        $this->assertSame('30%', $share['median_percent_display']);

        // And the same declaration in money: 30% of the ₹1,00,000 success fee every fixture froze.
        // Preformatted server-side with Indian grouping so no client re-derives money.
        $inr = collect($market['declared_share']['value_by_currency'])->firstWhere('currency', 'INR');
        $this->assertNotNull($inr);
        $this->assertFalse($inr['is_withheld']);
        $this->assertSame('₹30,000', $inr['median_share_display']);
    }

    public function test_no_range_extreme_or_quartile_is_published_anywhere(): void
    {
        // The threshold protects the middle of the distribution; a published range would undo it
        // from the ends. At n = 5 a minimum, a maximum or a quartile IS an individual Suchak's
        // declared share, printed exactly.
        [, $viewer] = $this->verifiedSuchakActor();

        foreach ([20, 25, 30, 35, 40] as $percent) {
            [$publisherUser, $publisher] = $this->verifiedSuchakActor();
            $this->publishChallenge($publisher, $publisherUser, $percent);
        }

        $encoded = (string) json_encode($this->service()->marketFor($viewer));

        foreach (['min_', '_min', 'max_', '_max', 'p25', 'p75', 'quartile', 'range', 'lowest', 'highest'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $encoded,
                'The market view published a distribution endpoint, which at n=5 is one Suchak declared share: '.$forbidden
            );
        }

        // And no individual declaration survives as a figure either — 20 and 40 are the endpoints.
        $this->assertStringNotContainsString('"20"', $encoded);
        $this->assertStringNotContainsString('"40"', $encoded);
    }

    public function test_the_viewers_own_challenges_are_never_in_the_denominator(): void
    {
        // A reader who is in his own aggregate can subtract himself out of it. He is therefore
        // excluded from every set — which is also what makes the read mean "the market I would be
        // publishing into", and matches browse(), which has always excluded own challenges.
        [$viewerUser, $viewer] = $this->verifiedSuchakActor();

        foreach ([20, 25, 30, 35, 40] as $percent) {
            [$publisherUser, $publisher] = $this->verifiedSuchakActor();
            $this->publishChallenge($publisher, $publisherUser, $percent);
        }

        // The viewer now declares wildly. If he counted, the median would move to 32.5.
        $this->publishChallenge($viewer, $viewerUser, 95);
        $this->publishChallenge($viewer, $viewerUser, 99);

        $market = $this->service()->marketFor($viewer);

        $this->assertSame(5, $market['declared_share']['percent']['observations']);
        $this->assertSame('30', $market['declared_share']['percent']['median_percent']);
        $this->assertSame(5, $market['supply']['published_challenges']);
        $this->assertSame(5, $market['open_now']['challenges'], 'The viewer own open challenges reached the open count.');
    }

    // ── The figures themselves ────────────────────────────────────────────────────────────────

    public function test_open_now_is_the_one_block_never_withheld_because_browse_already_lists_those_rows(): void
    {
        // Not an exception to the rule — a different set. This predicate is browse()'s exactly, so
        // every row counted is a row this same viewer can page through one by one with the
        // publisher's name and declared share on it. Withholding a count of what he is already
        // looking at protects nobody and leaves him unable to tell an empty market from a broken
        // screen.
        [, $viewer] = $this->verifiedSuchakActor();
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        $this->publishChallenge($publisher, $publisherUser, 30);

        $market = $this->service()->marketFor($viewer);

        $this->assertFalse($market['open_now']['is_withheld']);
        $this->assertSame(1, $market['open_now']['challenges']);
        $this->assertSame(1, $market['open_now']['publishers']);

        // The count and browse() must agree, or one of them is lying about the same rows.
        $this->assertSame(
            $market['open_now']['challenges'],
            $this->challengeService()->browse($viewer)->total(),
        );
    }

    public function test_answered_speed_depth_and_marriage_are_published_over_a_wide_enough_market(): void
    {
        [, $viewer] = $this->verifiedSuchakActor();

        // Five publishers, five challenges, every one answered a known number of hours later.
        $hours = [2, 4, 6, 8, 10];
        foreach ($hours as $index => $hour) {
            [$publisherUser, $publisher] = $this->verifiedSuchakActor();
            $challenge = $this->publishChallenge($publisher, $publisherUser, 30, publishedAt: now()->subDays(3));
            $this->recordProposals($challenge, $index + 1, now()->subDays(3)->addHours($hour));
        }

        $market = $this->service()->marketFor($viewer);

        $answered = $market['response']['answered'];
        $this->assertFalse($answered['is_withheld']);
        $this->assertSame(5, $answered['answered_challenges']);
        $this->assertSame('100', $answered['answered_rate_percent']);
        $this->assertSame('100%', $answered['answered_rate_display']);

        $speed = $market['response']['speed'];
        $this->assertFalse($speed['is_withheld']);
        $this->assertSame(6, $speed['median_hours_to_first_proposal']);
        $this->assertSame('0.3', $speed['median_days_to_first_proposal']);

        $depth = $market['response']['depth'];
        $this->assertFalse($depth['is_withheld']);
        $this->assertSame('3.0', $depth['median_proposals_per_answered_challenge']);

        // Nobody married, and that is a real 0% over a wide enough set — not a withholding.
        $marriage = $market['outcomes']['marriage'];
        $this->assertFalse($marriage['is_withheld']);
        $this->assertSame(0, $marriage['challenges_with_recorded_marriage']);
        $this->assertSame('0', $marriage['marriage_rate_percent']);
        $this->assertSame('0%', $marriage['marriage_rate_display']);
    }

    public function test_an_unanswered_market_withholds_the_speed_figure_while_still_publishing_the_answered_rate(): void
    {
        // Two sets, two verdicts. A market can publish plenty and answer almost nothing, and a
        // speed figure resting on the one challenge that was answered would name its publisher.
        [, $viewer] = $this->verifiedSuchakActor();

        $challenges = [];
        foreach (range(1, 5) as $i) {
            [$publisherUser, $publisher] = $this->verifiedSuchakActor();
            $challenges[] = $this->publishChallenge($publisher, $publisherUser, 30, publishedAt: now()->subDays(2));
        }

        $this->recordProposals($challenges[0], 1, now()->subDays(2)->addHours(5));

        $market = $this->service()->marketFor($viewer);

        $this->assertFalse($market['response']['answered']['is_withheld']);
        $this->assertSame(1, $market['response']['answered']['answered_challenges']);
        $this->assertSame('20', $market['response']['answered']['answered_rate_percent']);

        $this->assertTrue($market['response']['speed']['is_withheld']);
        $this->assertSame(1, $market['response']['speed']['observations']);
        $this->assertNull($market['response']['speed']['median_hours_to_first_proposal']);
    }

    public function test_the_observation_window_is_stated_and_enforced(): void
    {
        // The only bound on the row load, and a product bound rather than a performance dodge: a
        // share declared two years ago is not evidence about today's market. It is reported so
        // nobody has to guess what "typical" covers.
        [, $viewer] = $this->verifiedSuchakActor();

        foreach ([20, 25, 30, 35, 40] as $percent) {
            [$publisherUser, $publisher] = $this->verifiedSuchakActor();
            $challenge = $this->publishChallenge($publisher, $publisherUser, $percent);
            DB::table('suchak_marketplace_challenges')
                ->where('id', $challenge->id)
                ->update(['published_at' => now()->subDays(SuchakMarketEconomicsService::WINDOW_DAYS + 5)]);
        }

        $market = $this->service()->marketFor($viewer);

        $this->assertSame(SuchakMarketEconomicsService::WINDOW_DAYS, $market['window']['days']);
        $this->assertSame(0, $market['declared_share']['percent']['observations']);
        $this->assertTrue($market['declared_share']['percent']['is_withheld']);

        // Still OPEN, though — `open_now` is deliberately outside the window, because a challenge
        // published fourteen months ago and still live is still competing for helpers today.
        $this->assertSame(5, $market['open_now']['challenges']);
    }

    // ── Gates and shape ───────────────────────────────────────────────────────────────────────

    public function test_the_marketplace_badge_gates_the_market_view(): void
    {
        // §9 grants "another customer's fees" to VERIFIED Suchaks and to nobody else; A10 is why —
        // an unverified second account is the cheap way to read the market without being in it.
        [, $viewer] = $this->verifiedSuchakActor();
        $viewer->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();

        try {
            $this->service()->marketFor($viewer->fresh());
            $this->fail('An unverified Suchak read the market economics view.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('पडताळणी', $exception->getMessage());
        }
    }

    public function test_the_threshold_is_the_one_this_codebase_already_uses(): void
    {
        // One platform, one answer to "how thin is too thin". The reputation read suppresses a
        // behavioural rate under the same count; two different numbers would mean two different
        // stories about the same market on two adjacent screens.
        $this->assertSame(5, SuchakMarketEconomicsService::MIN_OBSERVATIONS);
        $this->assertSame(5, SuchakMarketEconomicsService::MIN_DISTINCT_PUBLISHERS);

        // Guarded because the reputation read is a sibling phase-5 slice built alongside this one:
        // the pin is worth having and is not worth failing this suite over the day that class is
        // renamed. If it exists, the two numbers must agree.
        if (defined(SuchakReputationService::class.'::MIN_RATE_DENOMINATOR')) {
            $this->assertSame(
                SuchakReputationService::MIN_RATE_DENOMINATOR,
                SuchakMarketEconomicsService::MIN_OBSERVATIONS,
                'Two Suchak reads disagree about how thin a population is too thin.'
            );
        }
    }

    public function test_nothing_the_market_view_publishes_is_stored(): void
    {
        // Derive, never denormalize. A stored answered-count is wrong the moment a proposal is
        // withdrawn, and a stored share median is wrong the moment a challenge is republished.
        foreach ([
            'market_median_share', 'answered_rate', 'marriage_rate', 'proposal_count',
            'first_proposal_at', 'median_share_percent',
        ] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('suchak_marketplace_challenges', $forbidden),
                'suchak_marketplace_challenges stores a figure the market view derives: '.$forbidden
            );
        }

        foreach (['suchak_market_economics', 'suchak_market_stats', 'suchak_marketplace_aggregates'] as $table) {
            $this->assertFalse(Schema::hasTable($table), 'A rollup table was created for a derived read: '.$table);
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function service(): SuchakMarketEconomicsService
    {
        return $this->app->make(SuchakMarketEconomicsService::class);
    }

    private function challengeService(): SuchakMarketplaceChallengeService
    {
        return $this->app->make(SuchakMarketplaceChallengeService::class);
    }

    private function publishChallenge(
        SuchakAccount $account,
        User $user,
        float $percent,
        ?\Illuminate\Support\Carbon $publishedAt = null,
    ): SuchakMarketplaceChallenge {
        [$representation] = $this->publishableCandidate($account, $user);

        $challenge = $this->challengeService()->publish($account, $user, $representation, [
            'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            'declared_share_percent' => $percent,
        ]);

        if ($publishedAt !== null) {
            DB::table('suchak_marketplace_challenges')
                ->where('id', $challenge->id)
                ->update(['published_at' => $publishedAt]);
            $challenge->refresh();
        }

        return $challenge;
    }

    /**
     * N proposals against one challenge, the first of them at a known instant.
     *
     * Written straight onto `suchak_collaboration_requests` rather than through
     * proposeCandidate(): this test is about ARITHMETIC over recorded proposals, and building five
     * helper Suchaks with consented candidates per challenge would make the fixture the subject.
     * The proposal path itself is covered by SuchakAcceptByProposingTest and by the inbox test.
     */
    private function recordProposals(
        SuchakMarketplaceChallenge $challenge,
        int $count,
        \Illuminate\Support\Carbon $firstAt,
    ): void {
        $challenge->loadMissing('representation');

        for ($i = 0; $i < $count; $i++) {
            [$helperUser, $helper] = $this->verifiedSuchakActor();
            $helperProfile = $this->activeProfile('Helper Candidate '.$challenge->id.'-'.$i);

            /** @var SuchakProfileRepresentation $helperRepresentation */
            $helperRepresentation = SuchakProfileRepresentation::factory()->create([
                'suchak_account_id' => $helper->id,
                'matrimony_profile_id' => $helperProfile->id,
                'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
                'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
                'consent_verified_at' => now(),
                'consent_valid_until' => now()->addYear(),
            ]);

            SuchakCollaborationRequest::query()->create([
                'requesting_suchak_account_id' => $helper->id,
                'target_suchak_account_id' => $challenge->suchak_account_id,
                'requesting_matrimony_profile_id' => $helperProfile->id,
                'target_matrimony_profile_id' => $challenge->representation->matrimony_profile_id,
                'requesting_representation_id' => $helperRepresentation->id,
                'target_representation_id' => $challenge->representation_id,
                'marketplace_challenge_id' => $challenge->id,
                'customer_owner_side' => SuchakCollaborationRequest::SIDE_TARGET,
                'status' => SuchakCollaborationRequest::STATUS_PENDING,
                'requested_at' => $firstAt->copy()->addHours($i),
                'expires_at' => $firstAt->copy()->addDays(30),
            ]);
        }
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
    private function publishableCandidate(SuchakAccount $account, User $user): array
    {
        $profile = $this->activeProfile('Sunita Gaikwad');

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
            'package_name' => 'Economics fixture '.$representation->id,
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
            // Blueprint 7.1's worked example — the base every declared percent is a slice of.
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
            'agreement_snapshot_hash' => hash('sha256', 'economics-'.$package->id),
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

    private function activeProfile(string $fullName): MatrimonyProfile
    {
        $state = $this->address('Maharashtra', 'state', 1, null);
        $district = $this->address('Pune', 'district', 2, $state);
        $taluka = $this->address('Shirur', 'taluka', 3, $district);
        $village = $this->address('Ranjangaon', 'village', 4, $taluka, 'rural');

        $male = MasterGender::query()->firstOrCreate(['key' => 'male'], ['label' => 'Male', 'is_active' => true]);

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => $fullName,
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'gender_id' => $male->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $village]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, $village, null, true, false);
        }

        $profile->update(['lifecycle_state' => 'active', 'is_suspended' => false]);

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
