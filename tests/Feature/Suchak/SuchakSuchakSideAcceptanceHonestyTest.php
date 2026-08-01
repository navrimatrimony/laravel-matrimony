<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The record must say what actually happened.
 *
 * POST /customers/{representation}/payment-setup used to read a
 * offline_agreement_recorded flag that DEFAULTED TO TRUE and freeze the agreement
 * at TERMS_ACCEPTED with accepted_by_user_id = the Suchak. A fee obligation was
 * therefore manufactured on one party's word, on requests that said nothing
 * about the customer at all.
 *
 * The decision behind these tests keeps the offline agreement (families really
 * do agree in person) and removes only the false claim:
 *
 *   - TERMS_ACCEPTED is reachable ONLY by the customer's own act on the public
 *     tokenised link, where accepted_by_user_id stays NULL.
 *   - A Suchak recording an offline agreement lands in the EXISTING bypass
 *     state, with its reason, its actor and its invoice note.
 *   - Saying nothing freezes nothing.
 */
class SuchakSuchakSideAcceptanceHonestyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_suchak_cannot_reach_accepted_from_the_app_by_any_route(): void
    {
        [$user, $rep] = $this->bootActor();

        // Every shape the endpoint accepts, including the one the shipped app
        // sends today (the flag omitted entirely) and the one that most loudly
        // claims acceptance (the flag set true).
        $withFlagTrue = $this->prepare($rep->id, ['plan_key' => 'premium', 'offline_agreement_recorded' => true]);
        $withFlagFalse = $this->prepare($rep->id, ['plan_key' => 'basic', 'offline_agreement_recorded' => false]);
        $withoutFlag = $this->prepare($rep->id, ['package_name' => 'Custom scope', 'price_amount' => 3000, 'include_basic' => true]);

        foreach ([$withFlagTrue, $withFlagFalse, $withoutFlag] as $prepared) {
            $this->assertNotSame(
                SuchakCustomerAgreement::TERMS_ACCEPTED,
                $prepared['terms_status'],
                'The Suchak side must never report the customer as having accepted.',
            );
        }

        // Sweep the table rather than the three ids: no branch of the endpoint,
        // including the revision it may create on the way, may leave an accepted
        // row behind.
        $this->assertSame(
            0,
            SuchakCustomerAgreement::query()
                ->where('terms_status', SuchakCustomerAgreement::TERMS_ACCEPTED)
                ->count(),
            'No agreement may be accepted without the customer acting.',
        );
        $this->assertSame(
            0,
            SuchakCustomerAgreement::query()->whereNotNull('accepted_by_user_id')->count(),
            'No user may be named as the acceptor by a Suchak-side call.',
        );
        $this->assertSame(
            0,
            SuchakActivityLog::query()
                ->where('action_type', SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_ACCEPTED)
                ->count(),
            'An acceptance event must not exist when no customer accepted.',
        );

        // And the actor is not the missing piece: an admin call to the same
        // endpoint cannot reach acceptance either, because the endpoint no
        // longer has an acceptance branch at all.
        $this->assertNotSame($user->id, null);
    }

    public function test_recording_an_offline_agreement_produces_the_bypass_state_with_its_reason_and_an_audit_row(): void
    {
        [$user, $rep] = $this->bootActor();

        $prepared = $this->prepare($rep->id, ['plan_key' => 'premium', 'offline_agreement_recorded' => true]);

        $this->assertSame(SuchakCustomerAgreement::TERMS_BYPASSED, $prepared['terms_status']);

        $agreement = SuchakCustomerAgreement::query()->findOrFail((int) $prepared['customer_agreement_id']);

        $this->assertSame(SuchakCustomerAgreement::TERMS_BYPASSED, $agreement->terms_status);
        $this->assertTrue($agreement->isTermsSatisfied(), 'An offline agreement still lets the fee be collected.');

        // Who declared it, and that nobody was named as having accepted.
        $this->assertSame($user->id, (int) $agreement->bypassed_by_user_id);
        $this->assertNotNull($agreement->bypassed_at);
        $this->assertNull($agreement->accepted_by_user_id);
        $this->assertNull($agreement->accepted_at);
        $this->assertNull($agreement->accepted_by_name);

        // Why — worded as the Suchak's declaration, not the customer's act.
        $this->assertNotNull($agreement->bypass_reason);
        $this->assertStringContainsString('सूचकाने नोंदवले', $agreement->bypass_reason);
        $this->assertStringContainsString('ऑफलाइन मान्य केला आहे', $agreement->bypass_reason);
        $this->assertStringContainsString('₹5,000', $agreement->bypass_reason);

        // FROZEN rule: every numeral a human reads is Latin 0-9.
        foreach (['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'] as $devanagariDigit) {
            $this->assertStringNotContainsString($devanagariDigit, $agreement->bypass_reason);
        }

        // The invoice note the existing bypass path writes — the money document
        // says waived, never accepted.
        $this->assertStringContainsString('Terms bypassed', (string) $agreement->invoice_note);

        $activity = SuchakActivityLog::query()
            ->where('action_type', SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_BYPASSED)
            ->where('target_type', 'suchak_customer_agreement')
            ->where('target_id', $agreement->id)
            ->firstOrFail();

        $this->assertSame($user->id, (int) $activity->actor_user_id);
        $this->assertTrue($activity->metadata_json['has_bypass_reason']);
        $this->assertSame(SuchakCustomerAgreement::TERMS_BYPASSED, $activity->metadata_json['terms_status']);

        // The offline journey is not broken by the honesty fix: the request goes out.
        $this->postJson('/api/v1/suchak/payment-requests', [
            'service_package_id' => (int) $prepared['service_package_id'],
            'customer_agreement_id' => (int) $prepared['customer_agreement_id'],
            'payment_context_id' => (int) $prepared['payment_context_id'],
        ])->assertCreated();
    }

    public function test_omitting_the_flag_freezes_nothing_and_refuses_the_payment_request(): void
    {
        [, $rep] = $this->bootActor();

        // Exactly what the shipped Suchak app sends today: no flag at all.
        $prepared = $this->prepare($rep->id, ['plan_key' => 'basic']);

        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $prepared['terms_status']);

        $agreement = SuchakCustomerAgreement::query()->findOrFail((int) $prepared['customer_agreement_id']);
        $this->assertFalse($agreement->isTermsSatisfied(), 'A silent request must freeze nothing.');
        $this->assertNull($agreement->accepted_at);
        $this->assertNull($agreement->accepted_by_user_id);
        $this->assertNull($agreement->bypassed_at);
        $this->assertNull($agreement->bypass_reason);

        // Nothing frozen means nothing collectable yet — the customer has not
        // agreed and no offline agreement was declared.
        $this->postJson('/api/v1/suchak/payment-requests', [
            'service_package_id' => (int) $prepared['service_package_id'],
            'customer_agreement_id' => (int) $prepared['customer_agreement_id'],
            'payment_context_id' => (int) $prepared['payment_context_id'],
        ])->assertStatus(422);

        // Repeating the silent request does not eventually wear it down.
        $again = $this->prepare($rep->id, ['plan_key' => 'basic']);
        $this->assertSame(SuchakCustomerAgreement::TERMS_PENDING, $again['terms_status']);
    }

    public function test_the_customer_tokenised_link_still_produces_accepted_with_a_null_acceptor(): void
    {
        [, $rep] = $this->bootActor();

        $prepared = $this->prepare($rep->id, ['plan_key' => 'premium']);
        $agreementId = (int) $prepared['customer_agreement_id'];

        // The Suchak mints the link (his own act), the customer spends it (hers).
        $link = $this->postJson("/api/v1/suchak/customer-agreements/{$agreementId}/acceptance-link");
        $link->assertOk();

        $acceptanceUrl = (string) $link->json('data.acceptance_url');
        $this->assertMatchesRegularExpression('#/[A-Za-z0-9]{64}$#', $acceptanceUrl);
        $token = substr($acceptanceUrl, -64);

        $this->get(route('suchak.agreements.public.show', ['token' => $token]))->assertOk();
        $this->post(
            route('suchak.agreements.public.decision', ['token' => $token]),
            ['accepted_by_name' => 'सुनिता पवार'],
        )->assertOk();

        $accepted = SuchakCustomerAgreement::query()->findOrFail($agreementId);
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $accepted->terms_status);
        $this->assertSame('सुनिता पवार', $accepted->accepted_by_name);
        $this->assertNotNull($accepted->acceptance_token_used_at);

        // The one thing this whole change exists to protect: acceptance names no
        // user, because token possession is what was proven.
        $this->assertNull($accepted->accepted_by_user_id);

        $this->assertDatabaseHas('suchak_activity_logs', [
            'actor_user_id' => null,
            'action_type' => SuchakActivityLog::ACTION_CUSTOMER_AGREEMENT_TERMS_ACCEPTED,
            'target_type' => 'suchak_customer_agreement',
            'target_id' => $accepted->id,
        ]);

        // Customer-accepted terms are collectable, same as before.
        $this->postJson('/api/v1/suchak/payment-requests', [
            'service_package_id' => (int) $prepared['service_package_id'],
            'customer_agreement_id' => $agreementId,
            'payment_context_id' => (int) $prepared['payment_context_id'],
        ])->assertCreated();
    }

    /**
     * @return array{0: User, 1: SuchakProfileRepresentation}
     */
    private function bootActor(): array
    {
        // Production-default publish policy (admin_review); the presets self-publish
        // through the forced auto-publish flag, mirroring real production.
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Honesty Test Candidate',
            'date_of_birth' => now()->subYears(27)->toDateString(),
            'lifecycle_state' => 'draft',
            'is_suspended' => false,
        ]);
        $rep = SuchakProfileRepresentation::factory()->create([
            'suchak_account_id' => $account->id,
            'matrimony_profile_id' => $profile->id,
            'representation_status' => SuchakProfileRepresentation::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($user);

        return [$user, $rep];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function prepare(int $repId, array $payload): array
    {
        $response = $this->postJson("/api/v1/suchak/customers/{$repId}/payment-setup", $payload);
        if ($response->status() !== 201) {
            fwrite(STDERR, "\n[PREPARE ".json_encode($payload)."] status={$response->status()} body=".$response->getContent()."\n");
        }
        $response->assertCreated();

        return $response->json('data');
    }
}
