<?php

namespace Tests\Feature\Suchak;

use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakConsent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakPolicy;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakPolicyService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakCollaborationAdvancedCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_collaboration_center_completes_commission_ui_acceptance_and_ledger_linkage(): void
    {
        $this->setCollaborationSlaDays(3);
        [$requestingUser, $requestingAccount, $requestingRepresentation, $targetAccount, $targetRepresentation] = $this->validCollaborationFixture();
        $this->insertPrivateContactFixture($targetRepresentation->matrimonyProfile);

        $this->actingAs($requestingUser)
            ->post(route('suchak.collaborations.store'), [
                'requesting_representation_id' => $requestingRepresentation->id,
                'target_representation_id' => $targetRepresentation->id,
                'message' => 'Day 29 collaboration request.',
                'commission_ack' => '1',
                'split_type' => SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT,
                'groom_side_share' => '60',
                'bride_side_share' => '40',
                'currency' => 'INR',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Collaboration request created with commission acknowledgement.');

        $collaboration = SuchakCollaborationRequest::query()->with('commissionAgreement')->firstOrFail();
        $this->assertSame(SuchakCommissionAgreement::SPLIT_CUSTOM_PERCENT, $collaboration->commissionAgreement->split_type);
        $this->assertSame('60.00', $collaboration->commissionAgreement->groom_side_share);
        $this->assertSame('40.00', $collaboration->commissionAgreement->bride_side_share);
        $this->assertTrue($collaboration->expires_at->greaterThan(now()->addDays(2)));
        $this->assertTrue($collaboration->expires_at->lessThan(now()->addDays(4)));

        $this->actingAs($requestingUser)
            ->get(route('suchak.collaborations.index'))
            ->assertOk()
            ->assertSee('Collaboration Center', false)
            ->assertSee('Current collaboration response timeout', false)
            ->assertSee('3 days', false)
            ->assertSee(route('suchak.collaborations.commission.update', $collaboration), false)
            ->assertDontSee('Sensitive Target Candidate', false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('Target Secret Lane', false);

        $this->actingAs($requestingUser)
            ->post(route('suchak.collaborations.commission.update', $collaboration), [
                'split_type' => SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT,
                'fixed_amount' => '25000',
                'currency' => 'INR',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Commission agreement terms updated.');

        $collaboration->refresh()->load('commissionAgreement');
        $this->assertSame(SuchakCommissionAgreement::SPLIT_FIXED_AMOUNT, $collaboration->commissionAgreement->split_type);
        $this->assertSame('25000.00', $collaboration->commissionAgreement->fixed_amount);
        $this->assertSame(SuchakCommissionAgreement::STATUS_PENDING, $collaboration->commissionAgreement->agreement_status);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $requestingAccount->id,
            'actor_user_id' => $requestingUser->id,
            'action_type' => SuchakActivityLog::ACTION_COMMISSION_AGREEMENT_UPDATED,
            'target_type' => 'suchak_collaboration_request',
            'target_id' => $collaboration->id,
        ]);

        $this->actingAs($targetAccount->user)
            ->get(route('suchak.collaborations.index'))
            ->assertOk()
            ->assertSee('Review and accept', false)
            ->assertSee('INR 25000.00', false)
            ->assertDontSee('Sensitive Requesting Candidate', false);

        $this->actingAs($targetAccount->user)
            ->post(route('suchak.collaborations.accept', $collaboration))
            ->assertRedirect()
            ->assertSessionHas('success', 'Collaboration request accepted.');

        $accepted = $collaboration->fresh(['commissionAgreement']);
        $this->assertSame(SuchakCollaborationRequest::STATUS_ACCEPTED, $accepted->status);
        $this->assertSame(SuchakCommissionAgreement::STATUS_ACCEPTED, $accepted->commissionAgreement->agreement_status);
        $this->assertTrue(app(SuchakCollaborationService::class)->canExchangeContact($accepted));

        $this->actingAs($targetAccount->user)
            ->post(route('suchak.collaborations.ledger-entries.store', $accepted), [
                'entry_type' => SuchakLedgerEntry::TYPE_SUCCESS_FEE_EXPECTED,
                'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
                'amount' => '25000',
                'currency' => 'INR',
                'status' => SuchakLedgerEntry::STATUS_EXPECTED,
                'due_date' => now()->addDays(10)->toDateString(),
                'note' => 'Commission expected after family confirmation.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Collaboration ledger entry linked.');

        $ledger = SuchakLedgerEntry::query()->firstOrFail();
        $this->assertSame($targetAccount->id, $ledger->suchak_account_id);
        $this->assertSame($accepted->id, $ledger->collaboration_request_id);
        $this->assertSame($requestingRepresentation->matrimony_profile_id, $ledger->matrimony_profile_id);
        $this->assertSame(SuchakPaymentContext::SOURCE_COLLABORATION, $ledger->paymentContext->source_owner);
        $this->assertSame(SuchakPaymentContext::COLLECTOR_SUCHAK, $ledger->paymentContext->payment_collector);
        $this->assertSame('Sensitive Requesting Candidate', $requestingRepresentation->matrimonyProfile->fresh()->full_name);

        $this->actingAs($targetAccount->user)
            ->get(route('suchak.collaborations.index'))
            ->assertOk()
            ->assertSee('Success Fee Expected', false)
            ->assertSee('INR 25000.00', false);
    }

    public function test_overdue_collaboration_can_be_expired_by_participant_and_blocks_later_actions(): void
    {
        [$requestingUser, $requestingAccount, $requestingRepresentation, $targetAccount, $targetRepresentation] = $this->validCollaborationFixture();
        $collaboration = app(SuchakCollaborationService::class)->createRequest(
            $requestingAccount,
            $requestingUser,
            $requestingRepresentation,
            $targetRepresentation,
        )['request'];

        DB::table('suchak_collaboration_requests')
            ->where('id', $collaboration->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($requestingUser)
            ->post(route('suchak.collaborations.expire', $collaboration))
            ->assertRedirect()
            ->assertSessionHas('success', 'Overdue collaboration request expired.');

        $expired = $collaboration->fresh(['commissionAgreement']);
        $this->assertSame(SuchakCollaborationRequest::STATUS_EXPIRED, $expired->status);
        $this->assertSame(SuchakCommissionAgreement::STATUS_CANCELLED, $expired->commissionAgreement->agreement_status);
        $this->assertDatabaseHas('suchak_activity_logs', [
            'suchak_account_id' => $requestingAccount->id,
            'actor_user_id' => $requestingUser->id,
            'action_type' => SuchakActivityLog::ACTION_COLLABORATION_REQUEST_EXPIRED,
            'target_id' => $collaboration->id,
        ]);

        $this->actingAs($targetAccount->user)
            ->post(route('suchak.collaborations.accept', $collaboration))
            ->assertRedirect()
            ->assertSessionHas('error', 'Only pending collaboration requests can be changed.');

        $this->actingAs($targetAccount->user)
            ->post(route('suchak.collaborations.ledger-entries.store', $collaboration), [
                'entry_type' => SuchakLedgerEntry::TYPE_SUCCESS_FEE_EXPECTED,
                'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
                'amount' => '1000',
                'currency' => 'INR',
                'status' => SuchakLedgerEntry::STATUS_EXPECTED,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Collaboration must be accepted with commission acknowledgement before ledger linkage.');
    }

    private function setCollaborationSlaDays(int $days): void
    {
        SuchakPolicy::query()->updateOrCreate(
            ['policy_key' => SuchakPolicyService::KEY_COLLABORATION_SLA_DAYS],
            [
                'policy_value' => (string) $days,
                'value_type' => SuchakPolicy::TYPE_INTEGER,
                'description' => 'Test collaboration SLA.',
                'is_active' => true,
            ],
        );
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakProfileRepresentation, 3: SuchakAccount, 4: SuchakProfileRepresentation}
     */
    private function validCollaborationFixture(): array
    {
        [$requestingUser, $requestingAccount] = $this->verifiedSuchakActor('day29-requesting@example.test', 'Day 29 Requesting Suchak');
        [, $targetAccount] = $this->verifiedSuchakActor('day29-target@example.test', 'Day 29 Target Suchak');

        $requestingProfile = $this->activeProfile([
            'full_name' => 'Sensitive Requesting Candidate',
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'highest_education' => 'Requesting MBA',
        ]);
        $targetProfile = $this->activeProfile([
            'full_name' => 'Sensitive Target Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'highest_education' => 'Target B.Tech',
            'address_line' => 'Target Secret Lane',
        ]);

        $requestingRepresentation = $this->activeRepresentation($requestingAccount, $requestingProfile);
        $targetRepresentation = $this->activeRepresentation($targetAccount, $targetProfile);

        return [$requestingUser, $requestingAccount, $requestingRepresentation, $targetAccount, $targetRepresentation];
    }

    /**
     * @return array{0: User, 1: SuchakAccount}
     */
    private function verifiedSuchakActor(string $email, string $name): array
    {
        $user = User::factory()->create(['email' => $email]);
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'suchak_name' => $name,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ]);

        return [$user, $account];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'full_name' => 'Private Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'height_cm' => 164,
            'highest_education' => 'Generic Education',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes, [
            'lifecycle_state' => 'draft',
        ]));

        $leafId = (int) City::query()->where('name', 'Pune City')->firstOrFail()->id;

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

    private function activeRepresentation(SuchakAccount $account, MatrimonyProfile $profile): SuchakProfileRepresentation
    {
        $representation = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
            'consent_status' => SuchakProfileRepresentation::CONSENT_ACCEPTED,
            'first_verified_consent_at' => now(),
            'consent_verified_at' => now(),
            'consent_valid_until' => now()->addYear(),
        ]);

        SuchakConsent::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_id' => $representation->id,
            'consent_status' => SuchakConsent::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'used_at' => now(),
            'otp_verified_at' => now(),
            'valid_from' => now(),
            'valid_until' => $representation->consent_valid_until,
        ]);

        return $representation->fresh(['suchakAccount', 'matrimonyProfile.gender']);
    }

    private function insertPrivateContactFixture(MatrimonyProfile $profile): void
    {
        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Sensitive Target Candidate',
            'phone_number' => '9876543210',
            'is_primary' => true,
            'visibility_rule' => 'unlock_only',
            'verified_status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('profile_contacts', 'contact_relation_id')) {
            $contactRow['contact_relation_id'] = null;
        }

        if (Schema::hasColumn('profile_contacts', 'relation_type')) {
            $contactRow['relation_type'] = 'self';
        }

        DB::table('profile_contacts')->insert($contactRow);
    }
}
