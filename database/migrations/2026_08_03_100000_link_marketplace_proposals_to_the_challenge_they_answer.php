<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint D7 / D7a / section 6.1 / section 11 phase 2 —
 * ACCEPT-BY-PROPOSING.
 *
 * The engagement is NOT a new object. `suchak_collaboration_requests` +
 * `suchak_commission_agreements` already are it (section 6.1, corrected 2026-08-01), and a
 * marketplace proposal is that same pair written in the REVERSED direction: the Suchak answering a
 * challenge becomes the `requester`, his candidate is `requestingRepresentation`, and the
 * challenge's candidate is `targetRepresentation`. So this migration adds one nullable pointer and
 * one uniqueness rule, and nothing else.
 *
 * ── WHY THE COLUMN EXISTS AT ALL ──────────────────────────────────────────────────────────────
 *
 * 2026_08_02_200000 stated plainly why it was NOT added then: *"its only writer is
 * accept-by-proposing, which is the NEXT slice, and a nullable FK with no writer is the exact
 * defect this repository keeps shipping."* That slice is this one, and the column now has exactly
 * one writer — SuchakCollaborationService::createRequest() when a challenge is supplied.
 *
 * Three facts need it and none of them can be derived:
 *
 *  1. THE FROZEN SHARE (D4). The declared share is the CHALLENGE's, frozen onto the commission
 *     agreement at proposal time and never typed by the helper. Which declaration a live engagement
 *     was formed under is a fact about the past; a republished challenge at a different rate must
 *     not be able to re-price it (A8).
 *  2. FULFILMENT. `SuchakMarketplaceChallenge::STATUS_FULFILLED` shipped with no writer, and its
 *     own docblock named the honest moment: when a proposal made against the challenge is accepted.
 *     Without this pointer, acceptance cannot find the challenge it closes.
 *  3. THE D4 GUARD. `updateCommissionTerms()` is requester-only, and the marketplace requester is
 *     the HELPER — the one party D4 says may never move the split. The refusal reads this column.
 *
 * NULL means "not a marketplace engagement": the direct cross-Suchak collaboration path predates
 * the marketplace and is untouched by every rule above.
 *
 * ── THE UNIQUE INDEX IS A2/A10, NOT AN OPTIMISATION ───────────────────────────────────────────
 *
 * "A Suchak must not propose the same candidate to the same challenge twice." The service refuses
 * it under a row lock with a Marathi sentence, and this index is the same rule as a database
 * guarantee, so a second entrance can never reintroduce it.
 *
 * It is a PAIR containing a nullable column on purpose: both MySQL and SQLite treat rows whose
 * indexed tuple contains a NULL as distinct, so every non-marketplace collaboration request — all
 * of which carry NULL here — is exempt without a partial index, which neither engine offers
 * portably. Nothing about the existing direct path changes.
 *
 * The rule is deliberately status-BLIND. `assertNoDuplicateOpenRequest()` already covers open
 * pairs; re-proposing the very same candidate to the very same challenge after it was rejected is
 * not a retry, it is pestering the publisher with an answer he has already given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_collaboration_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('marketplace_challenge_id')
                ->nullable()
                ->after('target_representation_id');

            $table->index('marketplace_challenge_id', 'suchak_collab_challenge_idx');
            $table->unique(
                ['marketplace_challenge_id', 'requesting_representation_id'],
                'suchak_collab_challenge_repr_unq',
            );
            $table->foreign('marketplace_challenge_id', 'suchak_collab_challenge_fk')
                ->references('id')->on('suchak_marketplace_challenges')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suchak_collaboration_requests', function (Blueprint $table): void {
            $table->dropForeign('suchak_collab_challenge_fk');
            $table->dropUnique('suchak_collab_challenge_repr_unq');
            $table->dropIndex('suchak_collab_challenge_idx');
            $table->dropColumn('marketplace_challenge_id');
        });
    }
};
