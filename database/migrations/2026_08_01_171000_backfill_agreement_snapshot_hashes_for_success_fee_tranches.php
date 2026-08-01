<?php

use App\Models\SuchakCustomerAgreement;
use App\Modules\Suchak\Services\SuchakAgreementService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-digest every stored agreement snapshot now that the success-fee split (blueprint 7.4)
 * is part of the hashed payload.
 *
 * A third pass rather than an edit to 2026_08_01_122000 or 2026_08_01_132000: both have
 * already run on production, so amending either would be a no-op there while silently
 * diverging from what production actually executed.
 *
 * Every agreement that exists today has ZERO tranche rows — the table is created one
 * migration earlier — so no agreed term changed here; only the shape of the digest did.
 * Left unrepaired, every stored hash would stay the old, shorter one and each existing
 * agreement would read as "package changed": accepted terms would be treated as stale and
 * payment requests refused for agreements nobody touched.
 *
 * The new hash comes from the agreement engine itself for the same reason as the previous
 * two passes — a payload copied into this file would be free to drift from the engine on the
 * next change to either side, which is the exact bug class this migration exists to repair.
 * The tranche argument is left at its default because the engine reads the agreement's own
 * (empty) tranche set through the same relation the runtime check uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suchak_customer_agreements')
            || ! Schema::hasTable('suchak_service_packages')
            || ! Schema::hasTable('suchak_success_fee_tranches')
            || ! Schema::hasColumn('suchak_customer_agreements', 'agreement_snapshot_hash')) {
            return;
        }

        $agreementService = app(SuchakAgreementService::class);

        SuchakCustomerAgreement::query()
            ->with(['servicePackage', 'successFeeTranches'])
            ->chunkById(200, function (Collection $agreements) use ($agreementService): void {
                foreach ($agreements as $agreement) {
                    $package = $agreement->servicePackage;
                    if ($package === null) {
                        continue;
                    }

                    $hash = $agreementService->agreementSnapshotHash(
                        $package,
                        (string) $agreement->terms_policy_mode,
                        (string) $agreement->agreement_title,
                        $agreement->agreement_body,
                        $agreement->successFeeTranches->all(),
                    );

                    if (hash_equals((string) $agreement->agreement_snapshot_hash, $hash)) {
                        continue;
                    }

                    // Written straight to the column: re-digesting is a repair, not a business
                    // event, and it must not bump updated_at or fire the agreement's model
                    // hooks — the agreement is immutable after acceptance and save() would throw.
                    DB::table('suchak_customer_agreements')
                        ->where('id', $agreement->id)
                        ->update(['agreement_snapshot_hash' => $hash]);
                }
            });
    }

    public function down(): void
    {
        // Forward-only. The old digests were computed from a payload that no longer exists, so
        // restoring them would re-freeze the very agreements this pass unblocked — and nothing
        // about the packages or their tranches themselves changed.
    }
};
