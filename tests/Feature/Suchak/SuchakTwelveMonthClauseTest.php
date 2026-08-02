<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakCustomerPortalLink;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakTwelveMonthClauseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE 12-MONTH ANTI-CIRCUMVENTION CLAUSE (blueprint D11, D21, 9a A5/A6/A13).
 *
 * The rule under test, in one sentence:
 *
 *   When the FAMILY records `viewed` on a candidate proposed through their Suchak, a marriage to
 *   that candidate within 12 months of that view still owes that Suchak the success fee — however
 *   the later contact happened, and even if the engagement, the agreement or the whole relationship
 *   ended in the meantime — unless the family declared at view time that they already knew that
 *   family.
 *
 * Before this, none of it existed: no constant, no column, no reader. The `viewed` rung had become
 * writable, and the binding it creates was a timestamp in a table nobody could ask a question of.
 *
 * The tests are grouped by the clause they defend, because each is a different way of losing the
 * rule silently — a status filter added "for tidiness" deletes D21 and nothing else fails.
 */
class SuchakTwelveMonthClauseTest extends TestCase
{
    use RefreshDatabase;

    /** Mid-month and mid-year on purpose: the A5 cap is a CALENDAR-month ordinal. */
    private const NOW = '2026-08-05 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── D11: the clause binds at `viewed`, and only at `viewed` ──────────────────────────────

    public function test_the_clause_binds_from_the_viewed_rung_for_twelve_months(): void
    {
        $world = $this->world();
        $this->recordView($world, $world['engagements'][0]);

        $verdict = $this->clause()->verdictFor($world['context'], $this->proposedId($world['engagements'][0], $world));

        $this->assertTrue($verdict['binds']);
        $this->assertNull($verdict['release_reason']);
        $this->assertSame(
            Carbon::parse(self::NOW)->addMonths(12)->toIso8601String(),
            $verdict['binds_until'],
        );
        $this->assertSame(Carbon::parse(self::NOW)->toIso8601String(), $verdict['viewed_at']);

        // WHO it is owed to survives every later question, so it is answered here.
        $this->assertSame((int) $world['ownerAccount']->id, $verdict['owed_to_suchak_account_id']);
    }

    /**
     * D11's second half — "never from merely Suggested". A helper naming a candidate binds nothing;
     * only the family opening it does. This is the whole of 9a A5's premise, and it is why the
     * anchor is a CUSTOMER rung.
     */
    public function test_a_suggested_but_unviewed_profile_binds_nothing(): void
    {
        $world = $this->world();
        $engagement = $world['engagements'][0];

        $this->app->make(SuchakCollaborationService::class)->claimStage(
            $engagement,
            $world['helperAccount'],
            $world['helperUser'],
            SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
        );

        $verdict = $this->clause()->verdictFor($world['context'], $this->proposedId($engagement, $world));

        $this->assertFalse($verdict['binds']);
        $this->assertSame(SuchakTwelveMonthClauseService::RELEASE_NEVER_VIEWED, $verdict['release_reason']);
        $this->assertNull($verdict['binds_until']);
        // "no" and "I have no record" must not arrive as the same silence.
        $this->assertSame((int) $world['context']->id, $verdict['customer_context_id']);
    }

    public function test_the_anchor_rung_is_declared_once_and_is_the_viewed_rung(): void
    {
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_VIEWED,
            SuchakCollaborationStageEvent::CLAUSE_ANCHOR_STAGE,
        );
        $this->assertSame(
            SuchakCollaborationStageEvent::CLAUSE_ANCHOR_STAGE,
            SuchakTwelveMonthClauseService::ANCHOR_STAGE,
        );
        $this->assertSame(12, SuchakTwelveMonthClauseService::BINDING_MONTHS);
    }

    public function test_the_clause_lapses_the_day_after_twelve_months(): void
    {
        $world = $this->world();
        $engagement = $world['engagements'][0];
        $this->recordView($world, $engagement);
        $candidateId = $this->proposedId($engagement, $world);

        Carbon::setTestNow(Carbon::parse(self::NOW)->addMonths(12));
        $this->assertTrue($this->clause()->isShareOwed($world['context'], $candidateId));

        Carbon::setTestNow(Carbon::parse(self::NOW)->addMonths(12)->addDay());
        $lapsed = $this->clause()->verdictFor($world['context'], $candidateId);

        $this->assertFalse($lapsed['binds']);
        $this->assertSame(SuchakTwelveMonthClauseService::RELEASE_LAPSED, $lapsed['release_reason']);
        $this->assertSame(0, $lapsed['days_remaining']);
        // The record does NOT disappear when it stops binding — a dispute a year later reads it.
        $this->assertNotNull($lapsed['viewed_at']);
    }

    // ── D21: leaving does not void the clause ────────────────────────────────────────────────

    /**
     * The single most losable rule in this file. D21: "Ending the engagement stops future fees, but
     * a marriage within 12 months to a profile the customer viewed still owes the success fee."
     *
     * The clause reader enforces it by an ABSENCE — no `status` filter and no `terms_status` filter
     * — so nothing else in the suite would fail if someone added one.
     */
    public function test_the_binding_survives_a_rejected_engagement_and_a_superseded_agreement(): void
    {
        $world = $this->world();
        $engagement = $world['engagements'][0];
        $this->recordView($world, $engagement);
        $candidateId = $this->proposedId($engagement, $world);

        $engagement->forceFill(['status' => SuchakCollaborationRequest::STATUS_REJECTED])->save();
        SuchakCustomerAgreement::query()
            ->whereKey($world['agreement']->id)
            ->update(['terms_status' => SuchakCustomerAgreement::TERMS_SUPERSEDED, 'superseded_at' => now()]);

        $this->assertFalse($world['agreement']->fresh()->isTermsSatisfied());

        $verdict = $this->clause()->verdictFor($world['context'], $candidateId);
        $this->assertTrue($verdict['binds'], 'D21: a rejected engagement must not void the clause.');

        $engagement->forceFill(['status' => SuchakCollaborationRequest::STATUS_CANCELLED])->save();
        $this->assertTrue(
            $this->clause()->isShareOwed($world['context'], $candidateId),
            'D21: a cancelled engagement must not void the clause either.',
        );

        // And the customer door's own listing DOES hide a closed engagement (OPEN_STATUSES) — which
        // is exactly why the clause may not be read through that listing.
        $this->assertSame(
            [],
            $this->app->make(SuchakCollaborationService::class)
                ->customerEngagementsForPortalLink($world['portalLink'])->all(),
        );
    }

    // ── 9a A6: the family who already knew them ──────────────────────────────────────────────

    public function test_the_one_tap_prior_acquaintance_declaration_releases_the_clause(): void
    {
        $world = $this->world();
        $engagement = $world['engagements'][0];

        $this->post($this->recordUrl($world['token'], $engagement), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_VIEWED,
            'prior_acquaintance' => '1',
        ])->assertRedirect();

        /** @var SuchakCollaborationStageEvent $event */
        $event = SuchakCollaborationStageEvent::query()
            ->where('stage_key', SuchakCollaborationStageEvent::STAGE_VIEWED)
            ->firstOrFail();
        $this->assertTrue((bool) $event->prior_acquaintance_declared);

        $verdict = $this->clause()->verdictFor($world['context'], $this->proposedId($engagement, $world));
        $this->assertFalse($verdict['binds']);
        $this->assertSame(
            SuchakTwelveMonthClauseService::RELEASE_PRIOR_ACQUAINTANCE,
            $verdict['release_reason'],
        );

        // The act that removed a success-fee obligation is in the trail, not only in the row.
        $log = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_COLLABORATION_STAGE_CUSTOMER_RECORDED)
            ->latest('id')
            ->firstOrFail();
        $this->assertTrue((bool) ($log->metadata_json['prior_acquaintance_declared'] ?? false));
    }

    /** A release on a rung that creates no binding is meaningless, and a meaningless value is a bug. */
    public function test_the_release_is_refused_on_every_rung_but_the_anchor(): void
    {
        $world = $this->world();
        $engagement = $world['engagements'][0];

        $this->post($this->recordUrl($world['token'], $engagement), [
            'stage_key' => SuchakCollaborationStageEvent::STAGE_INTERESTED,
            'prior_acquaintance' => '1',
        ])->assertSessionHasErrors('customer_stage');

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());

        // The model repeats the refusal on `saving`, because it is an invariant of the row and not
        // of one write path.
        $this->expectException(InvalidArgumentException::class);
        SuchakCollaborationStageEvent::query()->create([
            'collaboration_request_id' => $engagement->id,
            'stage_key' => SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
            'claimed_by_actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'claimed_by_suchak_account_id' => $world['helperAccount']->id,
            'claimed_by_user_id' => $world['helperUser']->id,
            'prior_acquaintance_declared' => true,
            'claimed_at' => now(),
        ]);
    }

    /**
     * The release is the family's alone. A Suchak who could set it would be deleting his own
     * obligation; one who could clear it would be manufacturing one. Neither is reachable, because
     * `viewed` is a CUSTOMER rung and every Suchak path refuses it outright.
     */
    public function test_no_suchak_can_write_the_anchor_rung_or_its_release(): void
    {
        $world = $this->world();

        foreach ([
            [$world['ownerAccount'], $world['ownerUser']],
            [$world['helperAccount'], $world['helperUser']],
        ] as [$account, $user]) {
            try {
                $this->app->make(SuchakCollaborationService::class)->claimStage(
                    $world['engagements'][0],
                    $account,
                    $user,
                    SuchakCollaborationStageEvent::STAGE_VIEWED,
                );
                $this->fail('A Suchak must never be able to claim the clause anchor rung.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('ग्राहक स्वतः नोंदवतो', $exception->getMessage());
            }
        }

        $this->assertSame(0, SuchakCollaborationStageEvent::query()->count());
    }

    // ── 9a A5: the monthly cap on binding views ──────────────────────────────────────────────

    public function test_views_past_the_monthly_cap_are_recorded_but_do_not_bind(): void
    {
        $cap = SuchakTwelveMonthClauseService::BINDING_VIEWS_PER_CALENDAR_MONTH;
        $world = $this->world($cap + 2);

        foreach ($world['engagements'] as $engagement) {
            $this->recordView($world, $engagement);
        }

        $bindings = $this->clause()->bindingsForCustomer($world['context']);
        $this->assertCount($cap + 2, $bindings, 'Every view is still RECORDED; only the binding is capped.');

        $binding = array_values(array_filter($bindings, static fn (array $row): bool => $row['binds'] === true));
        $this->assertCount($cap, $binding);

        $released = array_values(array_filter(
            $bindings,
            static fn (array $row): bool => $row['release_reason'] === SuchakTwelveMonthClauseService::RELEASE_MONTHLY_CAP,
        ));
        $this->assertCount(2, $released);
        $this->assertSame($cap + 1, $released[0]['binding_view_ordinal_in_month']);
    }

    /**
     * A6 must not spend an A5 slot. If an honest "we already know them" consumed a cap slot, the
     * family's own honesty would release a stranger's obligation later in the same month.
     */
    public function test_a_prior_acquaintance_view_does_not_consume_a_cap_slot(): void
    {
        $cap = SuchakTwelveMonthClauseService::BINDING_VIEWS_PER_CALENDAR_MONTH;
        $world = $this->world($cap + 1);

        // The FIRST view is the acquainted one; the remaining `cap` must all still bind.
        $this->recordView($world, $world['engagements'][0], priorAcquaintance: true);
        foreach (array_slice($world['engagements'], 1) as $engagement) {
            $this->recordView($world, $engagement);
        }

        $bindings = $this->clause()->bindingsForCustomer($world['context']);
        $binding = array_values(array_filter($bindings, static fn (array $row): bool => $row['binds'] === true));

        $this->assertCount($cap, $binding);
        $this->assertNull($bindings[0]['binding_view_ordinal_in_month']);
        $this->assertSame(1, $bindings[1]['binding_view_ordinal_in_month']);
    }

    public function test_the_cap_is_per_calendar_month_and_resets(): void
    {
        $cap = SuchakTwelveMonthClauseService::BINDING_VIEWS_PER_CALENDAR_MONTH;
        $world = $this->world($cap + 1);

        foreach (array_slice($world['engagements'], 0, $cap) as $engagement) {
            $this->recordView($world, $engagement);
        }

        Carbon::setTestNow(Carbon::parse(self::NOW)->addMonthNoOverflow());
        $this->recordView($world, $world['engagements'][$cap]);

        $bindings = $this->clause()->bindingsForCustomer($world['context']);
        $this->assertCount($cap + 1, array_filter($bindings, static fn (array $row): bool => $row['binds'] === true));
        $this->assertSame(1, $bindings[$cap]['binding_view_ordinal_in_month']);
    }

    // ── The question has a door ──────────────────────────────────────────────────────────────

    public function test_the_suchak_can_ask_both_questions_over_http(): void
    {
        $this->assertTrue($this->routeExists('api/v1/suchak/customer-contexts/{customerContext}/twelve-month-clause'));
        $this->assertTrue($this->routeExists('api/v1/suchak/customer-contexts/{customerContext}/twelve-month-clause/{candidate}'));

        $world = $this->world(2);
        $this->recordView($world, $world['engagements'][0]);
        $this->recordView($world, $world['engagements'][1], priorAcquaintance: true);

        Sanctum::actingAs($world['ownerUser']);

        $list = $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/twelve-month-clause')
            ->assertOk()
            ->json('data');

        $this->assertSame(12, $list['terms']['binding_months']);
        $this->assertTrue($list['terms']['survives_engagement_end']);
        $this->assertCount(2, $list['bindings']);
        $this->assertTrue($list['bindings'][0]['binds']);
        $this->assertFalse($list['bindings'][1]['binds']);

        $candidateId = $this->proposedId($world['engagements'][0], $world);
        $verdict = $this->getJson(
            '/api/v1/suchak/customer-contexts/'.$world['context']->id.'/twelve-month-clause/'.$candidateId
        )->assertOk()->json('data');

        $this->assertTrue($verdict['binds']);
        $this->assertSame($candidateId, $verdict['candidate_matrimony_profile_id']);
        // Money as text: Latin digits with Indian grouping, via MoneyFormat and nothing else.
        $this->assertSame('₹1,00,000', $verdict['success_fee']);

        // A candidate the family never viewed answers 200 with `binds: false` — NOT 404. A caller
        // that only checks the status code must never read "I have no record" as "no".
        $never = $this->getJson(
            '/api/v1/suchak/customer-contexts/'.$world['context']->id.'/twelve-month-clause/'
            .MatrimonyProfile::factory()->create()->id
        )->assertOk()->json('data');

        $this->assertFalse($never['binds']);
        $this->assertSame(SuchakTwelveMonthClauseService::RELEASE_NEVER_VIEWED, $never['release_reason']);
    }

    public function test_another_suchak_is_told_the_customer_does_not_exist(): void
    {
        $world = $this->world();
        [$outsiderUser] = $this->verifiedSuchakActor();

        Sanctum::actingAs($outsiderUser);
        $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/twelve-month-clause')
            ->assertNotFound();

        $this->getJson(
            '/api/v1/suchak/customer-contexts/'.$world['context']->id.'/twelve-month-clause/1'
        )->assertNotFound();
    }

    public function test_a_user_without_a_suchak_account_is_refused(): void
    {
        $world = $this->world();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/suchak/customer-contexts/'.$world['context']->id.'/twelve-month-clause')
            ->assertForbidden();
    }

    /**
     * The party the clause BINDS reads it too. The family is the only one who can be ambushed by
     * this a year later, so the date and the one-tap release are on their own page.
     */
    public function test_the_family_sees_the_binding_and_its_release_tap_on_their_own_page(): void
    {
        $world = $this->world();

        // This test pins MARATHI wording, so it has to ask for Marathi. It did
        // not have to when the stages page was Devanagari literals — that page
        // now reads through __(), and web routes resolve the locale from
        // `?locale=` or the session (SetLocaleFromQuery), never from
        // Accept-Language. Without this the page answers in English, correctly.
        $this->withSession(['locale' => 'mr']);

        $this->get(route('suchak.customer-portal.stages.index', ['token' => $world['token']]))
            ->assertOk()
            ->assertSee('आम्ही या कुटुंबाला आधीपासून ओळखतो.', false)
            ->assertSee('name="prior_acquaintance"', false);

        $this->recordView($world, $world['engagements'][0]);

        $this->get(route('suchak.customer-portal.stages.index', ['token' => $world['token']]))
            ->assertOk()
            ->assertSee(Carbon::parse(self::NOW)->addMonths(12)->format('d M Y'), false)
            ->assertSee('विवाह-फी लागू राहते', false);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    private function clause(): SuchakTwelveMonthClauseService
    {
        return $this->app->make(SuchakTwelveMonthClauseService::class);
    }

    private function routeExists(string $uri): bool
    {
        return collect(Route::getRoutes())->contains(fn ($route): bool => $route->uri() === $uri);
    }

    private function recordUrl(string $token, SuchakCollaborationRequest $collaboration): string
    {
        return route('suchak.customer-portal.stages.record', [
            'token' => $token,
            'collaboration' => $collaboration->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $world
     */
    private function recordView(array $world, SuchakCollaborationRequest $engagement, bool $priorAcquaintance = false): void
    {
        $this->app->make(SuchakCollaborationService::class)->recordCustomerStage(
            $engagement,
            $world['portalLink'],
            SuchakCollaborationStageEvent::STAGE_VIEWED,
            null,
            '203.0.113.7',
            'phpunit',
            $priorAcquaintance,
        );
    }

    /**
     * The candidate the clause is about: the OTHER family's profile on this engagement.
     *
     * @param  array<string, mixed>  $world
     */
    private function proposedId(SuchakCollaborationRequest $engagement, array $world): int
    {
        $own = (int) $world['context']->candidate_matrimony_profile_id;

        return (int) $engagement->requesting_matrimony_profile_id === $own
            ? (int) $engagement->target_matrimony_profile_id
            : (int) $engagement->requesting_matrimony_profile_id;
    }

    /**
     * ONE customer (one context, one agreement revision, one portal link) with `$engagementCount`
     * marketplace proposals against them — the helper answered, so he is the REQUESTING side and the
     * customer-owning Suchak is the target (blueprint 5.2, responder-is-requester).
     *
     * @return array<string, mixed>
     */
    private function world(int $engagementCount = 1): array
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();

        /** @var MatrimonyProfile $ownCandidate */
        $ownCandidate = MatrimonyProfile::factory()->create(['lifecycle_state' => 'draft', 'is_suspended' => false]);

        $context = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $ownerAccount->id,
            'candidate_matrimony_profile_id' => $ownCandidate->id,
            'service_context' => SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $ownerUser->id,
            'opened_at' => now(),
        ]);

        $agreement = $this->customerAgreement($ownerAccount, $ownerUser, $context);
        $collaborationService = $this->app->make(SuchakCollaborationService::class);

        $engagements = [];
        for ($i = 0; $i < $engagementCount; $i++) {
            /** @var SuchakCollaborationRequest $engagement */
            $engagement = SuchakCollaborationRequest::factory()->create([
                'requesting_suchak_account_id' => $helperAccount->id,
                'target_suchak_account_id' => $ownerAccount->id,
                'target_matrimony_profile_id' => $ownCandidate->id,
                'status' => SuchakCollaborationRequest::STATUS_PENDING,
                'responded_at' => null,
            ]);

            $collaborationService->linkCustomerAgreement($engagement, $ownerAccount, $ownerUser, $agreement);
            $engagements[] = $engagement->fresh(['commissionAgreement']);
        }

        [$plainToken, $portalLink] = $this->portalLink($ownerAccount, $ownerUser, $context);

        return [
            'ownerUser' => $ownerUser,
            'ownerAccount' => $ownerAccount,
            'helperUser' => $helperUser,
            'helperAccount' => $helperAccount,
            'context' => $context,
            'agreement' => $agreement,
            'engagements' => $engagements,
            'portalLink' => $portalLink,
            'token' => $plainToken,
        ];
    }

    /**
     * @return array{0: string, 1: SuchakCustomerPortalLink}
     */
    private function portalLink(
        SuchakAccount $account,
        User $issuer,
        SuchakCustomerContext $customerContext,
    ): array {
        $plainToken = Str::random(64);

        /** @var SuchakCustomerPortalLink $link */
        $link = SuchakCustomerPortalLink::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'issued_by_user_id' => $issuer->id,
            'token_hash' => hash('sha256', $plainToken),
            'portal_status' => SuchakCustomerPortalLink::STATUS_ACTIVE,
            'recipient_role' => SuchakCustomerPortalLink::RECIPIENT_FAMILY,
            'recipient_label' => 'Customer family',
            'claimed_name' => 'सुनीता पवार',
            'claimed_relationship_to_candidate' => 'आई',
            'expires_at' => now()->addDays(365),
        ]);

        return [$plainToken, $link];
    }

    private function customerAgreement(
        SuchakAccount $account,
        User $user,
        SuchakCustomerContext $customerContext,
    ): SuchakCustomerAgreement {
        /** @var SuchakServicePackage $package */
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Twelve month clause fixture',
            'price_amount' => '25000',
            'currency' => 'INR',
            'per_meeting_fee_amount' => '3000',
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            // A lakh on purpose: MoneyFormat's Indian grouping and number_format() agree below one,
            // so a smaller figure would pass even if the formatter were wrong.
            'post_marriage_fee_amount' => '100000',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
        ]);

        return SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'clause-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Clause fixture terms',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);
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
}
