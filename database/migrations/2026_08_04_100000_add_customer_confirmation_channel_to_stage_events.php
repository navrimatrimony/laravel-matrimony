<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint D26 / §7.4 / D23 — the family's CONFIRMATION gets a channel.
 *
 * `marriage_settled`, `engagement` and `marriage` are claimed by either Suchak and then CONFIRMED by
 * the customer (SuchakCollaborationStageEvent::CONFIRMABLE_STAGES, D26). Confirmation is the moment a
 * claim turns into money — SuchakSuccessFeeTrancheService releases on rungs where `isSettled()` is
 * true, and for these three `isSettled()` means `confirmed_at is not null`.
 *
 * ── WHY A COLUMN IS GENUINELY NEEDED ─────────────────────────────────────────────────────────
 *
 * The confirmation half of this table has exactly one identity column, `confirmed_by_user_id`, and it
 * is a `users` foreign key. Blueprint §2 says the customer is the FAMILY and usually has no login at
 * all, so the party D26 names as the confirmer is precisely the party that column cannot hold. The
 * result was that the whole success fee could only be released by an ADMIN standing in
 * (SuchakAdminTerminalStageApiController) or by a family member who happened to hold a member-app
 * login and to be one of the two candidates (MemberSuchakStageApiController) — never by the
 * login-less family the customer portal exists for.
 *
 * Written with `confirmed_by_actor_type = user` and `confirmed_by_user_id = NULL`, a portal-borne
 * confirmation would be indistinguishable from a row confirmed by nobody, which is exactly the
 * argument `claimed_via_customer_portal_link_id` was added on (2026_08_03_200000). This is that same
 * argument on the confirmation half, and it takes the same answer: name the CHANNEL — the customer
 * portal link the family was sent — rather than invent an identity.
 *
 * ── WHAT THIS COLUMN DOES *NOT* CLAIM (D23, §10 S4) ──────────────────────────────────────────
 *
 * OTP does not exist on production. This column proves only that somebody holding that link
 * confirmed, at that time. It is NOT a verification of who they were, and nothing on this path
 * writes a `*_match` or `*_verified` flag. The IP and user agent of the act live where the Suchak
 * domain already keeps them (`suchak_activity_logs`); the link's own timeline stays in
 * `suchak_customer_portal_events`. Neither is copied here.
 *
 * Nullable, because an admin's or a member's confirmation leaves it null and names a user instead.
 * The exactly-one-channel invariant lives in SuchakCollaborationStageEvent::assertConfirmChannel(),
 * on `saving`, for the same reason the claim-channel invariant does: MySQL and SQLite cannot both be
 * given the same CHECK through Laravel's schema builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('confirmed_via_customer_portal_link_id')
                ->nullable()
                ->after('confirmed_by_user_id');
        });

        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->index('confirmed_via_customer_portal_link_id', 'suchak_collab_stage_confirm_portal_idx');
            $table->foreign('confirmed_via_customer_portal_link_id', 'suchak_collab_stage_confirm_portal_fk')
                ->references('id')->on('suchak_customer_portal_links')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->dropForeign('suchak_collab_stage_confirm_portal_fk');
            $table->dropIndex('suchak_collab_stage_confirm_portal_idx');
        });

        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->dropColumn('confirmed_via_customer_portal_link_id');
        });
    }
};
