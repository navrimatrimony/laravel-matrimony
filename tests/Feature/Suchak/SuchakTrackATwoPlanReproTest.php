<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakPolicy;
use App\Models\SuchakServicePackage;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuchakTrackATwoPlanReproTest extends TestCase
{
    use RefreshDatabase;

    private function bootActor(): SuchakProfileRepresentation
    {
        // Intentionally DO NOT change the publish-approval policy: keep the
        // production default (admin_review). Presets/custom plans self-publish
        // via the forced auto-publish flag, so this mirrors real production.
        $user = User::factory()->create();
        $account = SuchakAccount::factory()->create([
            'user_id' => $user->id,
            'verification_status' => SuchakAccount::VERIFICATION_VERIFIED,
            'public_status' => SuchakAccount::PUBLIC_ACTIVE,
            'verified_at' => now(),
            'registration_completed_at' => now(),
        ]);
        $profile = MatrimonyProfile::factory()->create([
            'full_name' => 'Repro Candidate',
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

        return $rep;
    }

    private function prepare(int $repId, array $payload): array
    {
        $resp = $this->postJson("/api/v1/suchak/customers/{$repId}/payment-setup", $payload);
        if ($resp->status() !== 201) {
            fwrite(STDERR, "\n[PREPARE ".json_encode($payload)."] status={$resp->status()} body=".$resp->getContent()."\n");
        }
        $resp->assertCreated();

        return $resp->json('data');
    }

    private function request(array $data, string $tag): void
    {
        $resp = $this->postJson('/api/v1/suchak/payment-requests', [
            'service_package_id' => $data['service_package_id'],
            'customer_agreement_id' => $data['customer_agreement_id'],
            'payment_context_id' => $data['payment_context_id'],
        ]);
        if ($resp->status() !== 201) {
            fwrite(STDERR, "\n[REQUEST {$tag}] status={$resp->status()} body=".$resp->getContent()."\n");
        }
        $resp->assertCreated();
    }

    public function test_scenario_A_prepare_two_then_request_second(): void
    {
        $rep = $this->bootActor();
        $premium = $this->prepare($rep->id, ['plan_key' => 'premium']);
        $basic = $this->prepare($rep->id, ['plan_key' => 'basic']);
        $this->request($basic, 'A-basic');
    }

    public function test_scenario_B_prepare_premium_send_then_prepare_basic_send(): void
    {
        $rep = $this->bootActor();
        $premium = $this->prepare($rep->id, ['plan_key' => 'premium']);
        $this->request($premium, 'B-premium');
        $basic = $this->prepare($rep->id, ['plan_key' => 'basic']);
        $this->request($basic, 'B-basic');
    }

    public function test_scenario_C_reverse_order(): void
    {
        $rep = $this->bootActor();
        $basic = $this->prepare($rep->id, ['plan_key' => 'basic']);
        $premium = $this->prepare($rep->id, ['plan_key' => 'premium']);
        $this->request($premium, 'C-premium');
    }

    public function test_scenario_D_same_plan_twice_then_request(): void
    {
        $rep = $this->bootActor();
        $first = $this->prepare($rep->id, ['plan_key' => 'premium']);
        $second = $this->prepare($rep->id, ['plan_key' => 'premium']);
        $this->assertSame($first['service_package_id'], $second['service_package_id']);
        $this->request($second, 'D-premium');
    }

    public function test_scenario_E_preset_then_custom(): void
    {
        $rep = $this->bootActor();
        $premium = $this->prepare($rep->id, ['plan_key' => 'premium']);
        $custom = $this->prepare($rep->id, [
            'package_name' => 'Custom coordination',
            'services' => ['Horoscope match', 'Venue shortlist'],
        ]);
        $this->request($custom, 'E-custom');
    }

    public function test_scenario_F_two_customs_default_name(): void
    {
        $rep = $this->bootActor();
        $c1 = $this->prepare($rep->id, ['services' => ['Service one']]);
        $c2 = $this->prepare($rep->id, ['services' => ['Service two']]);
        $this->request($c2, 'F-custom2');
    }

    /**
     * Fully mimic the Suchak app: prepare premium (bind pkg id from prepare
     * response), reload options, derive agreement/context from OPTIONS (as the
     * client's _firstAgreementFor does), back out, prepare basic, reload, derive
     * again, then send the SECOND plan using OPTIONS-derived ids.
     */
    public function test_scenario_G_client_faithful_second_plan_send(): void
    {
        $rep = $this->bootActor();

        // Stage 1: prepare premium, then the client derives ids from options.
        $premiumPrepared = $this->prepare($rep->id, ['plan_key' => 'premium']);
        $ids1 = $this->clientDeriveIds($rep->id, (int) $premiumPrepared['service_package_id']);
        $this->assertNotNull($ids1['agreement'], 'premium agreement resolvable from options');

        // Stage 2 (user backs out, picks basic): prepare basic, re-derive.
        $basicPrepared = $this->prepare($rep->id, ['plan_key' => 'basic']);
        $ids2 = $this->clientDeriveIds($rep->id, (int) $basicPrepared['service_package_id']);
        $this->assertNotNull($ids2['agreement'], 'basic agreement resolvable from options');
        $this->assertNotSame($ids1['package'], $ids2['package']);
        $this->assertNotSame($ids1['agreement'], $ids2['agreement']);
        $this->assertSame($ids1['context'], $ids2['context']);

        // Send the SECOND plan exactly as the client's _submit() does.
        $resp = $this->postJson('/api/v1/suchak/payment-requests', [
            'service_package_id' => $ids2['package'],
            'customer_agreement_id' => $ids2['agreement'],
            'payment_context_id' => $ids2['context'],
        ]);
        if ($resp->status() !== 201) {
            fwrite(STDERR, "\n[G-send] status={$resp->status()} body=".$resp->getContent()."\n");
        }
        $resp->assertCreated();
    }

    /**
     * Replicates the Flutter screen's id resolution after a prepare+reload:
     * _packageId = prepared id, _agreementId = first options agreement whose
     * service_package_id == _packageId, _contextId = first options context.
     *
     * @return array{package:int, agreement:?int, context:?int}
     */
    private function clientDeriveIds(int $repId, int $preparedPackageId): array
    {
        $opts = $this->getJson("/api/v1/suchak/customers/{$repId}/payment-request-options");
        $opts->assertOk();
        $data = $opts->json('data');

        $agreementId = null;
        foreach (($data['customer_agreements'] ?? []) as $a) {
            if ((int) $a['service_package_id'] === $preparedPackageId) {
                $agreementId = (int) $a['id'];
                break;
            }
        }
        $contexts = $data['payment_contexts'] ?? [];
        $contextId = $contexts === [] ? null : (int) $contexts[0]['id'];

        return ['package' => $preparedPackageId, 'agreement' => $agreementId, 'context' => $contextId];
    }
}
