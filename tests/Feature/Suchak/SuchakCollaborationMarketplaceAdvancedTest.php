<?php

namespace Tests\Feature\Suchak;

use App\Models\Caste;
use App\Models\City;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakConsent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Services\Profile\ProfileCanonicalResidenceService;
use Database\Seeders\MinimalLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuchakCollaborationMarketplaceAdvancedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MinimalLocationSeeder::class);
        ProfileCanonicalResidenceService::forgetCachedMasters();
    }

    public function test_day_51_collaboration_marketplace_suggests_masked_opportunities_and_locks_collector(): void
    {
        $this->assertTrue(Schema::hasColumn('suchak_commission_agreements', 'collector_suchak_account_id'));

        [$requestingUser, $requestingAccount, $requestingRepresentation, $targetAccount, $targetRepresentation] = $this->collaborationMarketplaceFixture();
        $this->insertPrivateContactFixture($targetRepresentation->matrimonyProfile);

        $suggestions = app(SuchakCollaborationService::class)->suggestedOpportunities($requestingAccount);
        $this->assertCount(1, $suggestions);
        $this->assertSame($requestingRepresentation->id, $suggestions->first()['requesting_representation_id']);
        $this->assertSame($targetRepresentation->id, $suggestions->first()['target_representation_id']);

        $this->actingAs($requestingUser)
            ->get(route('suchak.collaborations.index'))
            ->assertOk()
            ->assertSee('Suggested collaboration opportunities', false)
            ->assertSee('masked-', false)
            ->assertSee('Same caste as an active Suchak representation.', false)
            ->assertSee('Collector lock after acceptance', false)
            ->assertSee('Request collaboration', false)
            ->assertDontSee('Day51 Sensitive Target Candidate', false)
            ->assertDontSee('9876543210', false)
            ->assertDontSee('Day51 Secret Lane', false);

        $this->actingAs($requestingUser)
            ->post(route('suchak.collaborations.store'), [
                'requesting_representation_id' => $requestingRepresentation->id,
                'target_representation_id' => $targetRepresentation->id,
                'message' => 'Day51 masked collaboration request.',
                'commission_ack' => '1',
                'split_type' => SuchakCommissionAgreement::SPLIT_TO_BE_DISCUSSED,
                'currency' => 'INR',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Collaboration request created with commission acknowledgement.');

        $collaboration = SuchakCollaborationRequest::query()
            ->with('commissionAgreement')
            ->firstOrFail();
        $this->assertSame($targetAccount->id, $collaboration->commissionAgreement->collector_suchak_account_id);

        $this->assertCount(0, app(SuchakCollaborationService::class)->suggestedOpportunities($requestingAccount));

        $this->actingAs($targetAccount->user)
            ->post(route('suchak.collaborations.accept', $collaboration))
            ->assertRedirect()
            ->assertSessionHas('success', 'Collaboration request accepted.');

        $accepted = $collaboration->fresh(['commissionAgreement']);
        $this->assertSame(SuchakCommissionAgreement::STATUS_ACCEPTED, $accepted->commissionAgreement->agreement_status);

        $this->actingAs($requestingUser)
            ->post(route('suchak.collaborations.ledger-entries.store', $accepted), $this->ledgerPayload())
            ->assertRedirect()
            ->assertSessionHas('error', 'Only the locked collector Suchak can record collaboration income for this request.');
        $this->assertSame(0, SuchakLedgerEntry::query()->count());

        $this->actingAs($targetAccount->user)
            ->post(route('suchak.collaborations.ledger-entries.store', $accepted), array_merge($this->ledgerPayload(), [
                'payment_collector' => SuchakPaymentContext::COLLECTOR_PLATFORM,
            ]))
            ->assertRedirect()
            ->assertSessionHas('error', 'Collaboration income must use the locked Suchak collector.');
        $this->assertSame(0, SuchakLedgerEntry::query()->count());

        $this->actingAs($targetAccount->user)
            ->get(route('suchak.collaborations.index'))
            ->assertOk()
            ->assertSee('Dispute reference: Collaboration #'.$accepted->id, false)
            ->assertSee('Payment collector: #'.$targetAccount->id.' Day51 Target Suchak', false)
            ->assertDontSee('Day51 Sensitive Requesting Candidate', false);

        $this->actingAs($targetAccount->user)
            ->post(route('suchak.collaborations.ledger-entries.store', $accepted), $this->ledgerPayload())
            ->assertRedirect()
            ->assertSessionHas('success', 'Collaboration ledger entry linked.');

        $ledger = SuchakLedgerEntry::query()->with('paymentContext')->firstOrFail();
        $this->assertSame($targetAccount->id, $ledger->suchak_account_id);
        $this->assertSame($accepted->id, $ledger->collaboration_request_id);
        $this->assertSame($requestingRepresentation->matrimony_profile_id, $ledger->matrimony_profile_id);
        $this->assertSame(SuchakPaymentContext::SOURCE_COLLABORATION, $ledger->paymentContext->source_owner);
        $this->assertSame(SuchakPaymentContext::COLLECTOR_SUCHAK, $ledger->paymentContext->payment_collector);
        $this->assertSame($accepted->id, $ledger->paymentContext->collaboration_request_id);
    }

    /**
     * @return array{0: User, 1: SuchakAccount, 2: SuchakProfileRepresentation, 3: SuchakAccount, 4: SuchakProfileRepresentation}
     */
    private function collaborationMarketplaceFixture(): array
    {
        [$religion, $caste] = $this->community();
        $requestingUser = User::factory()->create(['email' => 'day51-requesting@example.test']);
        $requestingAccount = $this->suchakAccount([
            'user_id' => $requestingUser->id,
            'suchak_name' => 'Day51 Requesting Suchak',
        ]);
        $targetAccount = $this->suchakAccount([
            'suchak_name' => 'Day51 Target Suchak',
        ]);

        $requestingProfile = $this->activeProfile([
            'full_name' => 'Day51 Sensitive Requesting Candidate',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
        ]);
        $targetProfile = $this->activeProfile([
            'full_name' => 'Day51 Sensitive Target Candidate',
            'religion_id' => $religion->id,
            'caste_id' => $caste->id,
            'address_line' => 'Day51 Secret Lane',
        ]);

        return [
            $requestingUser,
            $requestingAccount,
            $this->activeRepresentation($requestingAccount, $requestingProfile),
            $targetAccount,
            $this->activeRepresentation($targetAccount, $targetProfile),
        ];
    }

    /**
     * @return array{0: Religion, 1: Caste}
     */
    private function community(): array
    {
        $religion = Religion::query()->create([
            'key' => 'day51_religion_'.Religion::query()->count(),
            'label' => 'Day51 Religion',
            'label_en' => 'Day51 Religion',
            'is_active' => true,
        ]);
        $caste = Caste::query()->create([
            'religion_id' => $religion->id,
            'key' => 'day51_caste_'.Caste::query()->count(),
            'label' => 'Day51 Caste',
            'label_en' => 'Day51 Caste',
            'is_active' => true,
        ]);

        return [$religion, $caste];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function suchakAccount(array $attributes = []): SuchakAccount
    {
        return SuchakAccount::factory()->create(array_merge([
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activeProfile(array $attributes = []): MatrimonyProfile
    {
        $city = City::query()->where('name', 'Pune City')->firstOrFail();
        $profile = MatrimonyProfile::factory()->create(array_merge([
            'date_of_birth' => now()->subYears(29)->toDateString(),
            'height_cm' => 164,
            'highest_education' => 'Graduate',
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ], $attributes));

        if (Schema::hasColumn($profile->getTable(), 'location_id')) {
            DB::table($profile->getTable())->where('id', $profile->id)->update(['location_id' => $city->id]);
            $profile->refresh();
        } else {
            ProfileCanonicalResidenceService::upsertSelfCurrent((int) $profile->id, (int) $city->id, null, true, false);
        }

        $profile->forceFill([
            'lifecycle_state' => 'active',
            'is_suspended' => false,
        ])->save();

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

    /**
     * @return array<string, mixed>
     */
    private function ledgerPayload(): array
    {
        return [
            'entry_type' => SuchakLedgerEntry::TYPE_SUCCESS_FEE_EXPECTED,
            'payment_collector' => SuchakPaymentContext::COLLECTOR_SUCHAK,
            'amount' => '25000',
            'currency' => 'INR',
            'status' => SuchakLedgerEntry::STATUS_EXPECTED,
            'due_date' => now()->addDays(10)->toDateString(),
            'note' => 'Commission expected after family confirmation.',
        ];
    }

    private function insertPrivateContactFixture(MatrimonyProfile $profile): void
    {
        $contactRow = [
            'profile_id' => $profile->id,
            'contact_name' => 'Day51 Sensitive Target Candidate',
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
