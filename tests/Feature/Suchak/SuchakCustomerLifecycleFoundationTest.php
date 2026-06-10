<?php

namespace Tests\Feature\Suchak;

use App\Models\BiodataIntake;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataIntakeLink;
use App\Models\SuchakConsent;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakCustomerTimelineEvent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCrmLedgerService;
use App\Modules\Suchak\Services\SuchakCustomerLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SuchakCustomerLifecycleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suchak_customer_lifecycle_tables_exist_with_day_34_columns(): void
    {
        $this->assertTrue(Schema::hasTable('suchak_customer_contexts'));
        $this->assertTrue(Schema::hasTable('suchak_customer_timeline_events'));

        foreach ([
            'suchak_account_id',
            'candidate_matrimony_profile_id',
            'source_link_id',
            'representation_id',
            'payer_user_id',
            'payer_name',
            'payer_relationship_to_candidate',
            'consent_id',
            'consent_giver_user_id',
            'consent_giver_name',
            'consent_giver_relationship_to_candidate',
            'service_context',
            'source_owner',
            'source_type',
            'customer_lifecycle_status',
            'created_by_user_id',
            'classified_by_user_id',
            'classified_at',
            'opened_at',
            'closed_at',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_contexts', $column), $column);
        }

        foreach ([
            'customer_context_id',
            'suchak_account_id',
            'candidate_matrimony_profile_id',
            'event_type',
            'actor_type',
            'actor_user_id',
            'from_status',
            'to_status',
            'event_note',
            'occurred_at',
            'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('suchak_customer_timeline_events', $column), $column);
        }

        $this->assertTrue(Schema::hasColumn('suchak_payment_contexts', 'customer_context_id'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_contexts', 'profile_id'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_contexts', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('suchak_customer_timeline_events', 'deleted_at'));
    }

    public function test_suchak_can_create_pre_profile_customer_context_from_source_link(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $sourceLink = $this->sourceLink($user, $account);

        $context = app(SuchakCustomerLifecycleService::class)->createFromSourceLink(
            $account,
            $user,
            $sourceLink,
            [],
            '127.0.0.1',
            'Day-34 source test',
        );

        $this->assertSame($account->id, $context->suchak_account_id);
        $this->assertNull($context->candidate_matrimony_profile_id);
        $this->assertSame($sourceLink->id, $context->source_link_id);
        $this->assertNull($context->representation_id);
        $this->assertSame(SuchakCustomerContext::SOURCE_OWNER_SUCHAK, $context->source_owner);
        $this->assertSame(SuchakCustomerContext::SOURCE_TYPE_INTAKE_UPLOAD, $context->source_type);
        $this->assertSame(SuchakCustomerContext::STATUS_LEAD, $context->customer_lifecycle_status);

        $this->assertDatabaseHas('suchak_customer_timeline_events', [
            'customer_context_id' => $context->id,
            'event_type' => SuchakCustomerTimelineEvent::EVENT_CONTEXT_CREATED,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'actor_user_id' => $user->id,
            'from_status' => null,
            'to_status' => SuchakCustomerContext::STATUS_LEAD,
        ]);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $account->id,
            'actor_user_id' => $user->id,
            'action_type' => SuchakActivityLog::ACTION_CUSTOMER_CONTEXT_CREATED,
            'target_type' => 'suchak_customer_context',
            'target_id' => $context->id,
            'matrimony_profile_id' => null,
        ]);
    }

    public function test_customer_context_separates_candidate_payer_and_consent_giver_without_profile_mutation(): void
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $candidateUser = User::factory()->create();
        $payerUser = User::factory()->create();
        $profile = MatrimonyProfile::factory()->create([
            'user_id' => $candidateUser->id,
            'full_name' => 'Day 34 Candidate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $sourceLink = $this->sourceLink($user, $account, $profile, SuchakBiodataIntakeLink::STATUS_LINKED_TO_EXISTING_PROFILE);
        $representation = $this->representation($account, $profile, $sourceLink);
        $consent = SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'consent_given_by_name' => 'Candidate Father',
            'relationship_to_candidate' => 'father',
        ]);
        $beforeUpdatedAt = $profile->updated_at?->toDateTimeString();

        $context = app(SuchakCustomerLifecycleService::class)->createForRepresentation(
            $account,
            $user,
            $representation,
            [
                'payer_user_id' => $payerUser->id,
                'payer_name' => 'Candidate Father',
                'payer_relationship_to_candidate' => 'father',
                'consent_id' => $consent->id,
                'service_context' => SuchakCustomerContext::SERVICE_PROFILE_REPRESENTATION,
            ],
            '127.0.0.1',
            'Day-34 representation test',
        );

        $this->assertSame($profile->id, $context->candidate_matrimony_profile_id);
        $this->assertSame($representation->id, $context->representation_id);
        $this->assertSame($sourceLink->id, $context->source_link_id);
        $this->assertNotSame($candidateUser->id, $context->payer_user_id);
        $this->assertSame($payerUser->id, $context->payer_user_id);
        $this->assertSame('Candidate Father', $context->payer_name);
        $this->assertSame($consent->id, $context->consent_id);
        $this->assertSame('Candidate Father', $context->consent_giver_name);
        $this->assertSame('father', $context->consent_giver_relationship_to_candidate);
        $this->assertSame($beforeUpdatedAt, $profile->fresh()->updated_at?->toDateTimeString());

        $this->assertDatabaseHas('suchak_customer_timeline_events', [
            'customer_context_id' => $context->id,
            'event_type' => SuchakCustomerTimelineEvent::EVENT_PAYER_LINKED,
        ]);
        $this->assertDatabaseHas('suchak_customer_timeline_events', [
            'customer_context_id' => $context->id,
            'event_type' => SuchakCustomerTimelineEvent::EVENT_CONSENT_GIVER_LINKED,
        ]);

        $metadata = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_CUSTOMER_CONTEXT_CREATED)
            ->where('target_id', $context->id)
            ->firstOrFail()
            ->metadata_json;
        $encodedMetadata = json_encode($metadata, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Candidate Father', $encodedMetadata);
        $this->assertStringNotContainsString('Day 34 Candidate', $encodedMetadata);
    }

    public function test_suchak_can_classify_customer_source_and_lifecycle_with_timeline(): void
    {
        [$user, $account, $profile, $representation] = $this->representationFixture();
        $context = app(SuchakCustomerLifecycleService::class)->createForRepresentation($account, $user, $representation);

        $classified = app(SuchakCustomerLifecycleService::class)->classifySource(
            $context,
            $user,
            [
                'source_owner' => SuchakCustomerContext::SOURCE_OWNER_PLATFORM,
                'source_type' => SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST,
                'customer_lifecycle_status' => SuchakCustomerContext::STATUS_CONSENT_PENDING,
            ],
        );

        $this->assertSame(SuchakCustomerContext::SOURCE_OWNER_PLATFORM, $classified->source_owner);
        $this->assertSame(SuchakCustomerContext::SOURCE_TYPE_PLATFORM_REQUEST, $classified->source_type);
        $this->assertSame(SuchakCustomerContext::STATUS_CONSENT_PENDING, $classified->customer_lifecycle_status);
        $this->assertSame($user->id, $classified->classified_by_user_id);
        $this->assertNotNull($classified->classified_at);

        $this->assertDatabaseHas('suchak_customer_timeline_events', [
            'customer_context_id' => $context->id,
            'event_type' => SuchakCustomerTimelineEvent::EVENT_SOURCE_CLASSIFIED,
            'from_status' => SuchakCustomerContext::STATUS_CANDIDATE_IDENTIFIED,
            'to_status' => SuchakCustomerContext::STATUS_CONSENT_PENDING,
        ]);
        $this->assertDatabaseHas('suchak_customer_timeline_events', [
            'customer_context_id' => $context->id,
            'event_type' => SuchakCustomerTimelineEvent::EVENT_LIFECYCLE_STATUS_CHANGED,
        ]);
    }

    public function test_customer_context_can_be_linked_to_payment_context_and_prevents_source_spoofing(): void
    {
        [$user, $account, $profile, $representation] = $this->representationFixture();
        $context = app(SuchakCustomerLifecycleService::class)->createForRepresentation($account, $user, $representation);

        $entry = app(SuchakCrmLedgerService::class)->createLedgerEntry($account, $user, $profile, [
            'customer_context_id' => $context->id,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'entry_type' => SuchakLedgerEntry::TYPE_REGISTRATION_FEE_EXPECTED,
            'amount' => '750',
        ]);

        $this->assertSame($context->id, $entry->paymentContext->customer_context_id);

        try {
            app(SuchakCrmLedgerService::class)->createLedgerEntry($account, $user, $profile, [
                'customer_context_id' => $context->id,
                'source_owner' => SuchakPaymentContext::SOURCE_PLATFORM,
                'payment_collector' => SuchakPaymentContext::COLLECTOR_PLATFORM,
                'entry_type' => SuchakLedgerEntry::TYPE_PAYMENT_REMINDER,
                'amount' => '800',
            ]);

            $this->fail('Payment source owner should match the customer context source owner.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak payment source owner must match the customer context source owner.', $exception->getMessage());
        }
    }

    public function test_customer_context_owner_and_immutability_guards(): void
    {
        [$user, $account, , $representation] = $this->representationFixture();
        [$otherUser, $otherAccount] = $this->verifiedSuchakActor();
        $service = app(SuchakCustomerLifecycleService::class);

        try {
            $service->createForRepresentation($otherAccount, $otherUser, $representation);

            $this->fail('Other Suchak should not create customer context for this representation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Suchak customer representation must belong to the Suchak account.', $exception->getMessage());
        }

        $context = $service->createForRepresentation($account, $user, $representation);
        $event = $context->timelineEvents()->firstOrFail();

        try {
            $context->delete();

            $this->fail('Suchak customer context delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer contexts cannot be deleted.', $exception->getMessage());
        }

        try {
            $event->update(['event_note' => 'changed']);

            $this->fail('Suchak customer timeline event update should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer timeline events are immutable and cannot be modified.', $exception->getMessage());
        }

        try {
            $event->delete();

            $this->fail('Suchak customer timeline event delete should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Suchak customer timeline events are immutable and cannot be deleted.', $exception->getMessage());
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
        ]);

        return [$user, $account];
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: MatrimonyProfile, 3: SuchakProfileRepresentation}
     */
    private function representationFixture(): array
    {
        [$user, $account] = $this->verifiedSuchakActor();
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Day 34 Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $sourceLink = $this->sourceLink($user, $account, $profile, SuchakBiodataIntakeLink::STATUS_LINKED_TO_EXISTING_PROFILE);
        $representation = $this->representation($account, $profile, $sourceLink);

        return [$user, $account, $profile, $representation];
    }

    private function sourceLink(
        User $user,
        SuchakAccount $account,
        ?MatrimonyProfile $profile = null,
        string $sourceStatus = SuchakBiodataIntakeLink::STATUS_INTAKE_UPLOADED,
    ): SuchakBiodataIntakeLink {
        $intake = BiodataIntake::query()->create([
            'uploaded_by' => $user->id,
            'raw_ocr_text' => 'Day-34 Suchak customer context fixture',
            'intake_status' => 'uploaded',
            'parse_status' => 'pending',
            'approved_by_user' => false,
            'intake_locked' => false,
            'snapshot_schema_version' => 1,
        ]);

        return SuchakBiodataIntakeLink::query()->create([
            'suchak_account_id' => $account->id,
            'biodata_intake_id' => $intake->id,
            'matrimony_profile_id' => $profile?->id,
            'source_status' => $sourceStatus,
            'created_by_user_id' => $user->id,
        ]);
    }

    private function representation(
        SuchakAccount $account,
        MatrimonyProfile $profile,
        SuchakBiodataIntakeLink $sourceLink,
    ): SuchakProfileRepresentation {
        return SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'biodata_intake_id' => $sourceLink->biodata_intake_id,
            'representation_status' => SuchakProfileRepresentation::STATUS_PENDING,
            'representation_mode' => SuchakProfileRepresentation::MODE_MATCHED_EXISTING_PROFILE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_NOT_REQUESTED,
        ]);
    }
}
