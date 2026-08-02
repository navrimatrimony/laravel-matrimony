<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerPayment;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\SuchakSuccessFeeTranche;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TWO READ GAPS THAT MADE WRITE DOORS UNREACHABLE, and nothing else.
 *
 * GAP 1 — A SUCHAK COULD NOT ATTACH A RECEIPT TO AN INSTALMENT.
 * `POST …/success-fee-tranches/{tranche}/settlement` takes a `suchak_customer_payments.id`, and
 * no Suchak route listed that id space: `/payments` returns `suchak_ledger_entries`,
 * `/payment-requests` returns `suchak_payment_requests` ids (a different space — sending one
 * would bind the wrong receipt to a family's money) and a `customer_payment_id` reached the app
 * only in the one-shot body of `mark-paid`. `GET /suchak/customer-payments` is that list.
 *
 * GAP 2 — THE LADDER WAS AMNESIAC AND THE AGREEMENT COULD NOT BE LINKED.
 * `advanceMarketplaceStage()` deliberately does not move `marketplace_stage` for a CONFIRMABLE
 * rung, and there was no stage read anywhere, so a claimed-but-unconfirmed `marriage_settled`
 * existed only in the app's session state and vanished on restart. And `linkCustomerAgreement`
 * needs a `customer_agreement_id` whose only lister needs a representation id — the
 * collaborations payload carried neither. Both now ride on the row that is already fetched on
 * every load.
 */
class SuchakEngagementReadDoorsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These tests assert MARATHI wording, so they ask for Marathi.
     *
     * They did not have to before: the sentences they pin were hardcoded
     * Marathi literals, which read the same whatever the caller wanted — the
     * defect, not the contract. Now the wording follows the request, so the
     * language under test is stated rather than inherited from whatever the
     * suite's default locale happens to be (Symfony's test client sends
     * `Accept-Language: en-us`, so the default is English).
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('mr');
        $this->withHeader('Accept-Language', 'mr');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  GAP 2 — the engagement's real state is readable
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_claimed_but_unconfirmed_rung_survives_a_restart_because_the_row_carries_it(): void
    {
        $fixture = $this->linkedEngagement();

        // `marriage_settled` is CONFIRMABLE (D26): either Suchak claims, the family confirms.
        // advanceMarketplaceStage() therefore leaves `marketplace_stage` where it was — which is
        // exactly why the app could not read this claim back.
        $this->collaborationService()->claimStage(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
        );

        $this->assertNull(
            $fixture['collaboration']->fresh()->marketplace_stage,
            'A confirmable rung must not move the ladder — that is the whole reason it must be readable elsewhere.',
        );

        $row = $this->collaborationRow($fixture['ownerUser'], (int) $fixture['collaboration']->id);

        $this->assertNull($row['marketplace_stage']);

        $rungs = collect($row['stage_events'])->keyBy('stage_key');
        $this->assertTrue($rungs->has(SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED));

        $claimed = $rungs[SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED];
        $this->assertSame('लग्न ठरल्यावर', $claimed['stage_label']);
        $this->assertSame(SuchakCollaborationStageEvent::CLAIMANT_EITHER_SUCHAK, $claimed['claimant']);
        $this->assertTrue($claimed['requires_confirmation']);
        $this->assertNotNull($claimed['claimed_at']);
        // The family's word is still missing, so nothing releases on it.
        $this->assertNull($claimed['confirmed_at']);
        $this->assertFalse($claimed['is_settled']);
    }

    public function test_the_row_names_the_agreement_and_the_representation_link_customer_agreement_needs(): void
    {
        $fixture = $this->linkedEngagement();

        $row = $this->collaborationRow($fixture['ownerUser'], (int) $fixture['collaboration']->id);

        $this->assertSame((int) $fixture['agreement']->id, $row['customer_agreement_id']);
        $this->assertSame(
            $fixture['collaboration']->fresh()->customer_owner_side,
            $row['customer_owner_side'],
        );
        $this->assertTrue($row['is_customer_owner']);

        // The representation `GET /customers/{representation}/payment-request-options` accepts —
        // the caller's OWN side of this engagement, which is also the side an agreement offered to
        // linkCustomerAgreement() must be about.
        $expected = (int) $fixture['ownerRepresentation']->id;
        $this->assertSame($expected, $row['my_representation_id']);
        $this->assertSame($expected, $row['customer_owner_representation_id']);
    }

    public function test_an_unlinked_engagement_publishes_no_role_at_all_rather_than_the_column_default(): void
    {
        $fixture = $this->engagement();

        $row = $this->collaborationRow($fixture['ownerUser'], (int) $fixture['collaboration']->id);

        // `customer_owner_side` DEFAULTS to `target` in the database. Publishing that default as
        // though it were a finding is how an app tells the wrong Suchak he owns the customer.
        $this->assertSame(
            SuchakCollaborationRequest::SIDE_TARGET,
            $fixture['collaboration']->fresh()->customer_owner_side,
        );
        $this->assertNull($row['customer_agreement_id']);
        $this->assertNull($row['customer_owner_side']);
        $this->assertNull($row['is_customer_owner']);

        // The caller's own representation is published regardless — it is how he REACHES the link
        // door, and it is his own row either way.
        $this->assertSame((int) $fixture['ownerRepresentation']->id, $row['my_representation_id']);
        $this->assertSame([], $row['stage_events']);
    }

    public function test_the_engagement_payload_leaks_no_masked_counterparty_identity(): void
    {
        $fixture = $this->linkedEngagement();

        $this->collaborationService()->claimStage(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            'Sunita Gaikwad, Lakhandur — settled at the family home.',
        );

        // The HELPER reads the same engagement. D19a: nothing about the other Suchak's customer
        // may cross, and `SuchakCandidateMaskingService` is the one presenter that would have had
        // to do it — this payload carries no candidate for it to present at all.
        $response = $this->actingRead($fixture['helperUser']);
        $body = $response->getContent();
        $row = $this->rowFrom($response, (int) $fixture['collaboration']->id);

        $this->assertFalse($row['is_customer_owner'], 'The helper is not the customer owner.');
        $this->assertNull(
            $row['customer_owner_representation_id'],
            'A representation id is a handle to the other Suchak\'s customer.',
        );
        $this->assertSame(
            (int) $fixture['helperRepresentation']->id,
            $row['my_representation_id'],
            'The helper is handed his OWN representation and only his own.',
        );

        // No candidate fact of any kind — not a name, not a profile id, not a representation id
        // belonging to the other side.
        $this->assertStringNotContainsString($fixture['ownerCandidate']->full_name, $body);
        $this->assertStringNotContainsString('matrimony_profile_id', $body);
        $this->assertStringNotContainsString('candidate', $body);

        // The rung's free-text note is a Suchak's own words and can name the family. It is not a
        // ladder fact and is not published.
        $this->assertStringNotContainsString('Lakhandur', $body);
        $this->assertStringNotContainsString('event_note', $body);
        $this->assertStringNotContainsString('claimed_by', $body);

        $this->assertSame([
            'stage_event_id',
            'stage_key',
            'stage_label',
            'owner',
            'collaboration_id',
            'customer_agreement_id',
            'claimed_at',
            'confirmed_at',
            'claimant',
            'requires_confirmation',
            'is_settled',
        ], array_keys($row['stage_events'][0]));
    }

    public function test_a_stranger_suchak_sees_no_row_for_this_engagement_at_all(): void
    {
        $fixture = $this->linkedEngagement();
        [$strangerUser] = $this->verifiedSuchakActor();

        $response = $this->actingRead($strangerUser);

        $this->assertSame([], $response->json('data.collaborations'));
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    //  GAP 1 — the receipts a Suchak may bind
    // ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_receipt_list_names_the_amount_the_date_the_purpose_and_the_reference(): void
    {
        $fixture = $this->linkedEngagement();
        $payment = $this->paidCustomerPayment($fixture, '1000000', reference: 'UPI-77120034');

        Sanctum::actingAs($fixture['ownerUser']);
        $response = $this->getJson('/api/v1/suchak/customer-payments')->assertOk();

        $rows = $response->json('data.customer_payments');
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame((int) $payment->id, $row['id']);
        $this->assertSame((int) $fixture['agreement']->id, $row['customer_agreement_id']);
        $this->assertSame('Success fee instalment', $row['request_title']);
        $this->assertSame('UPI-77120034', $row['payment_reference']);
        $this->assertSame(SuchakCustomerPayment::STATUS_PAID, $row['payment_status']);
        $this->assertTrue($row['is_paid']);
        $this->assertNotNull($row['payment_received_at']);
        $this->assertNotNull($row['recorded_at']);

        // The server owns the money string: MoneyFormat, Indian grouping, Latin digits — never
        // ₹1,000,000 and never Devanagari numerals.
        $this->assertSame('₹10,00,000', $row['amount_received_display']);
        $this->assertStringNotContainsString('०', $response->getContent());
        $this->assertStringNotContainsString('१', $response->getContent());

        // Nothing is bound to it yet.
        $this->assertFalse($row['is_bound_to_tranche']);
        $this->assertSame(0, $row['bound_tranche_count']);
        $this->assertSame([], $row['bound_tranches']);
    }

    public function test_another_suchaks_receipts_are_never_listed(): void
    {
        $mine = $this->linkedEngagement();
        $theirs = $this->linkedEngagement();

        $myPayment = $this->paidCustomerPayment($mine, '50000');
        $theirPayment = $this->paidCustomerPayment($theirs, '50000');

        Sanctum::actingAs($mine['ownerUser']);
        $ids = collect($this->getJson('/api/v1/suchak/customer-payments')->assertOk()
            ->json('data.customer_payments'))
            ->pluck('id')
            ->all();

        $this->assertSame([(int) $myPayment->id], $ids);
        $this->assertNotContains((int) $theirPayment->id, $ids);
    }

    public function test_the_list_filters_to_one_agreement_because_settlement_requires_the_same_one(): void
    {
        $fixture = $this->linkedEngagement();
        $second = $this->secondAgreementFor($fixture);

        $onThisAgreement = $this->paidCustomerPayment($fixture, '50000');
        $onTheOther = $this->paidCustomerPayment($fixture, '9000', agreement: $second);

        Sanctum::actingAs($fixture['ownerUser']);
        $response = $this->getJson(
            '/api/v1/suchak/customer-payments?customer_agreement_id='.$fixture['agreement']->id,
        )->assertOk();

        $this->assertSame(
            [(int) $onThisAgreement->id],
            collect($response->json('data.customer_payments'))->pluck('id')->all(),
        );
        $this->assertSame((int) $fixture['agreement']->id, $response->json('data.customer_agreement_id'));

        // Unfiltered, both of this Suchak's receipts are there.
        $this->assertCount(2, $this->getJson('/api/v1/suchak/customer-payments')->json('data.customer_payments'));
        $this->assertNotNull($onTheOther->id);
    }

    public function test_a_receipt_already_bound_to_an_instalment_says_so_and_names_the_instalment(): void
    {
        $fixture = $this->linkedEngagement();
        $payment = $this->paidCustomerPayment($fixture, '100000');
        $tranche = $this->releasedTranche($fixture, SuchakCollaborationStageEvent::STAGE_MARRIAGE);

        // Bind it the way the settle door does.
        $tranche->forceFill([
            'customer_payment_id' => $payment->id,
            'settled_at' => now(),
        ])->save();

        Sanctum::actingAs($fixture['ownerUser']);
        $row = collect($this->getJson('/api/v1/suchak/customer-payments')->assertOk()
            ->json('data.customer_payments'))
            ->firstWhere('id', (int) $payment->id);

        // Binding an already-bound receipt is exactly how a family gets credited twice, so a human
        // choosing one must be able to SEE that it is spent — and on WHAT.
        $this->assertTrue($row['is_bound_to_tranche']);
        $this->assertSame(1, $row['bound_tranche_count']);
        $this->assertSame((int) $tranche->id, $row['bound_tranches'][0]['tranche_id']);
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
            $row['bound_tranches'][0]['trigger_stage_key'],
        );
        $this->assertSame('विवाहानंतर', $row['bound_tranches'][0]['trigger_stage_label']);
        $this->assertNotNull($row['bound_tranches'][0]['settled_at']);
    }

    public function test_a_pending_receipt_is_listed_with_its_status_rather_than_hidden(): void
    {
        $fixture = $this->linkedEngagement();
        $this->paidCustomerPayment($fixture, '50000', status: SuchakCustomerPayment::STATUS_PENDING);

        Sanctum::actingAs($fixture['ownerUser']);
        $row = $this->getJson('/api/v1/suchak/customer-payments')->assertOk()
            ->json('data.customer_payments.0');

        // The settle service refuses it in its own Marathi sentence. Hiding the row here would
        // move that rule into the client, and a Suchak who cannot see the receipt he recorded has
        // no way to understand why the instalment will not close.
        $this->assertSame(SuchakCustomerPayment::STATUS_PENDING, $row['payment_status']);
        $this->assertFalse($row['is_paid']);
    }

    public function test_the_listed_id_is_the_one_the_settlement_route_accepts(): void
    {
        $fixture = $this->linkedEngagement();
        $payment = $this->paidCustomerPayment($fixture, '100000');
        $tranche = $this->releasedTranche($fixture, SuchakCollaborationStageEvent::STAGE_MARRIAGE);

        Sanctum::actingAs($fixture['ownerUser']);

        $listedId = $this->getJson(
            '/api/v1/suchak/customer-payments?customer_agreement_id='.$fixture['agreement']->id,
        )->assertOk()->json('data.customer_payments.0.id');

        $this->assertSame((int) $payment->id, $listedId);

        // THE POINT OF THE WHOLE READ: the id it publishes is the id the write door takes.
        $this->postJson(
            '/api/v1/suchak/collaborations/'.$fixture['collaboration']->id
            .'/success-fee-tranches/'.$tranche->id.'/settlement',
            ['customer_payment_id' => $listedId],
        )->assertOk()->assertJsonPath('data.tranches.0.is_settled', true);

        $this->assertSame((int) $payment->id, (int) $tranche->fresh()->customer_payment_id);
    }

    public function test_the_receipt_list_needs_a_suchak_account(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/suchak/customer-payments')->assertStatus(403);
    }

    // ────────────────────────────────────────────────────────────────────── fixtures ─────────

    /**
     * @return array<string, mixed>
     */
    private function collaborationRow(User $actor, int $collaborationId): array
    {
        return $this->rowFrom($this->actingRead($actor), $collaborationId);
    }

    private function actingRead(User $actor): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->getJson('/api/v1/suchak/collaborations')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFrom(\Illuminate\Testing\TestResponse $response, int $collaborationId): array
    {
        $row = collect($response->json('data.collaborations'))->firstWhere('id', $collaborationId);
        $this->assertIsArray($row, 'The engagement must appear on the caller\'s own list.');

        return $row;
    }

    /**
     * An ACCEPTED engagement between two verified Suchaks, with the customer-owning side's own
     * agreement linked through the real service — so `customer_owner_side` is a recorded fact and
     * not the column default.
     *
     * @return array<string, mixed>
     */
    private function linkedEngagement(): array
    {
        $fixture = $this->engagement();

        $linked = $this->collaborationService()->linkCustomerAgreement(
            $fixture['collaboration'],
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            $fixture['agreement'],
        );

        $this->assertSame((int) $fixture['ownerAccount']->id, $linked->customerOwnerSuchakAccountId());
        $fixture['collaboration'] = $linked;

        return $fixture;
    }

    /**
     * @return array<string, mixed>
     */
    private function engagement(): array
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();

        $ownerCandidate = MatrimonyProfile::factory()->create(['full_name' => 'Sunita Gaikwad']);
        $helperCandidate = MatrimonyProfile::factory()->create(['full_name' => 'Rohit Jadhav']);

        $ownerRepresentation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $ownerAccount->id,
            'matrimony_profile_id' => $ownerCandidate->id,
        ]);
        $helperRepresentation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $helperAccount->id,
            'matrimony_profile_id' => $helperCandidate->id,
        ]);

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $ownerAccount->id,
            'target_suchak_account_id' => $helperAccount->id,
            'requesting_matrimony_profile_id' => $ownerCandidate->id,
            'target_matrimony_profile_id' => $helperCandidate->id,
            'requesting_representation_id' => $ownerRepresentation->id,
            'target_representation_id' => $helperRepresentation->id,
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        [$context, $package, $agreement] = $this->customerAgreement($ownerAccount, $ownerUser, $ownerCandidate, $ownerRepresentation);

        return [
            'ownerUser' => $ownerUser,
            'ownerAccount' => $ownerAccount,
            'ownerCandidate' => $ownerCandidate,
            'ownerRepresentation' => $ownerRepresentation,
            'helperUser' => $helperUser,
            'helperAccount' => $helperAccount,
            'helperRepresentation' => $helperRepresentation,
            'collaboration' => $collaboration,
            'context' => $context,
            'package' => $package,
            'agreement' => $agreement,
        ];
    }

    /**
     * @return array{0: SuchakCustomerContext, 1: SuchakServicePackage, 2: SuchakCustomerAgreement}
     */
    private function customerAgreement(
        SuchakAccount $account,
        User $user,
        MatrimonyProfile $candidate,
        SuchakProfileRepresentation $representation,
        string $packageName = 'Read-doors fixture',
    ): array {
        /** @var SuchakCustomerContext $context */
        $context = SuchakCustomerContext::query()->firstOrCreate([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $candidate->id,
        ], [
            'representation_id' => $representation->id,
            'service_context' => SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
            'source_owner' => SuchakCustomerContext::SOURCE_OWNER_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $user->id,
            'opened_at' => now(),
        ]);

        /** @var SuchakServicePackage $package */
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'package_name' => $packageName,
            'price_amount' => '25000',
            'currency' => 'INR',
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            'post_marriage_fee_amount' => '100000',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
        ]);

        /** @var SuchakCustomerAgreement $agreement */
        $agreement = SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $context->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => hash('sha256', 'read-doors-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => $packageName.' — terms revision 1',
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);

        return [$context, $package, $agreement];
    }

    /**
     * A SECOND agreement chain for the same family — the case `customer_agreement_id` filtering
     * exists for.
     */
    private function secondAgreementFor(array $fixture): SuchakCustomerAgreement
    {
        [, , $agreement] = $this->customerAgreement(
            $fixture['ownerAccount'],
            $fixture['ownerUser'],
            $fixture['ownerCandidate'],
            $fixture['ownerRepresentation'],
            packageName: 'Read-doors fixture (premium)',
        );

        return $agreement;
    }

    /**
     * A released instalment on the linked agreement, written the way the release door writes it.
     */
    private function releasedTranche(array $fixture, string $stageKey): SuchakSuccessFeeTranche
    {
        /** @var SuchakSuccessFeeTranche $tranche */
        $tranche = SuchakSuccessFeeTranche::query()->create([
            'customer_agreement_id' => $fixture['agreement']->id,
            'sort_order' => 10,
            'trigger_stage_key' => $stageKey,
            'share_percent' => '100.00',
            'is_final_tranche' => true,
            'released_by_collaboration_request_id' => $fixture['collaboration']->id,
            'released_at' => now()->subDay(),
        ]);

        return $tranche;
    }

    private function paidCustomerPayment(
        array $fixture,
        string $amount,
        ?SuchakCustomerAgreement $agreement = null,
        string $status = SuchakCustomerPayment::STATUS_PAID,
        ?string $reference = null,
    ): SuchakCustomerPayment {
        $agreement ??= $fixture['agreement'];

        /** @var SuchakPaymentContext $paymentContext */
        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $fixture['ownerAccount']->id,
            'customer_context_id' => $agreement->customer_context_id,
            'matrimony_profile_id' => $fixture['ownerCandidate']->id,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $fixture['ownerUser']->id,
            'resolution_note' => 'Read-doors fixture.',
        ]);

        /** @var SuchakPaymentRequest $paymentRequest */
        $paymentRequest = SuchakPaymentRequest::query()->create([
            'suchak_account_id' => $fixture['ownerAccount']->id,
            'customer_context_id' => $agreement->customer_context_id,
            'service_package_id' => $agreement->service_package_id,
            'customer_agreement_id' => $agreement->id,
            'payment_context_id' => $paymentContext->id,
            'requested_by_user_id' => $fixture['ownerUser']->id,
            'request_token_hash' => hash('sha256', 'read-doors-'.uniqid('', true)),
            'payment_status' => SuchakPaymentRequest::STATUS_PAID,
            'request_title' => 'Success fee instalment',
            'amount_due' => $amount,
            'currency' => 'INR',
            'collector_disclosure' => 'सूचक स्वतः रक्कम स्वीकारतील.',
            'sent_at' => now()->subDays(3),
        ]);

        /** @var SuchakCustomerPayment $payment */
        $payment = SuchakCustomerPayment::query()->create([
            'suchak_account_id' => $fixture['ownerAccount']->id,
            'customer_context_id' => $agreement->customer_context_id,
            'payment_context_id' => $paymentContext->id,
            'payment_request_id' => $paymentRequest->id,
            'service_package_id' => $agreement->service_package_id,
            'customer_agreement_id' => $agreement->id,
            'recorded_by_user_id' => $fixture['ownerUser']->id,
            'collection_channel' => SuchakCustomerPayment::CHANNEL_SUCHAK_DIRECT,
            'payment_mode' => SuchakCustomerPayment::MODE_UPI,
            'payment_status' => $status,
            'amount_due' => $amount,
            'amount_received' => $status === SuchakCustomerPayment::STATUS_PAID ? $amount : '0',
            'balance_amount' => '0',
            'currency' => 'INR',
            'payment_received_at' => now()->subDays(2),
            'payment_reference' => $reference,
        ]);

        return $payment;
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

    private function collaborationService(): SuchakCollaborationService
    {
        return $this->app->make(SuchakCollaborationService::class);
    }
}
