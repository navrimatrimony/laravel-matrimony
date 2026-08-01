<?php

use App\Models\SuchakVisitConfirmation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint section 5.1, blocker B1 + B3 — phase 1a.
 *
 * `unique(pipeline_id)` meant ONE meeting per pair, forever. That is not only a
 * "more than one meeting" defect: D24 charges an arranged re-visit at the same
 * rate as a first visit, and `meeting_sequence > 1` is exactly what marks a
 * charge as a re-visit — so without this migration the re-visit fee cannot
 * exist at all.
 *
 * The replacement guarantee is `unique(pipeline_id, meeting_sequence)`. Two
 * concurrent schedules can no longer both become meeting 2; the second one is
 * refused by the database, not by hopeful application code. The plain index on
 * `pipeline_id` is added BEFORE the unique is dropped because MySQL will not
 * drop the last index a foreign key can use.
 *
 * Three columns, each a fact about ONE meeting rather than about the relationship:
 *
 *  - meeting_sequence — 1, 2, 3... within a pipeline.
 *  - meeting_mode     — offline | online. The two per-meeting rates are fully
 *                       independent amounts (D2), so which one applied has to be
 *                       recorded, not inferred.
 *  - fee_amount       — what THIS meeting cost, frozen when it was scheduled.
 *                       NOT a duplicate of the rates: `suchak_service_packages`
 *                       .per_meeting_fee_amount / .per_meeting_online_fee_amount
 *                       are the RATE on the customer's accepted agreement — one
 *                       figure describing the whole relationship. A rate change
 *                       is a new agreement revision (section 4), and a meeting
 *                       already held must not silently re-price when that
 *                       happens. Rate -> agreement -> this meeting are three
 *                       homes for three different questions.
 *  - fee_currency     — the UNIT of that frozen figure. A quote that freezes its
 *                       number but not its unit is not frozen: the agreement it
 *                       came from can later be superseded by a revision in a
 *                       different currency, and a caller re-deriving "the
 *                       currency is the agreement's" would then render a USD
 *                       meeting as rupees. Not a duplicate of
 *                       `suchak_customer_agreements.currency` for the same
 *                       reason `fee_amount` is not a duplicate of the rate: that
 *                       column is the currency the RELATIONSHIP is priced in
 *                       today, this one is the currency THIS meeting was quoted
 *                       in. Null exactly when `fee_amount` is null — the
 *                       currency of nothing is nothing, never a defaulted INR.
 *  - customer_agreement_id — WHICH agreement asserted that figure. Without it
 *                       the frozen fee is an unattributable number: the meeting
 *                       hangs off `payment_context_id`, and
 *                       `suchak_payment_contexts` carries no `service_package_id`
 *                       and no `customer_agreement_id`, so there was no way to
 *                       say which of a customer's plans priced this meeting. The
 *                       one table that does tie a payment context to an
 *                       agreement — `suchak_payment_requests` — is structurally
 *                       unreachable here: `SuchakPaymentRequestService` runs
 *                       `assertAllowsDirectSuchakCollection()`, which REFUSES a
 *                       platform-owned platform-collected context, and that is
 *                       precisely the only kind of context a visit payout
 *                       accepts (`assertPlatformPaymentContext()`). So the link
 *                       did not exist and is created here, on the row that needs
 *                       it. Nullable: an unpriced meeting has no agreement to
 *                       name.
 *  - helper_suchak_account_id — in a marketplace meeting the candidate belongs
 *                       to another Suchak. `suchak_account_id` already names the
 *                       Suchak who ARRANGED the meeting; nothing on this table
 *                       names whose candidate was met. It is not derivable
 *                       today: a visit hangs off `suchak_pipelines` (the
 *                       member -> Suchak request pipeline) and has no link to
 *                       `suchak_collaboration_requests`, which is where the two
 *                       account ids of an engagement live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->unsignedInteger('meeting_sequence')
                ->default(1)
                ->after('confirmation_policy_mode');
            $table->string('meeting_mode', 16)
                ->default(SuchakVisitConfirmation::MODE_OFFLINE)
                ->after('meeting_sequence');
            $table->decimal('fee_amount', 12, 2)
                ->nullable()
                ->after('meeting_mode');
            $table->string('fee_currency', 3)
                ->nullable()
                ->after('fee_amount');
            $table->unsignedBigInteger('customer_agreement_id')
                ->nullable()
                ->after('customer_context_id');
            $table->unsignedBigInteger('helper_suchak_account_id')
                ->nullable()
                ->after('suchak_account_id');
        });

        // Every row that exists today is its pair's first and only meeting.
        DB::table('suchak_visit_confirmations')->update(['meeting_sequence' => 1]);

        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            // Added before the unique is dropped: the pipeline foreign key needs
            // an index to stand on, and MySQL refuses to leave it without one.
            $table->index('pipeline_id', 'sk_visit_confirmations_pipeline_idx');
            $table->index('meeting_mode', 'sk_visit_confirmations_mode_idx');
            $table->index('helper_suchak_account_id', 'sk_visit_confirmations_helper_idx');
            $table->index('customer_agreement_id', 'sk_visit_confirmations_agreement_idx');
            $table->foreign('helper_suchak_account_id', 'sk_visit_confirmations_helper_fk')
                ->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('customer_agreement_id', 'sk_visit_confirmations_agreement_fk')
                ->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
        });

        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->dropUnique('sk_visit_confirmations_pipeline_unique');
        });

        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->unique(['pipeline_id', 'meeting_sequence'], 'sk_visit_confirmations_pipeline_seq_unique');
        });
    }

    /**
     * Restores the original schema exactly, which means restoring
     * `unique(pipeline_id)`. If a pipeline has by then recorded more than one
     * meeting, this will fail — and that failure is the honest statement of what
     * the old index meant. Reduce the pipeline to one meeting first, or do not
     * roll this back.
     */
    public function down(): void
    {
        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->dropUnique('sk_visit_confirmations_pipeline_seq_unique');
        });

        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->unique('pipeline_id', 'sk_visit_confirmations_pipeline_unique');
        });

        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->dropForeign('sk_visit_confirmations_agreement_fk');
            $table->dropForeign('sk_visit_confirmations_helper_fk');
            $table->dropIndex('sk_visit_confirmations_agreement_idx');
            $table->dropIndex('sk_visit_confirmations_helper_idx');
            $table->dropIndex('sk_visit_confirmations_mode_idx');
            $table->dropIndex('sk_visit_confirmations_pipeline_idx');
        });

        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->dropColumn([
                'helper_suchak_account_id',
                'customer_agreement_id',
                'fee_currency',
                'fee_amount',
                'meeting_mode',
                'meeting_sequence',
            ]);
        });
    }
};
