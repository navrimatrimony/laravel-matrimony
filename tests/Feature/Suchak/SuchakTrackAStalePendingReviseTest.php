<?php

namespace Tests\Feature\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerAgreement;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakAgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression: a customer whose latest Track A agreement is PENDING with a STALE
 * package snapshot must still be preparable + sendable. The prepare flow must
 * supersede the stale pending revision with a fresh one and accept THAT, instead
 * of failing with "Suchak package changed. Create a new agreement revision
 * before accepting terms." (the device-reproduced bug).
 */
class SuchakTrackAStalePendingReviseTest extends TestCase
{
    use RefreshDatabase;

    private function bootActor(): SuchakProfileRepresentation
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
            'full_name' => 'Stale Pending Candidate',
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

    public function test_stale_pending_agreement_is_revised_not_rejected_then_sends(): void
    {
        $rep = $this->bootActor();

        // First send prepares the package + an accepted rev 1 agreement.
        $first = $this->prepare($rep->id, ['plan_key' => 'premium']);
        $packageId = (int) $first['service_package_id'];
        $rev1Id = (int) $first['customer_agreement_id'];

        // Simulate the exact broken state the device hit: the customer's latest
        // agreement is left PENDING and its stored snapshot no longer matches the
        // current package (e.g. a prior send that never completed acceptance, and
        // the package definition since drifted). A raw update sets the state
        // deterministically and bypasses the model's post-acceptance immutability
        // guard — the happy-path API never leaves an agreement pending+stale.
        DB::table('suchak_customer_agreements')->where('id', $rev1Id)->update([
            'terms_status' => SuchakCustomerAgreement::TERMS_PENDING,
            'agreement_snapshot_hash' => 'stale-snapshot-hash',
        ]);

        // Sanity: the snapshot really is stale (so the old path WOULD have thrown).
        $agreementService = app(SuchakAgreementService::class);
        $rev1 = SuchakCustomerAgreement::query()->findOrFail($rev1Id);
        $this->assertFalse(
            $agreementService->isPackageSnapshotCurrent($rev1),
            'Pre-condition: the pending agreement snapshot must be stale.',
        );

        // Prepare again — the bug used to surface "Suchak package changed" here.
        $resp = $this->postJson("/api/v1/suchak/customers/{$rep->id}/payment-setup", ['plan_key' => 'premium']);
        $resp->assertCreated();
        $this->assertStringNotContainsString('Suchak package changed', $resp->getContent());

        $prepared = $resp->json('data');
        $this->assertSame($packageId, (int) $prepared['service_package_id'], 'Same customer + plan reuses the same package.');
        $this->assertSame(SuchakCustomerAgreement::TERMS_ACCEPTED, $prepared['terms_status']);

        // A fresh revision (rev 2) was created, accepted, and is the latest; the
        // stale rev 1 was superseded rather than accepted.
        $rev2 = SuchakCustomerAgreement::query()->findOrFail((int) $prepared['customer_agreement_id']);
        $this->assertNotSame($rev1Id, $rev2->id);
        $this->assertSame(2, (int) $rev2->agreement_revision);
        $this->assertSame($rev1Id, (int) $rev2->supersedes_agreement_id);
        $this->assertTrue($rev2->isTermsSatisfied());
        $this->assertTrue($agreementService->isPackageSnapshotCurrent($rev2), 'The fresh revision snapshot is current.');
        $this->assertSame(
            SuchakCustomerAgreement::TERMS_SUPERSEDED,
            $rev1->fresh()->terms_status,
            'The stale pending revision is superseded, not left dangling.',
        );

        // Send the just-prepared records — this path also asserts snapshot
        // currency (assertAgreementAllowsPaymentRequest), so a 201 confirms the
        // whole prepare->send round trip no longer fails with "package changed".
        $send = $this->postJson('/api/v1/suchak/payment-requests', [
            'service_package_id' => (int) $prepared['service_package_id'],
            'customer_agreement_id' => (int) $prepared['customer_agreement_id'],
            'payment_context_id' => (int) $prepared['payment_context_id'],
        ]);
        if ($send->status() !== 201) {
            fwrite(STDERR, "\n[SEND] status={$send->status()} body=".$send->getContent()."\n");
        }
        $send->assertCreated();
    }
}
