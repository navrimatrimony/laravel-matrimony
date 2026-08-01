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

    public function test_a_preset_send_freezes_the_fee_it_posted_over_the_plans_default(): void
    {
        $rep = $this->bootSuchak();

        // The plan is the DEFAULT; this send is the DECISION. The Suchak ticked
        // ₹999 on the Basic card and the WhatsApp message quotes ₹999, so ₹999 is
        // what the acceptance page must freeze — not the ₹500 the plan happens to
        // carry. A preset that ignored the posted figure is exactly how a family
        // came to hold a message quoting a meeting fee and a frozen agreement
        // saying nothing had been agreed for meetings.
        SuchakCustomerPlan::query()->create([
            'suchak_account_id' => $rep->suchakAccount->id,
            'preset_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '500.00',
            'per_meeting_online_fee_amount' => '300.00',
            'is_visible' => true,
            'sort_order' => 0,
        ]);

        $data = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '999',
        ]);

        $package = SuchakServicePackage::query()->findOrFail($data['service_package_id']);

        $this->assertSame('999.00', $package->per_meeting_fee_amount);
        // Only the fee that was decided moves. The three the send said nothing
        // about still come off the plan, so overriding one figure never silently
        // wipes the others.
        $this->assertSame('300.00', $package->per_meeting_online_fee_amount);
    }

    public function test_a_preset_send_can_post_null_to_charge_nothing_for_a_fee_the_plan_prices(): void
    {
        $rep = $this->bootSuchak();

        SuchakCustomerPlan::query()->create([
            'suchak_account_id' => $rep->suchakAccount->id,
            'preset_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '500.00',
            'is_visible' => true,
            'sort_order' => 0,
        ]);

        // "Not for this family" is a decision, and a decision beats a default.
        // The message omits the meeting-fee line when the Suchak opts it out, so
        // the frozen terms have to omit it too.
        $data = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => null,
        ]);

        $package = SuchakServicePackage::query()->findOrFail($data['service_package_id']);
        $this->assertNull($package->per_meeting_fee_amount);
    }

    // ------------------------------------------------------- registration fee

    public function test_a_preset_send_freezes_the_price_it_posted_over_the_presets_hardcoded_figure(): void
    {
        $rep = $this->bootSuchak();

        // The bug the product owner hit on a device: the acceptance page read
        // "नोंदणी शुल्क ₹2,000" — the hardcoded SuchakDefaultPlans Basic figure —
        // whatever the Suchak had priced the plan at and whatever the WhatsApp
        // message quoted. The price is the largest number on the page, so it is
        // the last one allowed to drift.
        $data = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'price_amount' => '3000',
        ]);

        $package = SuchakServicePackage::query()->findOrFail($data['service_package_id']);
        $this->assertSame('3000.00', $package->price_amount);

        // ...and it reaches the document the family actually accepts, not just
        // the package underneath it.
        $agreement = SuchakCustomerAgreement::query()->findOrFail($data['customer_agreement_id']);
        $this->assertSame('3000.00', $agreement->price_amount);
    }

    public function test_a_preset_send_without_a_price_takes_the_suchaks_override_then_the_preset(): void
    {
        $rep = $this->bootSuchak();

        // No override configured yet: the code-defined preset figure stands, and
        // it is also what the carousel showed.
        $bare = $this->prepare($rep->id, ['plan_key' => SuchakDefaultPlans::KEY_PREMIUM]);
        $this->assertSame(
            '5000.00',
            SuchakServicePackage::query()->findOrFail($bare['service_package_id'])->price_amount,
        );

        // With an override the carousel prints the Suchak's own figure, so that
        // is what a send quoting nothing of its own must freeze — NOT the
        // hardcoded ₹2,000 the Basic preset carries in code.
        SuchakCustomerPlan::query()->create([
            'suchak_account_id' => $rep->suchakAccount->id,
            'preset_key' => SuchakDefaultPlans::KEY_BASIC,
            'price_amount' => '2500.00',
            'currency' => 'INR',
            'is_visible' => true,
            'sort_order' => 0,
        ]);

        $overridden = $this->prepare($rep->id, ['plan_key' => SuchakDefaultPlans::KEY_BASIC]);
        $this->assertSame(
            '2500.00',
            SuchakServicePackage::query()->findOrFail($overridden['service_package_id'])->price_amount,
        );
    }

    // ------------------------------------------------------------- re-sending

    public function test_a_resend_with_an_edited_fee_requotes_the_package_and_revises_the_pending_terms(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '500',
        ]);

        $second = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '999',
        ]);

        // Same package — a re-quote is not a new plan.
        $this->assertSame($first['service_package_id'], $second['service_package_id']);

        $package = SuchakServicePackage::query()->findOrFail($second['service_package_id']);
        $this->assertSame('999.00', $package->per_meeting_fee_amount);

        // ...but a different agreement: nobody had accepted, so the stale pending
        // revision is superseded by one digested from the re-quoted package.
        $this->assertNotSame($first['customer_agreement_id'], $second['customer_agreement_id']);

        $superseded = SuchakCustomerAgreement::query()->findOrFail($first['customer_agreement_id']);
        $this->assertSame(SuchakCustomerAgreement::TERMS_SUPERSEDED, $superseded->terms_status);

        $current = SuchakCustomerAgreement::query()->findOrFail($second['customer_agreement_id']);
        $this->assertSame(2, (int) $current->agreement_revision);
        $this->assertTrue(
            app(SuchakAgreementService::class)->isPackageSnapshotCurrent($current),
            'The fresh revision must describe the re-quoted package, or acceptance will refuse it.',
        );
    }

    public function test_a_resend_with_an_edited_price_behaves_exactly_like_an_edited_fee(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'price_amount' => '2000',
        ]);

        $second = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'price_amount' => '3000',
        ]);

        $this->assertSame($first['service_package_id'], $second['service_package_id']);
        $this->assertSame(
            '3000.00',
            SuchakServicePackage::query()->findOrFail($second['service_package_id'])->price_amount,
        );

        // Nobody had accepted, so the stale pending revision is superseded and
        // the fresh one carries the new price — the same path a changed fee takes.
        $this->assertNotSame($first['customer_agreement_id'], $second['customer_agreement_id']);

        $current = SuchakCustomerAgreement::query()->findOrFail($second['customer_agreement_id']);
        $this->assertSame('3000.00', $current->price_amount);
        $this->assertTrue(app(SuchakAgreementService::class)->isPackageSnapshotCurrent($current));
    }

    public function test_a_resend_may_not_requote_a_price_a_customer_has_already_accepted(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'price_amount' => '2000',
        ]);
        $this->acceptTerms($first['customer_agreement_id']);

        $response = $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'price_amount' => '3000',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'नोंदणी शुल्क: ₹2,000 → ₹3,000',
            (string) $response->json('message'),
        );

        // An accepted price is as frozen as an accepted fee. Both the package the
        // page reads and the agreement itself must be exactly as accepted.
        $this->assertSame(
            '2000.00',
            SuchakServicePackage::query()->findOrFail($first['service_package_id'])->price_amount,
        );

        $agreement = SuchakCustomerAgreement::query()->findOrFail($first['customer_agreement_id']);
        $this->assertSame('2000.00', $agreement->price_amount);
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $agreement->terms_status);
        $this->assertSame(1, (int) $agreement->agreement_revision);
    }

    public function test_a_resend_that_changes_nothing_reuses_the_accepted_agreement_untouched(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '500',
        ]);
        $this->acceptTerms($first['customer_agreement_id']);

        // Re-sharing the same quote to a customer who already accepted is an
        // ordinary thing to do and must keep working.
        $second = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '500',
        ]);

        $this->assertSame($first['service_package_id'], $second['service_package_id']);
        $this->assertSame($first['customer_agreement_id'], $second['customer_agreement_id']);
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $second['terms_status']);
    }

    public function test_a_resend_may_not_requote_terms_a_customer_has_already_accepted(): void
    {
        $rep = $this->bootSuchak();

        $first = $this->prepare($rep->id, [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '500',
        ]);
        $this->acceptTerms($first['customer_agreement_id']);

        // The acceptance page reads these fees live off the package, so editing
        // the package would rewrite a document a family has already signed.
        $response = $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", [
            'plan_key' => SuchakDefaultPlans::KEY_BASIC,
            'per_meeting_fee_amount' => '999',
        ])->assertStatus(422)->assertJsonPath('success', false);

        // Marathi, Latin digits, and both figures named — the Suchak is holding
        // a message quoting ₹999 and has to know what the customer agreed to.
        $message = (string) $response->json('message');
        $this->assertStringContainsString('प्रत्यक्ष भेटीचे शुल्क: ₹500 → ₹999', $message);

        $package = SuchakServicePackage::query()->findOrFail($first['service_package_id']);
        $this->assertSame('500.00', $package->per_meeting_fee_amount, 'Accepted terms must never be rewritten.');

        $agreement = SuchakCustomerAgreement::query()->findOrFail($first['customer_agreement_id']);
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $agreement->terms_status);
        $this->assertSame(1, (int) $agreement->agreement_revision);
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
     * Moves an agreement to TERMS_ACCEPTED through the real service, so the
     * "already accepted" guard is tested against the state production actually
     * reaches rather than a hand-written column value.
     */
    private function acceptTerms(int $agreementId): void
    {
        $agreement = SuchakCustomerAgreement::query()->findOrFail($agreementId);
        app(SuchakAgreementService::class)->acceptTerms(
            $agreement,
            $agreement->suchakAccount->user,
        );
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
