<?php

namespace Tests\Feature\Suchak;

use App\Http\Controllers\Api\Suchak\SuchakCustomerHistoryApiController;
use App\Http\Controllers\Api\Suchak\SuchakReputationApiController;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakClaimSilenceService;
use App\Modules\Suchak\Services\SuchakCrossSuchakObligationService;
use App\Modules\Suchak\Services\SuchakReputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * READ 1 of blueprint §11 phase 5 — the Suchak's behavioural record.
 *
 * Three rules are pinned here because breaking any one of them makes the read worse than not
 * shipping it:
 *
 *  D13                  a Suchak with no history is NEW, never `0%`. A card that describes a
 *                       newcomer as a zero makes him unemployable on the day he joins.
 *  SMALL DENOMINATORS   one dispute out of one meeting is not a 100% dispute rate. Counts ship from
 *                       event one; proportions wait for five.
 *  IT IS A DISCLOSURE   §9 shows this card to every Suchak, so nothing in it may name a candidate,
 *                       a family, a village or a customer.
 */
class SuchakReputationReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureRoutes();
    }

    // ── D13: a newcomer is NEW, never 0% ─────────────────────────────────────────────────────

    public function test_a_suchak_with_no_history_is_new_and_no_proportion_is_zero(): void
    {
        [$user] = $this->verifiedSuchakActor();

        Sanctum::actingAs($user);
        $data = $this->getJson('/api/v1/suchak/reputation')->assertOk()->json('data');

        $this->assertTrue($data['is_new'], 'D13: no history means NEW, not bad.');
        $this->assertSame(0, $data['recorded_event_count']);

        // EVERY proportion on the card, walked rather than sampled — a single one defaulting to 0
        // would be the exact defamation D13 forbids, and it would be on the newest Suchak's card.
        $rates = $this->ratesIn($data);
        $this->assertNotSame([], $rates, 'The card must actually contain proportions to check.');

        foreach ($rates as $path => $rate) {
            $this->assertNull($rate['percent'], $path.' must be null, never 0, with no events behind it.');
            $this->assertFalse($rate['is_publishable'], $path);
            $this->assertSame(SuchakReputationService::SUPPRESSED_NO_EVENTS, $rate['suppressed_reason'], $path);
            $this->assertSame(0, $rate['denominator'], $path);
        }

        // A7's own ratio already models this and is bound, not recomputed.
        $this->assertTrue($data['declared_share']['is_new']);
        $this->assertNull($data['declared_share']['realized_ratio_percent']);
    }

    public function test_a_newcomers_record_is_answered_rather_than_missing(): void
    {
        [, $subject] = $this->verifiedSuchakActor();
        [$reader] = $this->verifiedSuchakActor();

        Sanctum::actingAs($reader);

        // 404 would make "I have no record of him" indistinguishable from "he does not exist", and
        // a helper cannot tell a new Suchak from a deleted one on a status code.
        $this->getJson('/api/v1/suchak/reputation/'.$subject->id)
            ->assertOk()
            ->assertJsonPath('data.is_new', true)
            ->assertJsonPath('data.suchak_account_id', (int) $subject->id);
    }

    // ── small denominators lie ───────────────────────────────────────────────────────────────

    public function test_one_dispute_out_of_one_claim_is_not_published_as_a_hundred_percent(): void
    {
        $world = $this->world();
        $this->meeting($world, 1, [
            'visit_status' => SuchakVisitConfirmation::STATUS_DISPUTED,
            'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED,
            'suchak_completed_at' => now()->subDays(10),
            'dispute_id' => $this->dispute($world),
        ]);

        Sanctum::actingAs($world['user']);
        $meetings = $this->getJson('/api/v1/suchak/reputation')->assertOk()
            ->json('data.meetings_arranged');

        // The COUNTS ship from event one — a reader can see "1 dispute out of 1 claim" and weigh it
        // himself, which is a true sentence.
        $this->assertSame(1, $meetings['total']);
        $this->assertSame(1, $meetings['claims_made']);
        $this->assertSame(1, $meetings['disputed']);

        // The RATE does not, because "100% disputed" is not.
        $this->assertNull($meetings['disputed_rate']['percent']);
        $this->assertFalse($meetings['disputed_rate']['is_publishable']);
        $this->assertSame(
            SuchakReputationService::SUPPRESSED_TOO_FEW_EVENTS,
            $meetings['disputed_rate']['suppressed_reason'],
        );
        $this->assertSame(1, $meetings['disputed_rate']['numerator']);
        $this->assertSame(1, $meetings['disputed_rate']['denominator']);
        $this->assertSame(SuchakReputationService::MIN_RATE_DENOMINATOR, $meetings['disputed_rate']['threshold']);

        // And nowhere on the card does the string "100" appear as a percentage for this Suchak.
        $this->assertStringNotContainsString('"percent":"100"', json_encode($meetings));
    }

    public function test_a_proportion_appears_only_once_the_threshold_is_reached(): void
    {
        $world = $this->world();

        // Four claims, all confirmed. One short of the threshold on purpose.
        for ($sequence = 1; $sequence <= 4; $sequence++) {
            $this->meeting($world, $sequence, $this->confirmedMeeting());
        }

        Sanctum::actingAs($world['user']);
        $fourClaims = $this->getJson('/api/v1/suchak/reputation')->assertOk()
            ->json('data.meetings_arranged.confirmed_rate');

        $this->assertNull($fourClaims['percent'], 'Four events is still too few to publish a rate.');
        $this->assertSame(SuchakReputationService::SUPPRESSED_TOO_FEW_EVENTS, $fourClaims['suppressed_reason']);

        // The fifth claim crosses it, and this one is REFUSED by the family — so the published
        // figure is 80%, not a flattering 100%.
        $this->meeting($world, 5, [
            'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED,
            'suchak_completed_at' => now()->subDays(9),
            'user_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_DISPUTED,
            'user_confirmed_at' => now()->subDays(8),
        ]);

        $fiveClaims = $this->getJson('/api/v1/suchak/reputation')->assertOk()
            ->json('data.meetings_arranged.confirmed_rate');

        $this->assertTrue($fiveClaims['is_publishable']);
        $this->assertNull($fiveClaims['suppressed_reason']);
        $this->assertSame(4, $fiveClaims['numerator']);
        $this->assertSame(5, $fiveClaims['denominator']);
        // Latin digits, no trailing ".0" — the workspace rule, satisfied by construction because
        // nothing on this path is locale-aware.
        $this->assertSame('80', $fiveClaims['percent']);
    }

    public function test_a_meeting_not_yet_claimed_never_drags_the_confirmation_rate_down(): void
    {
        $world = $this->world();

        // Five claims, all confirmed…
        for ($sequence = 1; $sequence <= 5; $sequence++) {
            $this->meeting($world, $sequence, $this->confirmedMeeting());
        }

        // …plus three meetings scheduled for next week, which nobody has claimed and the family
        // therefore cannot possibly have confirmed. Counting those in the denominator would punish
        // a Suchak for having work in front of him.
        for ($sequence = 6; $sequence <= 8; $sequence++) {
            $this->meeting($world, $sequence, ['scheduled_for' => now()->addDays(4)]);
        }

        Sanctum::actingAs($world['user']);
        $meetings = $this->getJson('/api/v1/suchak/reputation')->assertOk()->json('data.meetings_arranged');

        $this->assertSame(8, $meetings['total']);
        $this->assertSame(3, $meetings['scheduled_open']);
        $this->assertSame(5, $meetings['claims_made']);
        $this->assertSame('100', $meetings['confirmed_rate']['percent']);
        $this->assertSame(5, $meetings['confirmed_rate']['denominator']);
    }

    // ── bound, never recomputed ──────────────────────────────────────────────────────────────

    public function test_the_declared_share_block_is_the_existing_a7_ratio_verbatim(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();

        Sanctum::actingAs($user);
        $card = $this->getJson('/api/v1/suchak/reputation')->assertOk()->json('data.declared_share');

        // Not "looks similar" — identical. A second computation of A7's ratio would be a second
        // answer to the one number §9a A7 exists to publish.
        $this->assertSame(
            $this->app->make(SuchakCrossSuchakObligationService::class)->declarerRatio((int) $account->id),
            $card,
        );
    }

    public function test_the_unanswered_claim_counter_is_the_existing_seven_two_counter_verbatim(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();

        Sanctum::actingAs($user);
        $card = $this->getJson('/api/v1/suchak/reputation')->assertOk()->json('data.unanswered_claims');

        $this->assertSame(
            $this->app->make(SuchakClaimSilenceService::class)->unansweredClaimSummary($account),
            $card,
        );
        // §7.2 clause 2's shape, not a new one.
        $this->assertArrayHasKey('claims', $card);
        $this->assertArrayHasKey('oldest_days', $card);
        $this->assertArrayHasKey('blocked', $card);
    }

    // ── it is a disclosure, so it names nobody ───────────────────────────────────────────────

    public function test_no_candidate_identity_reaches_a_reputation_payload(): void
    {
        $world = $this->world(candidateName: 'Sunita Gaikwad');
        $this->meeting($world, 1, [
            'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED,
            'suchak_completed_at' => now()->subDays(6),
            'schedule_note' => 'Sunita Gaikwad यांच्या घरी, Lakhandur.',
        ]);

        // Read by ANOTHER verified Suchak — the audience §9 actually opens this card to.
        [$reader] = $this->verifiedSuchakActor();
        Sanctum::actingAs($reader);

        $response = $this->getJson('/api/v1/suchak/reputation/'.$world['account']->id)->assertOk();
        $body = $response->getContent();

        // 1. No person, no place. "3 marriages in Lakhandur" identifies people; so does a name.
        $this->assertStringNotContainsString('Sunita', $body);
        $this->assertStringNotContainsString('Gaikwad', $body);
        $this->assertStringNotContainsString('Lakhandur', $body);

        // 2. No HANDLE to a person either — an id is an identity one request later.
        foreach ([
            'matrimony_profile_id',
            'representation_id',
            'customer_context_id',
            'customer_agreement_id',
            'collaboration_request_id',
            'challenge_id',
            'pipeline_id',
            'visit_confirmation_id',
            'candidate',
            'married_on',
            'schedule_note',
            'event_note',
            'completion_note',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, $forbidden.' is a handle to a family.');
        }

        // 3. The whole surface, pinned. A key added later without a look at this list is a key that
        //    reached another Suchak's screen without anyone deciding it should.
        $this->assertSame([
            'suchak_account_id',
            'suchak_name',
            'is_verified',
            'is_new',
            'recorded_event_count',
            'rate_threshold',
            'declared_share',
            'unanswered_claims',
            'meetings_arranged',
            'meetings_as_helper',
            'terminal_claims',
            'engagements',
            'marriages',
            'challenges',
        ], array_keys($response->json('data')));

        // 4. The only names on the card are the SUCHAK's own — the three publisher keys the
        //    marketplace listing already shows this same audience.
        $this->assertSame($world['account']->suchak_name, $response->json('data.suchak_name'));
    }

    public function test_marriages_are_a_count_and_carry_no_dimension_at_all(): void
    {
        $world = $this->world();

        // Two engagements he sat on, one of them carrying a recorded marriage rung.
        $collaboration = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $world['account']->id,
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
        SuchakCollaborationStageEvent::query()->create([
            'collaboration_request_id' => $collaboration->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            'claimed_by_actor_type' => 'suchak',
            'claimed_by_suchak_account_id' => $world['account']->id,
            'claimed_by_user_id' => $world['user']->id,
            'claimed_at' => now()->subDays(30),
        ]);

        Sanctum::actingAs($world['user']);
        $data = $this->getJson('/api/v1/suchak/reputation')->assertOk()->json('data');

        $this->assertSame(1, $data['engagements']['total']);
        $this->assertSame(1, $data['engagements']['accepted']);
        // No agreement revision is linked, so the ROLE is unrecorded and is published as such
        // rather than guessed from the column default.
        $this->assertSame(1, $data['engagements']['role_unrecorded']);
        $this->assertSame(0, $data['engagements']['as_customer_owner']);

        // A claim the family has not answered, and its age — §7.2 clause 2's shape.
        $this->assertSame(1, $data['terminal_claims']['claimed']);
        $this->assertSame(0, $data['terminal_claims']['confirmed_by_customer']);
        $this->assertSame(1, $data['terminal_claims']['awaiting_customer']);
        $this->assertSame(30, $data['terminal_claims']['oldest_awaiting_days']);
        $this->assertNull($data['terminal_claims']['confirmed_rate']['percent']);

        // Every confirmable rung is present even at zero, so a screen never has to guess whether a
        // missing row means "none" or "not rendered".
        $this->assertSame(
            SuchakCollaborationStageEvent::CONFIRMABLE_STAGES,
            array_column($data['terminal_claims']['by_stage'], 'stage_key'),
        );
        $this->assertSame('लग्न ठरल्यावर', $data['terminal_claims']['by_stage'][0]['stage_label']);

        $this->assertFalse($data['is_new'], 'An engagement is history; he is no longer new.');
    }

    // ── the doors ────────────────────────────────────────────────────────────────────────────

    public function test_an_unverified_suchak_may_not_read_another_suchaks_record(): void
    {
        [, $subject] = $this->verifiedSuchakActor();
        $unverifiedUser = User::factory()->create();
        SuchakAccount::factory()->create([
            'user_id' => $unverifiedUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_PENDING,
        ]);

        Sanctum::actingAs($unverifiedUser);

        // D18 / A10 — marketplace participation is tied to the badge, and one person running two
        // accounts must not be able to read the market from the unverified one.
        $this->getJson('/api/v1/suchak/reputation/'.$subject->id)->assertStatus(403);

        // His OWN card stays readable, because a record you are judged by and cannot read is a
        // rumour, not a record.
        $this->getJson('/api/v1/suchak/reputation')->assertOk()->assertJsonPath('data.is_new', true);
    }

    public function test_the_reputation_read_needs_a_suchak_account(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/suchak/reputation')->assertStatus(403);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    /**
     * Every `rate()` block on the card, keyed by its path, found by SHAPE rather than by a
     * hand-written list — so a proportion added later is checked by the D13 test automatically.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function ratesIn(array $data, string $prefix = ''): array
    {
        $found = [];

        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (array_key_exists('percent', $value)
                && array_key_exists('is_publishable', $value)
                && array_key_exists('suppressed_reason', $value)) {
                $found[$path] = $value;

                continue;
            }

            $found += $this->ratesIn($value, $path);
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmedMeeting(): array
    {
        return [
            'suchak_completion_status' => SuchakVisitConfirmation::COMPLETION_SUCHAK_MARKED,
            'suchak_completed_at' => now()->subDays(9),
            'user_confirmation_status' => SuchakVisitConfirmation::CONFIRMATION_CONFIRMED,
            'user_confirmed_at' => now()->subDays(8),
            'visit_status' => SuchakVisitConfirmation::STATUS_CONFIRMED,
        ];
    }

    /**
     * One Suchak, one pipeline, and everything a meeting row needs to stand on.
     *
     * The meeting rows are written DIRECTLY rather than through
     * `SuchakVisitConfirmationService`: this suite tests the READER, whose contract is over the
     * engine's columns, and driving eight meetings through the full state machine would test the
     * writer a second time at eight times the cost.
     *
     * @return array<string, mixed>
     */
    private function world(string $candidateName = 'Reputation fixture candidate'): array
    {
        [$user, $account] = $this->verifiedSuchakActor();

        $candidate = MatrimonyProfile::factory()->create(['full_name' => $candidateName]);
        $other = MatrimonyProfile::factory()->create(['full_name' => 'Reputation fixture counterparty']);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $candidate->id,
        ]);

        /** @var SuchakProfileRequest $request */
        $request = SuchakProfileRequest::factory()->create([
            'requesting_matrimony_profile_id' => $other->id,
            'target_matrimony_profile_id' => $candidate->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
        ]);

        /** @var SuchakPipeline $pipeline */
        $pipeline = SuchakPipeline::factory()->create([
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $candidate->id,
            'requesting_matrimony_profile_id' => $other->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
        ]);

        return [
            'user' => $user,
            'account' => $account,
            'candidate' => $candidate,
            'other' => $other,
            'representation' => $representation,
            'request' => $request,
            'pipeline' => $pipeline,
        ];
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  array<string, mixed>  $overrides
     */
    private function meeting(array $world, int $sequence, array $overrides = []): SuchakVisitConfirmation
    {
        /** @var SuchakVisitConfirmation $visit */
        $visit = SuchakVisitConfirmation::query()->create(array_merge([
            'pipeline_id' => $world['pipeline']->id,
            'suchak_account_id' => $world['account']->id,
            'request_id' => $world['request']->id,
            'representation_id' => $world['representation']->id,
            'target_matrimony_profile_id' => $world['candidate']->id,
            'requesting_matrimony_profile_id' => $world['other']->id,
            'visit_status' => SuchakVisitConfirmation::STATUS_SCHEDULED,
            'confirmation_policy_mode' => SuchakVisitConfirmation::POLICY_USER_ONLY,
            'meeting_sequence' => $sequence,
            'meeting_mode' => SuchakVisitConfirmation::MODE_OFFLINE,
            'fee_amount' => '3000.00',
            'fee_currency' => 'INR',
            'scheduled_by_user_id' => $world['user']->id,
            'scheduled_at' => now()->subDays(12),
            'scheduled_for' => now()->subDays(11),
        ], $overrides));

        return $visit;
    }

    /**
     * A dispute row a meeting can point at. The dispute ENGINE is not under test here — only that
     * `dispute_id` is the one column "disputed" is counted from.
     */
    private function dispute(array $world): int
    {
        return (int) \App\Models\SuchakDispute::factory()->create([
            'suchak_account_id' => $world['account']->id,
        ])->id;
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
     * The three phase-5 routes.
     *
     * Registered here ONLY when `routes/api/suchak.php` does not already carry them — that file was
     * being edited concurrently when this slice was written, so the exact lines were handed over
     * rather than added. The moment they land, this method finds them and does nothing, and these
     * tests exercise the real registration.
     */
    private function ensureRoutes(): void
    {
        $existing = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();

        if (in_array('api/v1/suchak/reputation', $existing, true)) {
            return;
        }

        // `SubstituteBindings` comes free inside the `api` group that wraps `routes/api.php`;
        // a group declared from a test does not get it, and without it `{suchakAccount}` arrives as
        // an empty model and every count is computed for account 0.
        Route::middleware([\Illuminate\Routing\Middleware\SubstituteBindings::class, 'auth:sanctum', 'suchak.account'])
            ->prefix('api/v1/suchak')
            ->group(function (): void {
                Route::get('/reputation', [SuchakReputationApiController::class, 'own']);
                Route::get('/reputation/{suchakAccount}', [SuchakReputationApiController::class, 'show'])
                    ->whereNumber('suchakAccount');
                Route::get('/customer-contexts/{customerContext}/history', [SuchakCustomerHistoryApiController::class, 'show'])
                    ->whereNumber('customerContext');
            });

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
}
