<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint D11 / D21 / 9a A6 — the one release the 12-month
 * anti-circumvention clause has.
 *
 * D11 binds the clause at `viewed`, and the anchor is this table's `claimed_at`. 9a A6 names the
 * attack that creates against a family rather than against a cheat:
 *
 *   "A6 | The clause traps a family who already knew the other family
 *       | A one-tap 'we already know them' flag at view time removes the binding for that profile"
 *
 * ── WHY THIS COLUMN AND NOT A DERIVATION ─────────────────────────────────────────────────────
 *
 * Nothing else in the schema records that two families were already acquainted. It is not
 * observable from a profile, a village, a pipeline or a suggestion — only the family knows it, and
 * A6 says they say so with one tap. So this is a genuinely new fact (blueprint 5.5's test) and it
 * gets exactly one column, in the one place that can carry it.
 *
 * ── WHY IT SITS ON THE `viewed` ROW ──────────────────────────────────────────────────────────
 *
 * A6 says "at view time" and "for that profile". The `viewed` stage event IS the act that creates
 * the binding, it is unique per (engagement, stage), and the engagement names exactly the one
 * candidate pair the release applies to. Putting the release anywhere else — on the engagement, on
 * the customer context, on a table of its own — would leave the binding and its release in two
 * places that can disagree; here, one row answers "did this bind?" completely.
 *
 * It is also why the flag is refused on every other rung
 * (SuchakCollaborationStageEvent::assertPriorAcquaintanceRelease): "we already knew them" declared
 * on `interested` or `meeting_confirmed` would be a release with no binding to release, and a
 * column that can hold a meaningless value eventually holds one.
 *
 * ── WHO MAY SET IT ───────────────────────────────────────────────────────────────────────────
 *
 * The FAMILY, over their own customer portal link, in the same request that records the view —
 * SuchakCollaborationService::recordCustomerStage(). `viewed` is a CUSTOMER rung
 * (STAGE_CLAIMANTS), so assertClaimChannel() already refuses every Suchak on that row and the
 * release inherits that guard rather than restating it. A Suchak able to tick this box could delete
 * his own obligation; a Suchak able to untick it could manufacture one.
 *
 * NOT nullable and defaulted false: every row that is not a declaration is an absence of one, and
 * NULL would invent a third state ("unknown") that no screen can produce and no rule reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->boolean('prior_acquaintance_declared')
                ->default(false)
                ->after('claimed_via_customer_portal_link_id');
        });
    }

    public function down(): void
    {
        Schema::table('suchak_collaboration_stage_events', function (Blueprint $table): void {
            $table->dropColumn('prior_acquaintance_declared');
        });
    }
};
