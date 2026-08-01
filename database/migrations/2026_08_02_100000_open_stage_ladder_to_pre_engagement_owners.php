<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint section 6a — the stage ladder could not record its own first
 * four rungs.
 *
 * `2026_08_01_161000` created `suchak_collaboration_stage_events` with `collaboration_request_id`
 * NOT NULL. Four ladder stages are PRE-ENGAGEMENT facts and therefore had no owner to hang off:
 *
 *   registration -> agreement_proposed -> agreement_accepted -> published_to_marketplace
 *
 * The sharp one is `published_to_marketplace`. Publication is the act that INVITES a counterparty,
 * so by definition no collaboration request exists at that moment — the row could not be written
 * even in principle. Section 6a calls this ladder "the one spine analytics, reputation,
 * installments and dispute resolution all read from", and a spine missing its first four vertebrae
 * is not a spine.
 *
 * ── THE SHAPE, AND WHY ────────────────────────────────────────────────────────────────────────
 *
 * Two nullable owner columns, exactly one of which is set, guarded in
 * `SuchakCollaborationStageEvent` (a portable CHECK is not available: production is MySQL and the
 * test suite is SQLite, and Laravel's schema builder has no CHECK verb — so the invariant lives in
 * one model hook that every writer passes through, and is pinned by a test):
 *
 *   collaboration_request_id  — the ENGAGEMENT (blueprint 6.1). Owns every stage from
 *                               `profile_suggested` onward: those stages are facts about one
 *                               helper's one proposal.
 *   customer_agreement_id     — the CUSTOMER AGREEMENT REVISION. Owns the four pre-engagement
 *                               stages.
 *
 * The customer agreement is the pre-engagement owner rather than the challenge object because
 * section 4 already decided it: *"Publication attaches to whichever agreement is accepted at that
 * moment."* The challenge declares a share OF these terms (D4), a rate change is a NEW agreement
 * row and never an edit (section 4), and A8 requires the declared share to stick to what was
 * published under it — all three are statements about the agreement revision, which is exactly
 * what this column names.
 *
 * It is also the only owner that EXISTS. `suchak_marketplace_challenges` is Phase 2 and is not
 * built yet, so a `challenge_id` column added here would be a column with no table to point at, no
 * foreign key and no writer — the precise defect this repository keeps shipping. When the challenge
 * lands it becomes a third owner at the cost of one entry in
 * `SuchakCollaborationStageEvent::OWNER_COLUMNS` plus the column and FK in its own migration, and
 * the guard, the writers and the unique indexes below all extend with it unchanged.
 *
 * A polymorphic (`owner_type`, `owner_id`) pair was rejected for one reason: it cannot carry a
 * foreign key. "A stage event must never belong to nothing" is enforced here by the database for
 * the row's existence and by the model for the exactly-one rule; a morph pair would move both
 * halves into application code and let `owner_id = 999999` through.
 *
 * ── THE UNIQUE INDEXES ────────────────────────────────────────────────────────────────────────
 *
 * `unique(collaboration_request_id, stage_key)` survives untouched and gains a sibling,
 * `unique(customer_agreement_id, stage_key)`. Both are NULL-tolerant in MySQL and SQLite alike
 * (NULLs compare distinct in a unique index), so a row owned by the agreement is not policed by the
 * engagement index and vice versa — which is what makes one table with two owners safe.
 *
 * Grain, stated plainly so nobody re-derives it wrongly later: `published_to_marketplace` is
 * recordable once per AGREEMENT REVISION. Re-publishing at the same rate cannot be counted twice
 * here; that count is the challenge object's job (A12, "times published"). Re-publishing at a new
 * rate is a new agreement row under section 4, so it is a new stage row automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nullable FIRST, on the table as it stands, so the SQLite table rebuild that backs
        // ->change() only has to carry the original shape across.
        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('collaboration_request_id')->nullable()->change();
        });

        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_agreement_id')
                ->nullable()
                ->after('collaboration_request_id');
        });

        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            // Leftmost column is customer_agreement_id, so the foreign key below can stand on it.
            $table->unique(['customer_agreement_id', 'stage_key'], 'suchak_collab_stage_agr_key_unique');
            $table->index(['customer_agreement_id', 'claimed_at'], 'suchak_collab_stage_agr_timeline_idx');
            $table->foreign('customer_agreement_id', 'suchak_collab_stage_agreement_fk')
                ->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
        });
    }

    /**
     * Restores `collaboration_request_id` NOT NULL, which is only possible while no pre-engagement
     * stage has been recorded. If one has, this throws instead of deleting it: a ladder row is
     * evidence in a dispute a year later, and silently dropping the four stages that this migration
     * exists to make recordable would be the worst possible way to roll it back. Delete them
     * deliberately first, or do not roll this back.
     */
    public function down(): void
    {
        $orphanCount = DB::table('suchak_collaboration_stage_events')
            ->whereNull('collaboration_request_id')
            ->count();

        if ($orphanCount > 0) {
            throw new RuntimeException(
                'Cannot roll back: '.$orphanCount.' pre-engagement stage event(s) are owned by a customer '
                .'agreement and would lose their owner. Remove them deliberately before rolling back.'
            );
        }

        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->dropForeign('suchak_collab_stage_agreement_fk');
            $table->dropUnique('suchak_collab_stage_agr_key_unique');
            $table->dropIndex('suchak_collab_stage_agr_timeline_idx');
        });

        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->dropColumn('customer_agreement_id');
        });

        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('collaboration_request_id')->nullable(false)->change();
        });
    }
};
