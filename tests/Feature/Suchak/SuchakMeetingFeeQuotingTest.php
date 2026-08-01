<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakCustomerPlan;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use App\Models\SuchakVisitConfirmation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The four-fee agreement is only worth anything if the fees survive the trip
 * from the Suchak's plan to the package the customer is asked to accept.
 *
 * `/customers/{rep}/payment-setup` is the ONLY caller of
 * SuchakPackageCatalogService::createCustomPackage() in app/, and it used to
 * send none of `per_meeting_fee_amount`, `per_meeting_online_fee_amount`,
 * `post_marriage_fee_mode`, `post_marriage_fee_amount` — the keys were not even
 * accepted by its validate() block. Every package production can actually
 * create therefore carried four NULLs, the public agreement page read
 * "ठरलेले नाही" for three of the four fees, and the meeting engine froze a null
 * fee onto every meeting. The plans screen displayed the rates the whole time.
 *
 * These tests hold the wiring in place from both ends: what a preset send
 * quotes, what a custom send quotes, that "not configured" still means null
 * rather than an invented figure, and that the agreement digest is taken from
 * the package AFTER the fees are on it.
 */
class SuchakMeetingFeeQuotingTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- custom send

    public function test_a_custom_send_freezes_the_four_submitted_fees_onto_the_package(): void
    {
        $rep = $this->bootSuchak();

        $data = $this->prepare($rep->id, [
            'package_name' => 'Custom coordination',
            'services' => ['Horoscope match'],
            'price_amount' => '12000',
            'per_meeting_fee_amount' => '750',
            'per_meeting_online_fee_amount' => '1800',
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            'post_marriage_fee_amount' => '34500',
        ]);

        $package = SuchakServicePackage::query()->findOrFail($data['service_package_id']);

        $this->assertSame('750.00', $package->per_meeting_fee_amount);
        // The online rate is an independent figure, never derived from the
        // offline one — here it is deliberately the larger of the two.
        $this->assertSame('1800.00', $package->per_meeting_online_fee_amount);
        $this->assertSame(SuchakCustomerPlan::MODE_FIXED, $package->post_marriage_fee_mode);
        $this->assertSame('34500.00', $package->post_marriage_fee_amount);
    }

    public function test_a_custom_send_that_quotes_no_fee_stores_null_and_not_a_default(): void
    {
        $rep = $this->bootSuchak();

        $data = $this->prepare($rep->id, [
            'package_name' => 'Bare custom plan',
            'services' => ['Introductions only'],
        ]);

        $package = SuchakServicePackage::query()->findOrFail($data['service_package_id']);

        // "Not agreed" is a real answer. Inventing ₹999 here would be a charge
        // nobody consented to.
        $this->assertNull($package->per_meeting_fee_amount);
        $this->assertNull($package->per_meeting_online_fee_amount);
        $this->assertNull($package->post_marriage_fee_mode);
        $this->assertNull($package->post_marriage_fee_amount);
    }

    public function test_the_post_marriage_mode_uses_the_existing_plan_vocabulary(): void
    {
        $rep = $this->bootSuchak();

        $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", [
            'package_name' => 'Bad mode',
            'services' => ['Introductions only'],
            'post_marriage_fee_mode' => 'whatever_the_client_felt_like',
        ])->assertStatus(422)->assertJsonValidationErrors('post_marriage_fee_mode');

        // ...and each of the three real modes is accepted, so nothing was
        // narrowed while adding the guard.
        foreach (SuchakCustomerPlan::POST_MARRIAGE_FEE_MODES as $mode) {
            $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", [
                'package_name' => 'Mode '.$mode,
                'services' => ['Introductions only'],
                'post_marriage_fee_mode' => $mode,
            ])->assertCreated();
        }
    }

    // ------------------------------------------------------------- preset send

    public function test_a_preset_send_takes_its_fees_from_the_suchaks_configured_plan(): void
    {
        $rep = $this->bootSuchak();
        $account = $rep->suchakAccount;

        // The per-Suchak row the carousel resolver reads for this preset.
        SuchakCustomerPlan::query()->create([
            'suchak_account_id' => $account->id,
            'preset_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '500.00',
            'per_meeting_online_fee_amount' => '300.00',
            'post_marriage_fee_mode' => SuchakCustomerPlan::MODE_FIXED,
            'post_marriage_fee_amount' => '25000.00',
            'is_visible' => true,
            'sort_order' => 0,
        ]);

        $data = $this->prepare($rep->id, ['plan_key' => SuchakDefaultPlans::KEY_BASIC]);
        $package = SuchakServicePackage::query()->findOrFail($data['service_package_id']);

        $this->assertSame('500.00', $package->per_meeting_fee_amount);
        $this->assertSame('300.00', $package->per_meeting_online_fee_amount);
        $this->assertSame(SuchakCustomerPlan::MODE_FIXED, $package->post_marriage_fee_mode);
        $this->assertSame('25000.00', $package->post_marriage_fee_amount);
    }

    public function test_a_preset_with_no_configured_plan_quotes_nothing_rather_than_guessing(): void
    {
        $rep = $this->bootSuchak();

        $data = $this->prepare($rep->id, ['plan_key' => SuchakDefaultPlans::KEY_PREMIUM]);
        $package = SuchakServicePackage::query()->findOrFail($data['service_package_id']);

        $this->assertNull($package->per_meeting_fee_amount);
        $this->assertNull($package->per_meeting_online_fee_amount);
        $this->assertNull($package->post_marriage_fee_mode);
        $this->assertNull($package->post_marriage_fee_amount);
    }

    public function test_a_preset_send_ignores_fees_put_on_the_request(): void
    {
        $rep = $this->bootSuchak();

        // The plan is the authority for a plan. Honouring a request-side fee here
        // would reopen the gap between what the carousel showed and what froze.
        $data = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '9999',
        ]);

        $package = SuchakServicePackage::query()->findOrFail($data['service_package_id']);
        $this->assertNull($package->per_meeting_fee_amount);
    }

    // --------------------------------------------------------- snapshot order

    public function test_the_agreement_digest_is_taken_after_the_fees_are_on_the_package(): void
    {
        $rep = $this->bootSuchak();

        $data = $this->prepare($rep->id, [
            'package_name' => 'Digest ordering',
            'services' => ['Introductions'],
            'per_meeting_fee_amount' => '750',
        ]);

        $agreement = SuchakCustomerAgreement::query()->findOrFail($data['customer_agreement_id']);
        $agreementService = app(SuchakAgreementService::class);

        // Current: the stored digest already describes a package carrying 750.
        // Had the snapshot been taken before the fees were written, this would be
        // false the moment the row was created.
        $this->assertTrue(
            $agreementService->isPackageSnapshotCurrent($agreement),
            'The snapshot must be digested from the finished package, not from a package still being built.',
        );

        // ...and the digest genuinely covers the fee, so a re-quote is a new
        // agreement rather than a silent re-price of the old one.
        $package = SuchakServicePackage::query()->findOrFail($data['service_package_id']);
        $package->forceFill(['per_meeting_fee_amount' => '900.00'])->save();

        $this->assertFalse(
            $agreementService->isPackageSnapshotCurrent($agreement->fresh()),
            'Changing a frozen fee must invalidate the snapshot; a different quote is a different agreement.',
        );
    }

    // ---------------------------------------------------------------- currency

    public function test_a_meeting_fee_renders_in_the_currency_it_was_quoted_in(): void
    {
        $rep = $this->bootSuchak();
        $account = $rep->suchakAccount;
        $pipeline = SuchakPipeline::factory()->create();

        $visit = SuchakVisitConfirmation::query()->create([
            'pipeline_id' => $pipeline->id,
            'suchak_account_id' => $account->id,
            'request_id' => $pipeline->request_id,
            'representation_id' => $pipeline->representation_id,
            'target_matrimony_profile_id' => $pipeline->target_matrimony_profile_id,
            'requesting_matrimony_profile_id' => $pipeline->requesting_matrimony_profile_id,
            'visit_status' => SuchakVisitConfirmation::STATUS_SCHEDULED,
            'scheduled_by_user_id' => $account->user_id,
            'scheduled_at' => now(),
            'fee_amount' => '500.00',
        ]);

        // `fee_currency` is the sibling column that freezes the unit beside the
        // figure. While it is absent the endpoint must still expose the key and
        // fall back to MoneyFormat's own default; once it lands, a USD quote must
        // stop reading as ₹. Both halves are asserted, so this test strengthens
        // itself the moment the migration carries the column.
        $currencyFrozen = Schema::hasColumn('suchak_visit_confirmations', 'fee_currency');
        if ($currencyFrozen) {
            DB::table('suchak_visit_confirmations')
                ->where('id', $visit->id)
                ->update(['fee_currency' => 'USD']);
        }

        $payload = $this->getJson('/api/v1/suchak/meetings')
            ->assertOk()
            ->json('data.visits.0');

        $this->assertArrayHasKey('fee_currency', $payload, 'A frozen amount must travel with its unit.');
        $this->assertSame('500.00', $payload['fee_amount']);
        $this->assertSame($currencyFrozen ? 'USD 500' : '₹500', $payload['fee_display']);
    }

    // ----------------------------------------------------------------- helpers

    private function bootSuchak(): SuchakProfileRepresentation
    {
        // Production publish policy left alone (admin_review): preset and custom
        // sends self-publish through the forced auto-publish flag.
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Fee Quoting Candidate',
            'date_of_birth' => now()->subYears(28)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $rep = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($user);

        return $rep->load('suchakAccount');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function prepare(int $repId, array $payload): array
    {
        $response = $this->postJson("/api/v1/suchak/customers/{$repId}/payment-setup", $payload);
        if ($response->status() !== 201) {
            fwrite(STDERR, "\n[PREPARE] status={$response->status()} body=".$response->getContent()."\n");
        }
        $response->assertCreated();

        return $response->json('data');
    }
}
