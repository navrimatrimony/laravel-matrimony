<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint section 7.4 / decision D25 — the success-fee split.
 *
 * The success fee itself is NOT re-declared here. It stays where it already lives:
 * suchak_service_packages.post_marriage_fee_mode + post_marriage_fee_amount (and its
 * frozen copy inside the agreement snapshot hash). This table only says how that ONE
 * figure is broken into tranches, so there is no second success-fee amount to drift.
 *
 * Rows hang off the CUSTOMER AGREEMENT, not off the package and not off an engagement:
 *
 *  - off the agreement, because the split "freezes with everything else" (7.4) — it is
 *    part of what the customer accepted, exactly like the package price and the two
 *    meeting fees, and it is digested into agreement_snapshot_hash for that reason.
 *  - NOT off an engagement (suchak_collaboration_requests), because of M9: when a
 *    settlement breaks and a DIFFERENT match succeeds later, the paid tranches stand
 *    and only the unpaid ones fire on the new match. A tranche ledger owned by the
 *    engagement would be thrown away with the broken engagement and the family would
 *    be exposed to the full fee a second time. Owned by the agreement, the ledger is
 *    per CUSTOMER and the total exposure stays at the one agreed figure however many
 *    settlements break.
 *
 * released_by_collaboration_request_id is therefore per ROW, not per table: blueprint
 * 7.4 requires attribution "per tranche, not per customer", so helper A's declared share
 * applies to the tranche A's work released and helper B's to B's.
 *
 * trigger_stage_key is constrained in PHP to App\Models\SuchakCollaborationStageEvent::STAGE_LADDER.
 * Free text is explicitly forbidden — a tranche that fires on a stage nobody can reach is
 * money nobody can claim.
 *
 * There is deliberately NO amount column. The rupee figure is derived once, in
 * SuchakSuccessFeeTrancheService::amounts(), from the frozen fee and these shares:
 * T1 (share is of the TOTAL) and T2 (the last tranche is the REMAINDER, so the parts sum
 * to the whole exactly) are arithmetic, and storing their output beside their inputs would
 * be a second home for the same fact and the first thing to go stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_success_fee_tranches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_agreement_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('trigger_stage_key', 64);
            // 5,2 holds 0.01 .. 100.00. The declared shares must sum to exactly 100.00 (T3).
            $table->decimal('share_percent', 5, 2);
            // Exactly one row per agreement, and it must be the last (T2).
            $table->boolean('is_final_tranche')->default(false);

            // --- M9 ledger state. Nullable everywhere: an unreleased tranche is the normal
            // resting state, and a plan that never fires must stay silent, not read as zero.
            $table->unsignedBigInteger('released_by_collaboration_request_id')->nullable();
            $table->unsignedBigInteger('released_by_stage_event_id')->nullable();
            $table->timestamp('released_at')->nullable();
            // The payment fact is owned by suchak_customer_payments; this is a pointer to it,
            // never a copy of its amount or its status.
            $table->unsignedBigInteger('customer_payment_id')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_agreement_id', 'trigger_stage_key'], 'suchak_fee_tranche_stage_unique');
            $table->unique(['customer_agreement_id', 'sort_order'], 'suchak_fee_tranche_order_unique');
            $table->index('trigger_stage_key', 'suchak_fee_tranche_stage_idx');
            $table->index('released_by_collaboration_request_id', 'suchak_fee_tranche_release_req_idx');
            $table->index('released_by_stage_event_id', 'suchak_fee_tranche_release_event_idx');
            $table->index('customer_payment_id', 'suchak_fee_tranche_payment_idx');
            $table->index(['customer_agreement_id', 'settled_at'], 'suchak_fee_tranche_unpaid_idx');

            $table->foreign('customer_agreement_id', 'suchak_fee_tranche_agreement_fk')
                ->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
            $table->foreign('released_by_collaboration_request_id', 'suchak_fee_tranche_release_req_fk')
                ->references('id')->on('suchak_collaboration_requests')->restrictOnDelete();
            $table->foreign('released_by_stage_event_id', 'suchak_fee_tranche_release_event_fk')
                ->references('id')->on('suchak_collaboration_stage_events')->restrictOnDelete();
            $table->foreign('customer_payment_id', 'suchak_fee_tranche_payment_fk')
                ->references('id')->on('suchak_customer_payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_success_fee_tranches');
    }
};
