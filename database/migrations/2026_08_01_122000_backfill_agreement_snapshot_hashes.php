<?php

use App\Models\SuchakCustomerAgreement;
use App\Modules\Suchak\Services\SuchakAgreementService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-digest every stored agreement snapshot now that the per-meeting and
 * post-marriage fee terms are part of the hashed package payload.
 *
 * Those three package columns are NULL on every row that exists today, so no
 * package fact actually changed — only the shape of the digest did. Without this
 * pass each stored hash would still be the old, fee-less one and every existing
 * agreement would read as "package changed": accepted terms would be treated as
 * stale and payment requests would be refused for agreements nobody touched.
 *
 * The new hash is produced by calling the agreement engine itself instead of
 * rebuilding the payload here. A copied payload would be free to drift from the
 * engine on the next change to either side, which is exactly the class of bug
 * this migration is repairing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suchak_customer_agreements')
            || ! Schema::hasTable('suchak_service_packages')
            || ! Schema::hasColumn('suchak_customer_agreements', 'agreement_snapshot_hash')) {
            return;
        }

        $agreementService = app(SuchakAgreementService::class);

        SuchakCustomerAgreement::query()
            ->with('servicePackage')
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
                    );

                    if (hash_equals((string) $agreement->agreement_snapshot_hash, $hash)) {
                        continue;
                    }

                    // Written straight to the column: re-digesting is a repair, not a
                    // business event, and it must not bump updated_at or fire the
                    // agreement's model hooks.
                    DB::table('suchak_customer_agreements')
                        ->where('id', $agreement->id)
                        ->update(['agreement_snapshot_hash' => $hash]);
                }
            });
    }

    public function down(): void
    {
        // Forward-only. The old digests were computed from a payload that no longer
        // exists, so restoring them would re-freeze the very agreements this pass
        // unblocked — and nothing about the packages themselves changed.
    }
};
