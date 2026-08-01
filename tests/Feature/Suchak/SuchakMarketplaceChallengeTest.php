<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakMarketplaceChallenge;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakMarketplaceChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * The challenge object (blueprint D4 / D18 / section 11 phase 2).
 *
 * A Suchak publishes "I hold this customer; I will pay X to whoever brings the match", BEFORE any
 * helper exists. Nothing in the schema could hold that: every candidate owner names two Suchak
 * accounts NOT NULL from row one, and a challenge by definition has no counterparty yet.
 */
class SuchakMarketplaceChallengeTest extends TestCase
{
    use RefreshDatabase;

    // ── The verdict: it really did not exist ──────────────────────────────────────────────────

    public function test_the_engagement_pair_cannot_hold_a_counterparty_less_challenge(): void
    {
        // The decisive fact. Both account ids are NOT NULL from the foundation migration, so a row
        // describing "I will pay whoever turns up" cannot be inserted — there is no whoever yet.
        foreach (['requesting_suchak_account_id', 'target_suchak_account_id'] as $column) {
            $this->assertFalse(
                $this->columnIsNullable('suchak_collaboration_requests', $column),
                $column.' is nullable, so the engagement pair could have held a challenge after all.'
            );
        }

        // And the commission agreement, which carries the split, hangs off a collaboration request.
        $this->assertFalse($this->columnIsNullable('suchak_commission_agreements', 'collaboration_request_id'));

        $this->assertTrue(Schema::hasTable('suchak_marketplace_challenges'));
    }

    public function test_the_challenge_keeps_no_second_copy_of_the_candidates_visible_facts(): void
    {
        // Cross-Suchak presentation has exactly one owner (D19a / S6). A column here named after any
        // of the four masked facts would be a second owner and would go stale the moment the
        // originating Suchak changed his mind.
        foreach ([
            'full_name', 'candidate_name', 'display_name', 'village', 'city', 'district',
            'address_line', 'mobile', 'photo_path', 'profile_photo', 'age_years',
        ] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('suchak_marketplace_challenges', $forbidden),
                'suchak_marketplace_challenges carries a copy of a masked candidate fact: '.$forbidden
            );
        }

        // Nor a second success-fee figure: the share is a slice of the package's frozen fee.
        $this->assertFalse(Schema::hasColumn('suchak_marketplace_challenges', 'success_fee_amount'));
        // Nor the ceiling section 5.5 still mentions — D17 and section 15 reversed it twice.
        $this->assertFalse(Schema::hasColumn('suchak_marketplace_challenges', 'chargeable_ceiling'));
    }

    // ── The currency has one owner, and this row is not it ────────────────────────────────────

    public function test_the_challenge_carries_no_currency_of_its_own(): void
    {
        // suchak_service_packages.currency owns it and suchak_customer_agreements.currency is its
        // frozen snapshot. A third column here was a third owner, and it let a publisher relabel his
        // own money — see the render test below for the exact figures it produced.
        foreach (['share_currency', 'currency', 'declared_share_currency'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('suchak_marketplace_challenges', $forbidden),
                'suchak_marketplace_challenges carries a second owner of the currency: '.$forbidden
            );
        }

        $this->assertNotContains('share_currency', (new SuchakMarketplaceChallenge)->getFillable());
    }

    public function test_the_share_is_rendered_in_the_currency_of_the_agreement_it_points_at(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation, $agreement] = $this->publishableCandidate($account, $user);

        // Blueprint 7.1's worked example: a ₹1,00,000 success fee, 30% declared.
        $challenge = $this->service()->publish($account, $user, $representation, $this->percentTerms(30));

        $share = $this->service()->listingPayload($challenge)['declared_share'];
        $this->assertSame('INR', $share['currency']);
        $this->assertSame('₹1,00,000', $share['success_fee_display']);
        $this->assertSame('₹30,000', $share['estimated_share_display']);

        // Now move the money itself. The agreement is the frozen snapshot of the package's currency,
        // so BOTH have to move — that is the point: only the owner of the money can rename it, and
        // when he does, the challenge follows without being touched.
        DB::table('suchak_service_packages')->where('id', $agreement->service_package_id)
            ->update(['currency' => 'USD']);
        DB::table('suchak_customer_agreements')->where('id', $agreement->id)
            ->update(['currency' => 'USD']);

        $moved = $this->service()->listingPayload($challenge->fresh())['declared_share'];
        $this->assertSame('USD', $moved['currency'], 'The listing did not follow the agreement, so it is reading something else.');
        $this->assertSame('USD 1,00,000', $moved['success_fee_display']);
        $this->assertSame('USD 30,000', $moved['estimated_share_display']);

        // And a NULL snapshot falls back to the package it was copied from, then to INR — never to
        // anything a caller supplied.
        DB::table('suchak_customer_agreements')->where('id', $agreement->id)->update(['currency' => null]);
        $this->assertSame('USD', $challenge->fresh()->declaredShareCurrency());

        DB::table('suchak_service_packages')->where('id', $agreement->service_package_id)
            ->update(['currency' => null]);
        $this->assertSame('INR', $challenge->fresh()->declaredShareCurrency());
    }

    public function test_a_publisher_cannot_assert_a_currency_over_the_agreements(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation] = $this->publishableCandidate($account, $user);

        // THE PROVEN ATTACK. Package and agreement are INR with a ₹1,00,000 success fee. Before this
        // fix the route's size:3 rule passed, the service passed, the row saved, and browse rendered
        // "USD 1,00,000" / "USD 30,000" to every Suchak deciding whether the work was worth doing.
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/suchak/marketplace/challenges', [
            'representation_id' => $representation->id,
            'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            'declared_share_percent' => 30,
            'share_currency' => 'USD',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('share_currency');

        // Refused, not quietly dropped: nothing was published.
        $this->assertSame(0, SuchakMarketplaceChallenge::query()->count());

        // The same refusal one layer down, so a caller that bypasses the route gains nothing.
        try {
            $this->service()->publish($account, $user, $representation, $this->percentTerms(30) + [
                'share_currency' => 'USD',
            ]);
            $this->fail('The service accepted a currency the agreement does not own.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, SuchakMarketplaceChallenge::query()->count());
        }

        // Published honestly, it reads in the agreement's own money.
        $challenge = $this->service()->publish($account, $user, $representation->fresh(), $this->percentTerms(30));
        $share = $this->service()->listingPayload($challenge)['declared_share'];

        $this->assertSame('INR', $share['currency']);
        $this->assertSame('₹1,00,000', $share['success_fee_display']);
        $this->assertSame('₹30,000', $share['estimated_share_display']);
    }

    public function test_the_declared_share_vocabulary_is_the_commission_agreements_own(): void
    {
        // Bound, not re-declared — so the commission agreement written when a helper is accepted can
        // carry the same words the challenge declared.
        $this->assertSame([
            SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT,
        ], SuchakMarketplaceChallenge::DECLARED_SHARE_TYPES);

        // D4: decided upfront, not negotiable. "To be discussed" is the one thing it cannot say.
        $this->assertNotContains(
            SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED,
            SuchakMarketplaceChallenge::DECLARED_SHARE_TYPES,
        );
    }

    // ── Publishing ────────────────────────────────────────────────────────────────────────────

    public function test_publishing_freezes_the_accepted_agreement_and_writes_the_ladder_stage(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation, $agreement] = $this->publishableCandidate($account, $user);

        $challenge = $this->service()->publish($account, $user, $representation, [
            'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            'declared_share_percent' => 30,
            'publisher_note' => 'Pune area, engineer preferred.',
        ]);

        $this->assertSame(SuchakMarketplaceChallenge::STATUS_OPEN, $challenge->status);
        $this->assertSame((int) $representation->id, (int) $challenge->representation_id);
        // Section 4: publication attaches to whichever agreement is ACCEPTED at that moment. The
        // publisher never named it — the service resolved it and froze it.
        $this->assertSame((int) $agreement->id, (int) $challenge->customer_agreement_id);
        $this->assertSame('30.00', (string) $challenge->declared_share_percent);
        $this->assertNull($challenge->declared_share_amount);
        $this->assertSame(SuchakMarketplaceChallenge::AUDIENCE_VERIFIED_SUCHAKS, $challenge->audience);
        // The publisher's expiry decision, and NULL is one: "open until I withdraw it".
        $this->assertNull($challenge->expires_at);

        // The ladder row goes on the CUSTOMER AGREEMENT, never on an engagement — publication is
        // the act that invites a counterparty, so none can exist yet.
        $this->assertDatabaseHas('suchak_collaboration_stage_events', [
            'customer_agreement_id' => $agreement->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE,
            'collaboration_request_id' => null,
        ]);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'action_type' => SuchakActivityLog::ACTION_MARKETPLACE_CHALLENGE_PUBLISHED,
            'target_type' => 'suchak_marketplace_challenge',
            'target_id' => $challenge->id,
        ]);
    }

    public function test_republishing_adds_a_challenge_but_not_a_second_ladder_row(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation, $agreement] = $this->publishableCandidate($account, $user);
        $service = $this->service();

        $first = $service->publish($account, $user, $representation, $this->percentTerms(30));
        $service->withdraw($first, $account, $user, 'Family paused the search.');
        $service->publish($account, $user, $representation, $this->percentTerms(45));

        // "Times published" (A12) is this table's own count(*), which is why it is not a column.
        $this->assertSame(2, SuchakMarketplaceChallenge::query()
            ->where('representation_id', $representation->id)
            ->count());

        // The ladder stage is recordable ONCE PER AGREEMENT REVISION — unique(customer_agreement_id,
        // stage_key). Re-publishing at the same rate must not count twice on the spine that
        // installments and dispute resolution read.
        $this->assertSame(1, SuchakCollaborationStageEvent::query()
            ->where('customer_agreement_id', $agreement->id)
            ->where('stage_key', SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE)
            ->count());
    }

    public function test_one_candidate_cannot_carry_two_open_challenges(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation] = $this->publishableCandidate($account, $user);
        $service = $this->service();

        $service->publish($account, $user, $representation, $this->percentTerms(30));

        // A8's escape hatch: suggest under the generous share, pay under the mean one.
        $this->expectException(InvalidArgumentException::class);
        $service->publish($account, $user, $representation, $this->percentTerms(10));
    }

    public function test_a_percent_share_needs_a_fixed_success_fee_to_be_a_share_of(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation, $agreement] = $this->publishableCandidate($account, $user);

        // D5 makes "none" legitimate — a Suchak who declared nothing owes nothing. Promising a
        // PERCENTAGE of nothing is the contradiction, not the mode.
        $agreement->servicePackage->forceFill([
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_NONE,
            'post_marriage_fee_amount' => null,
        ])->save();

        try {
            $this->service()->publish($account, $user, $representation->fresh(), $this->percentTerms(30));
            $this->fail('A percent share was accepted against a package with no fixed success fee.');
        } catch (InvalidArgumentException) {
            // A FIXED rupee declaration needs no base — it is owed regardless of what the customer pays.
            $challenge = $this->service()->publish($account, $user, $representation->fresh(), [
                'declared_share_type' => SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT,
                'declared_share_amount' => 25000,
            ]);

            $this->assertSame('25000.00', (string) $challenge->declared_share_amount);
            $this->assertNull($challenge->declared_share_percent);
        }
    }

    public function test_a_declared_share_can_never_say_two_things_at_once(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation, $agreement] = $this->publishableCandidate($account, $user);

        // Straight at the model guard: D4 leaves no later conversation in which "percent or rupees?"
        // could be settled, so the row must never be able to ask the question.
        $this->expectException(InvalidArgumentException::class);

        SuchakMarketplaceChallenge::query()->create([
            'suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'customer_agreement_id' => $agreement->id,
            'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            'declared_share_percent' => 30,
            'declared_share_amount' => 25000,
            'audience' => SuchakMarketplaceChallenge::AUDIENCE_VERIFIED_SUCHAKS,
            'status' => SuchakMarketplaceChallenge::STATUS_OPEN,
            'published_by_user_id' => $user->id,
            'published_at' => now(),
        ]);
    }

    public function test_publishing_needs_an_accepted_agreement_a_verified_badge_and_live_consent(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation, $agreement] = $this->publishableCandidate($account, $user);
        $service = $this->service();

        // D3 freezes amounts on ACCEPTANCE. A share declared against pending terms would be a slice
        // of a number that can still move.
        DB::table('suchak_customer_agreements')->where('id', $agreement->id)
            ->update(['terms_status' => SuchakCustomerAgreement::TERMS_PENDING]);
        $this->assertPublishRefused($service, $account, $user, $representation->fresh());
        DB::table('suchak_customer_agreements')->where('id', $agreement->id)
            ->update(['terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED]);

        // A10: marketplace participation is tied to the verification badge, on both sides.
        $account->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();
        $this->assertPublishRefused($service, $account->fresh(), $user, $representation->fresh());
        $account->forceFill(['verification_status' => SuchakAccount::VERIFICATION_VERIFIED])->save();

        // Section 15: cross-Suchak sharing is legitimate because the candidate consented to being
        // "forwarded to suitable and appropriate matches". A lapsed consent covers nothing.
        $representation->forceFill(['consent_status' => SuchakProfileRepresentation::CONSENT_EXPIRED])->save();
        $this->assertPublishRefused($service, $account->fresh(), $user, $representation->fresh());
    }

    // ── Withdrawing and expiring ──────────────────────────────────────────────────────────────

    public function test_withdrawing_closes_the_challenge_and_keeps_the_row(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation] = $this->publishableCandidate($account, $user);
        $service = $this->service();

        $challenge = $service->publish($account, $user, $representation, $this->percentTerms(30));
        $withdrawn = $service->withdraw($challenge, $account, $user, 'Family paused the search.');

        $this->assertSame(SuchakMarketplaceChallenge::STATUS_WITHDRAWN, $withdrawn->status);
        $this->assertNotNull($withdrawn->withdrawn_at);
        $this->assertSame('Family paused the search.', $withdrawn->withdrawn_reason);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_MARKETPLACE_CHALLENGE_WITHDRAWN,
            'target_id' => $challenge->id,
        ]);

        // A7 and A8 both read declarations a publisher would prefer gone.
        $this->expectException(RuntimeException::class);
        $withdrawn->delete();
    }

    public function test_a_stranger_cannot_withdraw_and_a_closed_challenge_cannot_be_withdrawn_twice(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$otherUser, $otherAccount] = $this->verifiedSuchakActor();
        [$representation] = $this->publishableCandidate($account, $user);
        $service = $this->service();

        $challenge = $service->publish($account, $user, $representation, $this->percentTerms(30));

        try {
            $service->withdraw($challenge, $otherAccount, $otherUser);
            $this->fail('Another Suchak withdrew a challenge that was not his.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $service->withdraw($challenge, $account, $user);

        $this->expectException(InvalidArgumentException::class);
        $service->withdraw($challenge->fresh(), $account, $user);
    }

    public function test_expiry_closes_only_what_the_publisher_dated(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$dated] = $this->publishableCandidate($account, $user);
        [$open] = $this->publishableCandidate($account, $user);
        $service = $this->service();

        $expiring = $service->publish($account, $user, $dated, $this->percentTerms(30) + [
            'expires_at' => now()->addDay()->toIso8601String(),
        ]);
        $neverExpires = $service->publish($account, $user, $open, $this->percentTerms(30));

        $this->travel(2)->days();

        $this->assertSame(1, $service->expireDue());

        $this->assertSame(SuchakMarketplaceChallenge::STATUS_EXPIRED, $expiring->fresh()->status);
        // NULL is not "missing", it is "open until I withdraw it", and the sweep must respect that.
        $this->assertSame(SuchakMarketplaceChallenge::STATUS_OPEN, $neverExpires->fresh()->status);

        // Nobody acted; a date arrived.
        $this->assertDatabaseHas('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_MARKETPLACE_CHALLENGE_EXPIRED,
            'target_id' => $expiring->id,
            'actor_type' => SuchakActivityLog::ACTOR_SYSTEM,
            'actor_user_id' => null,
        ]);

        // Idempotent: a second sweep finds nothing left to close.
        $this->assertSame(0, $service->expireDue());
    }

    // ── The listing read (D18 / D19a) ─────────────────────────────────────────────────────────

    public function test_browsing_shows_other_verified_suchaks_the_masked_candidate_and_the_share(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [, $viewer] = $this->verifiedSuchakActor();
        [$representation] = $this->publishableCandidate($publisher, $publisherUser);
        $service = $this->service();

        $service->publish($publisher, $publisherUser, $representation, $this->percentTerms(30));

        $listings = $service->browse($viewer);
        $this->assertCount(1, $listings->items());

        $listing = $listings->items()[0];

        // The candidate block is SuchakCandidateMaskingService's output verbatim: village withheld,
        // name masked, photograph always present (D19a).
        $this->assertSame('Sunita G.', $listing['candidate']['display_name']);
        $this->assertTrue($listing['candidate']['location']['is_broad']);
        $this->assertSame('Shirur', $listing['candidate']['location']['city']);
        $this->assertNull($listing['candidate']['location']['exact_address']);
        $this->assertArrayHasKey('photo', $listing['candidate']);

        // The declaration, plus the base it is a share OF — a percent without its base tells a
        // helper nothing about whether the work is worth doing (section 9, market economics).
        $this->assertSame('30%', $listing['declared_share']['display']);
        $this->assertSame('₹1,00,000', $listing['declared_share']['success_fee_display']);
        $this->assertSame('₹30,000', $listing['declared_share']['estimated_share_display']);
        $this->assertTrue($listing['expires_never']);
    }

    public function test_the_marketplace_is_closed_to_the_unverified_and_to_the_publisher_himself(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [, $viewer] = $this->verifiedSuchakActor();
        [$representation] = $this->publishableCandidate($publisher, $publisherUser);
        $service = $this->service();

        $service->publish($publisher, $publisherUser, $representation, $this->percentTerms(30));

        // A publisher reading his own listing is not market discovery, and counting him as a viewer
        // would poison the very read log D18 shows him.
        $this->assertCount(0, $service->browse($publisher)->items());

        // D18: verified only.
        $viewer->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();
        $this->expectException(InvalidArgumentException::class);
        $service->browse($viewer->fresh());
    }

    public function test_browse_drops_withdrawn_expired_and_consent_lapsed_listings(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [, $viewer] = $this->verifiedSuchakActor();
        $service = $this->service();

        [$withdrawnRepr] = $this->publishableCandidate($publisher, $publisherUser);
        [$expiredRepr] = $this->publishableCandidate($publisher, $publisherUser);
        [$lapsedRepr] = $this->publishableCandidate($publisher, $publisherUser);
        [$liveRepr] = $this->publishableCandidate($publisher, $publisherUser);

        $withdrawn = $service->publish($publisher, $publisherUser, $withdrawnRepr, $this->percentTerms(30));
        $service->withdraw($withdrawn, $publisher, $publisherUser);

        $service->publish($publisher, $publisherUser, $expiredRepr, $this->percentTerms(30) + [
            'expires_at' => now()->addDay()->toIso8601String(),
        ]);
        $service->publish($publisher, $publisherUser, $lapsedRepr, $this->percentTerms(30));
        $live = $service->publish($publisher, $publisherUser, $liveRepr, $this->percentTerms(30));

        $this->travel(2)->days();
        $lapsedRepr->forceFill(['consent_status' => SuchakProfileRepresentation::CONSENT_REVOKED])->save();

        $ids = array_map(
            static fn (array $listing): int => $listing['challenge_id'],
            $service->browse($viewer)->items(),
        );

        $this->assertSame([(int) $live->id], $ids);
    }

    public function test_opening_one_listing_is_logged_to_the_originating_suchak_but_browsing_is_not(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$viewerUser, $viewer] = $this->verifiedSuchakActor();
        [$representation] = $this->publishableCandidate($publisher, $publisherUser);
        $service = $this->service();

        $challenge = $service->publish($publisher, $publisherUser, $representation, $this->percentTerms(30));

        // Twelve rows per scroll would bury the signal D18 exists to give. A browse is not an open.
        $service->browse($viewer);
        $this->assertDatabaseMissing('suchak_activity_logs', [
            'action_type' => SuchakActivityLog::ACTION_MARKETPLACE_LISTING_OPENED,
        ]);

        $service->openListing($challenge, $viewer, $viewerUser);

        /** @var SuchakActivityLog $log */
        $log = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_MARKETPLACE_LISTING_OPENED)
            ->sole();

        // Filed under the ORIGINATING Suchak, because the log is shown to HIM. A row filed under the
        // viewer's account would never reach the person D18 wrote it for.
        $this->assertSame((int) $publisher->id, (int) $log->suchak_account_id);
        $this->assertSame((int) $viewerUser->id, (int) $log->actor_user_id);
        $this->assertSame((int) $viewer->id, (int) ($log->metadata_json['viewer_suchak_account_id'] ?? 0));

        // The first READ this log has ever recorded — its own action, not a write borrowed to stand in.
        $this->assertStringContainsString('opened', SuchakActivityLog::ACTION_MARKETPLACE_LISTING_OPENED);
    }

    // ── Every capability has a door ───────────────────────────────────────────────────────────

    public function test_publish_withdraw_browse_and_open_are_all_routable(): void
    {
        foreach ([
            ['POST', 'api/v1/suchak/marketplace/challenges'],
            ['POST', 'api/v1/suchak/marketplace/challenges/{challenge}/withdraw'],
            ['GET', 'api/v1/suchak/marketplace/challenges'],
            // Declared BEFORE {challenge} or the numeric binding would swallow it.
            ['GET', 'api/v1/suchak/marketplace/challenges/mine'],
            ['GET', 'api/v1/suchak/marketplace/challenges/{challenge}'],
        ] as [$method, $uri]) {
            $this->assertNotNull(
                collect(Route::getRoutes()->getRoutes())->first(
                    fn ($route): bool => $route->uri() === $uri && in_array($method, $route->methods(), true),
                ),
                'No route reaches '.$method.' '.$uri.'; a service method no route calls is the same defect as a column no writer writes.'
            );
        }
    }

    public function test_the_publish_route_publishes_and_the_withdraw_route_withdraws(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        [$representation, $agreement] = $this->publishableCandidate($account, $user);

        Sanctum::actingAs($user);

        $published = $this->postJson('/api/v1/suchak/marketplace/challenges', [
            'representation_id' => $representation->id,
            'declared_share_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
            'declared_share_percent' => 30,
            'expires_at' => now()->addWeek()->toIso8601String(),
        ]);

        $published->assertStatus(201)
            ->assertJsonPath('data.status', SuchakMarketplaceChallenge::STATUS_OPEN)
            ->assertJsonPath('data.declared_share.display', '30%')
            ->assertJsonPath('data.expires_never', false);

        $challengeId = $published->json('data.challenge_id');

        $this->assertDatabaseHas('suchak_collaboration_stage_events', [
            'customer_agreement_id' => $agreement->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_PUBLISHED_TO_MARKETPLACE,
        ]);

        $this->getJson('/api/v1/suchak/marketplace/challenges/mine')
            ->assertOk()
            ->assertJsonPath('data.0.challenge_id', $challengeId);

        $this->postJson('/api/v1/suchak/marketplace/challenges/'.$challengeId.'/withdraw', [
            'withdrawn_reason' => 'Family paused the search.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', SuchakMarketplaceChallenge::STATUS_WITHDRAWN);
    }

    public function test_the_browse_route_hides_another_suchaks_challenge_from_an_unverified_caller(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$viewerUser, $viewer] = $this->verifiedSuchakActor();
        [$representation] = $this->publishableCandidate($publisher, $publisherUser);

        $challenge = $this->service()->publish($publisher, $publisherUser, $representation, $this->percentTerms(30));

        Sanctum::actingAs($viewerUser);
        $this->getJson('/api/v1/suchak/marketplace/challenges')
            ->assertOk()
            ->assertJsonPath('data.0.challenge_id', (int) $challenge->id);

        $this->getJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id)
            ->assertOk()
            ->assertJsonPath('data.candidate.display_name', 'Sunita G.');

        $viewer->forceFill(['verification_status' => SuchakAccount::VERIFICATION_PENDING])->save();

        $this->getJson('/api/v1/suchak/marketplace/challenges')->assertStatus(422);
        $this->getJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id)->assertStatus(422);
    }

    public function test_withdrawing_someone_elses_challenge_is_a_404_not_a_403(): void
    {
        [$publisherUser, $publisher] = $this->verifiedSuchakActor();
        [$otherUser] = $this->verifiedSuchakActor();
        [$representation] = $this->publishableCandidate($publisher, $publisherUser);

        $challenge = $this->service()->publish($publisher, $publisherUser, $representation, $this->percentTerms(30));

        // A Suchak has no business learning that another Suchak's challenge exists from the shape of
        // a refusal. Browse is where other people's challenges are seen.
        Sanctum::actingAs($otherUser);
        $this->postJson('/api/v1/suchak/marketplace/challenges/'.$challenge->id.'/withdraw')
            ->assertStatus(404);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function service(): SuchakMarketplaceChallengeService
    {
        return $this->app->make(SuchakMarketplaceChallengeService::class);
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

    private function assertPublishRefused(
        SuchakMarketplaceChallengeService $service,
        SuchakAccount $account,
        User $user,
        SuchakProfileRepresentation $representation,
    ): void {
        try {
            $service->publish($account, $user, $representation, $this->percentTerms(30));
            $this->fail('Publishing was allowed when it should have been refused.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
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
            // SuchakAccountFactory does not set this, and canOperate() requires it.
            'registration_completed_at' => now(),
        ]);

        return [$user, $account];
    }

    /**
     * One candidate this Suchak may publish: active representation, valid consent, a customer
     * context, and a customer agreement the customer has ACCEPTED whose package carries a fixed
     * ₹1,00,000 success fee (blueprint 7.1's worked example).
     *
     * @return array{0: SuchakProfileRepresentation, 1: SuchakCustomerAgreement}
     */
    private function publishableCandidate(SuchakAccount $account, User $user): array
    {
        $state = $this->address('Maharashtra', 'state', 1, null);
        $district = $this->address('Pune', 'district', 2, $state);
        $taluka = $this->address('Shirur', 'taluka', 3, $district);
        $village = $this->address('Ranjangaon', 'village', 4, $taluka, 'rural');

        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Sunita Gaikwad',
            'location_id' => $village,
        ]);

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
            'package_name' => 'Challenge fixture '.$representation->id,
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
            // The success fee's one owner (section 5.2). The declared share is a slice of THIS.
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
            'agreement_snapshot_hash' => hash('sha256', 'challenge-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Accepted terms revision 1',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);

        return [$representation->fresh(), $agreement];
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return (bool) $definition['nullable'];
            }
        }

        $this->fail($table.'.'.$column.' does not exist.');
    }

    private function address(string $name, string $hierarchy, int $level, ?int $parent, ?string $tag = null): int
    {
        return DB::table('addresses')->insertGetId(array_filter([
            'name' => $name,
            'slug' => strtolower($name).'-'.$hierarchy.'-'.uniqid(),
            'hierarchy' => $hierarchy,
            'level' => $level,
            'parent_id' => $parent,
            'tag' => $tag,
            'created_at' => now(),
            'updated_at' => now(),
        ], static fn ($v): bool => $v !== null));
    }
}
