<?php

namespace Tests\Feature\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCollaborationStageEvent;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Matchmaker marketplace blueprint 6.1 (the engagement object) and 6a (the stage ladder).
 */
class SuchakMarketplaceEngagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_engagement_is_the_existing_pair_extended_not_a_new_table(): void
    {
        $this->assertFalse(Schema::hasTable('suchak_engagements'));

        $this->assertTrue(Schema::hasColumn('suchak_collaboration_requests', 'customer_owner_side'));
        $this->assertTrue(Schema::hasColumn('suchak_collaboration_requests', 'marketplace_stage'));
        $this->assertTrue(Schema::hasColumn('suchak_collaboration_requests', 'status'));
        $this->assertTrue(Schema::hasColumn('suchak_commission_agreements', 'customer_agreement_id'));

        $this->assertTrue(Schema::hasTable('suchak_collaboration_stage_events'));
        foreach ([
            'collaboration_request_id',
            'stage_key',
            'claimed_by_actor_type',
            'claimed_by_suchak_account_id',
            'claimed_by_user_id',
            'claimed_at',
            'confirmed_by_actor_type',
            'confirmed_by_user_id',
            'confirmed_at',
            'event_note',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_collaboration_stage_events', $column), $column);
        }
    }

    public function test_stage_ladder_is_ordered_and_only_the_three_terminal_stages_need_confirmation(): void
    {
        $this->assertSame([
            'registration',
            'agreement_proposed',
            'agreement_accepted',
            'published_to_marketplace',
            'profile_suggested',
            'viewed',
            'interested',
            'meeting_scheduled',
            'meeting_completed',
            'meeting_confirmed',
            'marriage_settled',
            'engagement',
            'marriage',
            'share_settled',
        ], SuchakCollaborationStageEvent::STAGE_LADDER);

        $this->assertSame([
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        ], SuchakCollaborationStageEvent::CONFIRMABLE_STAGES);

        // Settled must precede engagement, which must precede marriage — the installment schedule
        // "50% when the marriage is settled, 50% on the engagement day" depends on that order.
        $this->assertTrue(SuchakCollaborationStageEvent::stageIndex(SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED)
            < SuchakCollaborationStageEvent::stageIndex(SuchakCollaborationStageEvent::STAGE_ENGAGEMENT));
        $this->assertTrue(SuchakCollaborationStageEvent::stageIndex(SuchakCollaborationStageEvent::STAGE_ENGAGEMENT)
            < SuchakCollaborationStageEvent::stageIndex(SuchakCollaborationStageEvent::STAGE_MARRIAGE));

        $this->assertFalse(SuchakCollaborationStageEvent::isValidStage('lagna_zale'));
        $this->assertFalse(SuchakCollaborationStageEvent::requiresConfirmation(SuchakCollaborationStageEvent::STAGE_VIEWED));
        $this->assertTrue(SuchakCollaborationStageEvent::isStageAfter(
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
            SuchakCollaborationStageEvent::STAGE_VIEWED,
        ));
        $this->assertFalse(SuchakCollaborationStageEvent::isStageAfter(
            SuchakCollaborationStageEvent::STAGE_VIEWED,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE,
        ));
    }

    public function test_linking_a_customer_agreement_names_the_owning_side_and_freezes_the_revision(): void
    {
        [$ownerUser, $ownerAccount, $helperUser, $helperAccount, $collaboration] = $this->acceptedEngagement();

        // Legacy default: the target side collects, so the target side owns the customer.
        $this->assertSame(SuchakCollaborationRequest::SIDE_TARGET, $collaboration->fresh()->customer_owner_side);

        $agreement = $this->customerAgreement($ownerAccount, $ownerUser, revision: 1);
        $service = $this->service();

        $linked = $service->linkCustomerAgreement($collaboration, $ownerAccount, $ownerUser, $agreement);

        $this->assertSame(SuchakCollaborationRequest::SIDE_REQUESTING, $linked->customer_owner_side);
        $this->assertSame((int) $ownerAccount->id, $linked->customerOwnerSuchakAccountId());
        $this->assertSame((int) $helperAccount->id, $linked->helpingSuchakAccountId());
        $this->assertTrue($linked->isCustomerOwner((int) $ownerAccount->id));
        $this->assertTrue($linked->isHelpingSuchak((int) $helperAccount->id));

        /** @var SuchakCommissionAgreement $commission */
        $commission = SuchakCommissionAgreement::query()
            ->where('collaboration_request_id', $collaboration->id)
            ->firstOrFail();
        $this->assertSame((int) $agreement->id, (int) $commission->customer_agreement_id);
        $this->assertSame(1, (int) $commission->customerAgreement->agreement_revision);

        // A later revision is a different row; the engagement stays bound to the one in force.
        $laterRevision = $this->customerAgreement($ownerAccount, $ownerUser, revision: 2);
        $this->expectException(InvalidArgumentException::class);
        $service->linkCustomerAgreement($linked, $ownerAccount, $ownerUser, $laterRevision);
    }

    public function test_the_helping_suchak_cannot_bind_the_owner_customer_agreement(): void
    {
        [$ownerUser, $ownerAccount, $helperUser, $helperAccount, $collaboration] = $this->acceptedEngagement();
        $agreement = $this->customerAgreement($ownerAccount, $ownerUser, revision: 1);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->linkCustomerAgreement($collaboration, $helperAccount, $helperUser, $agreement);
    }

    public function test_either_suchak_may_claim_a_terminal_stage_and_the_customer_confirms_it(): void
    {
        [$ownerUser, $ownerAccount, $helperUser, $helperAccount, $collaboration] = $this->acceptedEngagement();
        $service = $this->service();

        // D26: either Suchak may raise the claim — here the helping side does.
        $claim = $service->claimStage(
            $collaboration,
            $helperAccount,
            $helperUser,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            'दोन्ही कुटुंबांनी होकार दिला.',
        );

        $this->assertSame((int) $helperAccount->id, (int) $claim->claimed_by_suchak_account_id);
        $this->assertSame((int) $helperUser->id, (int) $claim->claimed_by_user_id);
        $this->assertNotNull($claim->claimed_at);
        $this->assertNull($claim->confirmed_at);
        $this->assertFalse($claim->isSettled());

        // A claim alone does not move the engagement's ladder position.
        $this->assertNull($collaboration->fresh()->marketplace_stage);

        $customer = User::factory()->create();
        $confirmed = $service->confirmStage(
            $collaboration,
            $customer,
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
        );

        $this->assertSame((int) $customer->id, (int) $confirmed->confirmed_by_user_id);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertTrue($confirmed->isSettled());
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED,
            $collaboration->fresh()->marketplace_stage,
        );

        $this->expectException(InvalidArgumentException::class);
        $service->confirmStage($collaboration, $customer, SuchakCollaborationStageEvent::STAGE_MARRIAGE_SETTLED);
    }

    public function test_a_participating_suchak_cannot_confirm_their_own_stage_claim(): void
    {
        [$ownerUser, $ownerAccount, $helperUser, $helperAccount, $collaboration] = $this->acceptedEngagement();
        $service = $this->service();

        $service->claimStage(
            $collaboration,
            $ownerAccount,
            $ownerUser,
            SuchakCollaborationStageEvent::STAGE_ENGAGEMENT,
        );

        $this->expectException(InvalidArgumentException::class);
        $service->confirmStage($collaboration, $helperUser, SuchakCollaborationStageEvent::STAGE_ENGAGEMENT);
    }

    /**
     * `viewed` and `interested` used to stand in for "any non-terminal rung" here. They cannot any
     * more: section 6a gives both to the CUSTOMER, and no Suchak may write them
     * (SuchakCollaborationStageEvent::STAGE_CLAIMANTS). The property under test — settles on claim,
     * never rewinds — is unchanged; only the rungs are ones their claimant may actually record.
     */
    public function test_non_terminal_stage_settles_on_claim_and_the_ladder_never_rewinds(): void
    {
        [$ownerUser, $ownerAccount, $helperUser, $helperAccount, $collaboration] = $this->acceptedEngagement();
        $this->linkOwnerAgreement($collaboration, $ownerAccount, $ownerUser);
        $service = $this->service();

        $scheduled = $service->claimStage(
            $collaboration->fresh(),
            $helperAccount,
            $helperUser,
            SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED,
        );
        $this->assertTrue($scheduled->isSettled());
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED,
            $collaboration->fresh()->marketplace_stage,
        );

        $service->claimStage(
            $collaboration->fresh(),
            $helperAccount,
            $helperUser,
            SuchakCollaborationStageEvent::STAGE_MEETING_COMPLETED,
        );
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MEETING_COMPLETED,
            $collaboration->fresh()->marketplace_stage,
        );

        // An earlier stage recorded late must not pull the engagement backwards.
        $service->claimStage(
            $collaboration->fresh(),
            $helperAccount,
            $helperUser,
            SuchakCollaborationStageEvent::STAGE_PROFILE_SUGGESTED,
        );
        $this->assertSame(
            SuchakCollaborationStageEvent::STAGE_MEETING_COMPLETED,
            $collaboration->fresh()->marketplace_stage,
        );
    }

    public function test_free_text_stage_keys_and_repeat_claims_are_refused(): void
    {
        [$ownerUser, $ownerAccount, , , $collaboration] = $this->acceptedEngagement();
        $service = $this->service();

        try {
            $service->claimStage($collaboration, $ownerAccount, $ownerUser, 'sakharpuda_done');
            $this->fail('Free text stage keys must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unknown marketplace stage key', $exception->getMessage());
        }

        $service->claimStage($collaboration, $ownerAccount, $ownerUser, SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED);

        $this->expectException(InvalidArgumentException::class);
        $service->claimStage($collaboration, $ownerAccount, $ownerUser, SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED);
    }

    public function test_stage_events_cannot_be_deleted(): void
    {
        [$ownerUser, $ownerAccount, , , $collaboration] = $this->acceptedEngagement();

        $event = $this->service()->claimStage(
            $collaboration,
            $ownerAccount,
            $ownerUser,
            SuchakCollaborationStageEvent::STAGE_MEETING_SCHEDULED,
        );

        $this->expectException(RuntimeException::class);
        $event->delete();
    }

    private function service(): SuchakCollaborationService
    {
        return $this->app->make(SuchakCollaborationService::class);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: User, 3: SuchakAccount, 4: SuchakCollaborationRequest}
     */
    private function acceptedEngagement(): array
    {
        [$ownerUser, $ownerAccount] = $this->verifiedSuchakActor();
        [$helperUser, $helperAccount] = $this->verifiedSuchakActor();

        /** @var SuchakCollaborationRequest $collaboration */
        $collaboration = SuchakCollaborationRequest::factory()->create([
            'requesting_suchak_account_id' => $ownerAccount->id,
            'target_suchak_account_id' => $helperAccount->id,
            'status' => SuchakCollaborationRequest::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);

        return [$ownerUser, $ownerAccount, $helperUser, $helperAccount, $collaboration];
    }

    /**
     * Turns the two roles from a column DEFAULT into a recorded fact: `customer_owner_side` starts
     * at `target`, and a role-scoped rung (profile_suggested, meeting_completed, share_settled) is
     * refused until the owning Suchak has linked his own customer agreement revision.
     */
    private function linkOwnerAgreement(
        SuchakCollaborationRequest $collaboration,
        SuchakAccount $ownerAccount,
        User $ownerUser,
    ): SuchakCustomerAgreement {
        $agreement = $this->customerAgreement($ownerAccount, $ownerUser, revision: 1);
        $this->service()->linkCustomerAgreement($collaboration, $ownerAccount, $ownerUser, $agreement);

        return $agreement;
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

    private function customerAgreement(SuchakAccount $account, User $user, int $revision): SuchakCustomerAgreement
    {
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'package_name' => 'Marketplace engagement fixture '.$revision,
            'price_amount' => '25000',
            'currency' => 'INR',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $user->id,
            'published_at' => now(),
        ]);

        return SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'service_package_id' => $package->id,
            'agreement_revision' => $revision,
            'terms_status' => 'accepted',
            'terms_policy_mode' => 'strict',
            'agreement_snapshot_hash' => hash('sha256', 'marketplace-engagement-'.$package->id),
            'package_name' => $package->package_name,
            'price_amount' => '25000',
            'currency' => 'INR',
            'agreement_title' => 'Accepted terms revision '.$revision,
            'created_by_user_id' => $user->id,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
        ]);
    }
}
