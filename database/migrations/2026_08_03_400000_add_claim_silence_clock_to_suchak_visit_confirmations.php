<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint §7.2 — the seven-day silence clock, the stop-loss counter and the 90-day lapse.
 *
 * ONE new column. Everything else §7.2 needs already exists on this row and is bound to, not
 * copied: `suchak_completed_at` is the claim, `user_confirmation_status` is the answer,
 * `dispute_id` / `payout_hold_id` are the freeze, `refund_review_status` is the finding,
 * `fee_amount` is the money and `helper_suchak_account_id` / `suchak_account_id` are the two
 * Suchaks. The one fact none of them can express is the one below.
 *
 * ── WHY A COLUMN IS GENUINELY NEEDED ─────────────────────────────────────────────────────────
 *
 * §7.2 clause 3 caps a Suchak at "2 claims, or ₹5,000, past their window", and clause 4 says a
 * lapsed claim is "still counted". So the counter must be able to name, permanently, every claim
 * that went past its window — including ones whose case has since been closed. Nothing already on
 * the row can answer that:
 *
 *  - `visit_status = disputed` is temporary; the moment the case closes it moves off `disputed`.
 *  - `dispute_id` is permanent but says nothing about WHY the dispute opened — a family who
 *    contested on day 2 and a family who never answered at all both end up with one.
 *  - `refund_review_status = closed_no_finding` cannot distinguish this lapse from an admin
 *    filing a case away, and it is written for both.
 *
 * A stop-loss that a Suchak can clear by stonewalling for 90 days is not a stop-loss (M3: doing
 * nothing must never make an obligation disappear). So the moment of silence is recorded once,
 * in its own column, and is NEVER cleared — not by the lapse, not by a late confirmation, not by
 * an adjudication. Whether the claim is still unanswered TODAY is a separate question, derived
 * from the answer columns ({@see \App\Models\SuchakVisitConfirmation::hasUnansweredClaim()}),
 * so the count moves while the history does not.
 *
 * ── WHY THIS ALSO REPLACES THE MISSING FK ────────────────────────────────────────────────────
 *
 * `suchak_disputes` has no foreign key back to the visit, so a counter that starts from the
 * dispute side has no index path to the meeting, the money or the two accounts. It is not given
 * one: the counter starts from the VISIT side instead, where `dispute_id` already is the link in
 * the direction that exists, and where the account ids and `fee_amount` already live on the same
 * row. One table, one index, no join — and no second FK to keep in step.
 *
 * ── WHY IT DOUBLE-SERVES AS THE SILENCE ANCHOR FOR BOTH WINDOWS ──────────────────────────────
 *
 * §7.2 clause 5: both windows — the family's and the originating Suchak's — start on delivery and
 * run IN PARALLEL, not 7-then-7. They therefore expire at the same instant, which is exactly this
 * timestamp, so one column carries both.
 *
 * ── WHY THE SECOND COLUMN EXISTS: THE LAPSE IS A FACT, NOT A PREDICATE ───────────────────────
 *
 * An earlier version of this docblock claimed no `claim_lapsed_at` was needed, because the 90-day
 * lapse could be computed from `claim_unanswered_since` and so would hold on a production where
 * `schedule:run` never fires. The arithmetic half of that is right and is kept. The conclusion was
 * wrong, and it cost exactly the guarantee this file's own docblock promised.
 *
 * The predicate asked "is this claim unanswered AND more than 90 days old". Both halves move. A
 * family answering on day 99 made the first half false, so the whole predicate went false, so the
 * claim was no longer lapsed, so the payout guard let it through AND the stop-loss counter dropped
 * back to zero. A Suchak could clear his own counter by stonewalling for 90 days and then getting
 * one late answer — the precise thing the paragraph above declares impossible.
 *
 * The fix is that lapsing is a THING THAT HAPPENED, not a description of how the row looks now.
 * `claim_lapsed_at` records it, is stamped with the instant the window actually closed (never
 * `now()`, so a late observation cannot move the date), and is NEVER cleared. It is written by
 * whichever comes first: the daily lapse sweep, or the very act that would otherwise erase it —
 * every path that can record an answer stamps the lapse before writing the answer.
 *
 * That keeps both guarantees at once instead of trading one for the other:
 *  - with NO job ever running, {@see \App\Models\SuchakVisitConfirmation::isClaimLapsed()} still
 *    answers true by arithmetic, so "never due" needs no timer;
 *  - once anything at all touches the row, the fact is on it and no later answer, finding or
 *    confirmation can take it off again.
 *
 * It is not derivable from `claim_unanswered_since` alone, which is why it is not a duplicate of
 * it: the derivation additionally needs to know WHEN the claim was answered, and this row cannot
 * say. `user_confirmed_at` covers only one of the four ways to answer — a family's contest writes
 * no timestamp at all, and a finding's instant lives on `suchak_disputes.resolved_at`, across a
 * join the counter deliberately does not make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->timestamp('claim_unanswered_since')
                ->nullable()
                ->after('refund_review_note');

            // The terminal fact. Stamped with the instant the 90 days ran out, not the instant
            // somebody noticed, and never cleared by anything that arrives afterwards.
            $table->timestamp('claim_lapsed_at')
                ->nullable()
                ->after('claim_unanswered_since');
        });

        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            // The stop-loss read: every unanswered claim standing against ONE originating Suchak.
            // Account first because that is what the gate is given.
            $table->index(
                ['suchak_account_id', 'claim_unanswered_since'],
                'sk_visit_confirmations_unanswered_idx',
            );

            // The sweep read: meetings sitting at `completed` whose claim is old enough. The
            // status is the selective half on a healthy table — almost nothing stays `completed`.
            $table->index(
                ['visit_status', 'suchak_completed_at'],
                'sk_visit_confirmations_claim_clock_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->dropIndex('sk_visit_confirmations_claim_clock_idx');
            $table->dropIndex('sk_visit_confirmations_unanswered_idx');
        });

        Schema::table('suchak_visit_confirmations', function (Blueprint $table): void {
            $table->dropColumn(['claim_lapsed_at', 'claim_unanswered_since']);
        });
    }
};
