<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint 6a / D11 / D23 — the three rungs the FAMILY owns get a channel.
 *
 * `viewed`, `interested` and `meeting_confirmed` are claimed by the CUSTOMER
 * (SuchakCollaborationStageEvent::STAGE_CLAIMANTS). Until now no row could exist for any of them:
 * every Suchak was refused (correctly — a Suchak writing "the family looked at this" is a forgery,
 * 9a A2/A3), and the customer had no door of their own. D11 attaches the 12-month
 * anti-circumvention clause at `viewed`, and its anchor timestamp is `claimed_at` on this table —
 * a column that was declared, indexed and unwritable.
 *
 * ── WHY A COLUMN IS GENUINELY NEEDED ─────────────────────────────────────────────────────────
 *
 * A customer claim carries NEITHER of the two existing claimer columns: `claimed_by_suchak_
 * account_id` is null because no Suchak acted, and `claimed_by_user_id` is null because the
 * customer is the FAMILY and, per blueprint section 2, usually has no login at all. A row with
 * both null and no third column would be indistinguishable from a row written by nobody — and the
 * evidentiary trail of a dispute a year later is the entire point of this table.
 *
 * So the row names the CHANNEL the act came through: the customer portal link the family was sent.
 * `suchak_customer_portal_links` is the one existing tokenised customer link that is re-openable
 * (`opened_at`), claimable (`claimed_at`, `claimed_name`, `claimed_relationship_to_candidate`) and
 * revocable, with its own append-only event table — the only one of the four that can carry three
 * separate acts weeks apart and record WHO in the family held it. The agreement-acceptance token
 * and the consent token are both SINGLE USE and die on first decision; a payment-request token is
 * one money artifact, not an identity.
 *
 * ── WHAT THIS COLUMN DOES *NOT* CLAIM (D23, section 8) ───────────────────────────────────────
 *
 * OTP does not exist on production (section 10 S4). This column proves only that somebody holding
 * that link acted. It is NOT a verification of the customer's identity, and nothing here writes a
 * `*_match` or `*_verified` flag — `recordPublicConsentDecision()` writing `mobile_match => true`
 * unchecked is named in section 8 as the one fiction already in this codebase, and it must not be
 * repeated. The IP and user agent of the act are recorded where the Suchak domain already keeps
 * them, `suchak_activity_logs`, and the link's own timeline stays in
 * `suchak_customer_portal_events` — neither is copied here.
 *
 * Nullable, because every Suchak-claimed rung leaves it null. The exactly-one-channel invariant
 * (a customer rung MUST name a link and MUST NOT name a Suchak claimer; a Suchak rung must do the
 * reverse) lives in SuchakCollaborationStageEvent::assertClaimChannel(), on `saving`, for the same
 * reason the owner invariant does: MySQL and SQLite cannot both be given the same CHECK through
 * Laravel's schema builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('claimed_via_customer_portal_link_id')
                ->nullable()
                ->after('claimed_by_user_id');
        });

        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->index('claimed_via_customer_portal_link_id', 'suchak_collab_stage_portal_idx');
            $table->foreign('claimed_via_customer_portal_link_id', 'suchak_collab_stage_portal_fk')
                ->references('id')->on('suchak_customer_portal_links')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->dropForeign('suchak_collab_stage_portal_fk');
            $table->dropIndex('suchak_collab_stage_portal_idx');
        });

        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->dropColumn('claimed_via_customer_portal_link_id');
        });
    }
};
