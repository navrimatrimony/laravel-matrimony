<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakProfileRequest;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakVisitConfirmationService;
use App\Notifications\SuchakMeetingCompletionMarkedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * U8: customer is told when a meeting is marked complete.
 */
class SuchakMeetingCompletionMarkedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_u8_marking_complete_notifies_customer_once(): void
    {
        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);

        $visit = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
            'schedule_note' => 'U8 schedule',
        ]);

        Notification::fake();

        $service->markSuchakCompleted($visit, $fixture['suchakUser'], [
            'completion_note' => 'Meeting happened; family should confirm.',
        ]);

        Notification::assertSentTo(
            $fixture['customerUser'],
            SuchakMeetingCompletionMarkedNotification::class,
            function (SuchakMeetingCompletionMarkedNotification $notification) use ($visit): bool {
                return $notification->visitId === (int) $visit->id;
            },
        );
        Notification::assertSentToTimes(
            $fixture['customerUser'],
            SuchakMeetingCompletionMarkedNotification::class,
            1,
        );
        Notification::assertNotSentTo(
            $fixture['suchakUser'],
            SuchakMeetingCompletionMarkedNotification::class,
        );
    }

    public function test_u8_double_complete_notifies_only_once(): void
    {
        $fixture = $this->meetingFixture();
        $service = app(SuchakVisitConfirmationService::class);

        $visit = $service->scheduleVisit($fixture['pipeline'], $fixture['suchakUser'], [
            'payment_context_id' => $fixture['paymentContext']->id,
        ]);

        Notification::fake();

        $service->markSuchakCompleted($visit, $fixture['suchakUser'], [
            'completion_note' => 'First mark.',
        ]);

        try {
            $service->markSuchakCompleted($visit->fresh(), $fixture['suchakUser'], [
                'completion_note' => 'Second mark must be refused.',
            ]);
            $this->fail('Double completion must be refused.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('already marked', $exception->getMessage());
        }

        Notification::assertSentToTimes(
            $fixture['customerUser'],
            SuchakMeetingCompletionMarkedNotification::class,
            1,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function meetingFixture(): array
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);
        $suchakUser = User::factory()->create();
        $requestingUser = User::factory()->create();
        $customerUser = User::factory()->create();

        $account = SuchakAccount::factory()->create([
            'user_id' => $suchakUser->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);
        $requestingProfile = MatrimonyProfile::factory()->create([
            'user_id' => $requestingUser->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $targetProfile = MatrimonyProfile::factory()->create([
            'user_id' => $customerUser->id,
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $targetProfile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);
        $request = SuchakProfileRequest::query()->create([
            'requesting_user_id' => $requestingUser->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'request_status' => SuchakProfileRequest::STATUS_PENDING,
            'request_reason' => 'intro_visit',
            'message' => 'Please arrange an introduction.',
        ]);
        $pipeline = SuchakPipeline::query()->create([
            'request_id' => $request->id,
            'target_matrimony_profile_id' => $targetProfile->id,
            'requesting_matrimony_profile_id' => $requestingProfile->id,
            'selected_suchak_account_id' => $account->id,
            'representation_id' => $representation->id,
            'pipeline_status' => SuchakPipeline::STATUS_PENDING,
            'attribution_locked_at' => now(),
            'lock_expires_at' => now()->addDays(2),
            'sla_status' => SuchakPipeline::SLA_WITHIN,
        ]);
        $customerContext = SuchakCustomerContext::query()->create([
            'suchak_account_id' => $account->id,
            'candidate_matrimony_profile_id' => $targetProfile->id,
            'payer_name' => 'Candidate family',
            'payer_relationship_to_candidate' => 'Parent',
            'service_context' => SuchakCustomerContext::SERVICE_PACKAGE_LEAD,
            'source_owner' => SuchakPaymentContext::SOURCE_SUCHAK,
            'source_type' => SuchakCustomerContext::SOURCE_TYPE_MANUAL,
            'customer_lifecycle_status' => SuchakCustomerContext::STATUS_ACTIVE_SERVICE,
            'created_by_user_id' => $suchakUser->id,
            'classified_by_user_id' => $suchakUser->id,
            'classified_at' => now(),
            'opened_at' => now(),
        ]);
        $package = SuchakServicePackage::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'package_name' => 'U8 package',
            'price_amount' => '20000.00',
            'currency' => 'INR',
            'per_meeting_fee_amount' => '3000.00',
            'per_meeting_online_fee_amount' => '5000.00',
            'package_status' => SuchakServicePackage::STATUS_PUBLISHED,
            'approval_policy_mode' => SuchakServicePackage::APPROVAL_MODE_AUTO_PUBLISH,
            'requires_admin_approval' => false,
            'customized_by_user_id' => $suchakUser->id,
            'published_at' => now(),
        ]);
        SuchakCustomerAgreement::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'service_package_id' => $package->id,
            'agreement_revision' => 1,
            'terms_status' => SuchakCustomerAgreement::TERMS_ACCEPTED,
            'terms_policy_mode' => SuchakCustomerAgreement::POLICY_STRICT,
            'agreement_snapshot_hash' => str_repeat('b', 64),
            'package_name' => 'U8 package',
            'price_amount' => '20000.00',
            'currency' => 'INR',
            'agreement_title' => 'U8 agreement',
            'created_by_user_id' => $suchakUser->id,
            'accepted_at' => now(),
        ]);
        $paymentContext = SuchakPaymentContext::query()->create([
            'suchak_account_id' => $account->id,
            'customer_context_id' => $customerContext->id,
            'matrimony_profile_id' => $targetProfile->id,
            'pipeline_id' => $pipeline->id,
            'source_owner' => SuchakPaymentContext::SOURCE_PLATFORM,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_PLATFORM,
            'context_status' => SuchakPaymentContext::STATUS_ACTIVE,
            'resolved_by_user_id' => $admin->id,
            'resolution_note' => 'U8 platform context.',
        ]);

        return [
            'suchakUser' => $suchakUser,
            'customerUser' => $customerUser,
            'pipeline' => $pipeline->fresh(['selectedSuchakAccount', 'request', 'representation']),
            'paymentContext' => $paymentContext->fresh(['suchakAccount', 'pipeline']),
        ];
    }
}
