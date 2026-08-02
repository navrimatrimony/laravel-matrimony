<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint §6.2 — SUCCESS ATTRIBUTION, and the first record anywhere in
 * this codebase that a candidate actually MARRIED.
 *
 * ── WHAT DID NOT EXIST, VERIFIED ONE LINE EACH ───────────────────────────────────────────────
 *
 *  - `SuchakPipeline::STATUS_CONVERTED` is declared and has ZERO writers (grep: three declarations,
 *    one read in SuchakVisitConfirmationService, no write) — a status nobody sets is not a record.
 *  - `matrimony_profiles.lifecycle_state = archived_due_to_marriage` is a VISIBILITY state set from
 *    a generic admin dropdown: no date, no counterparty, no engagement, no terms.
 *  - `profile_marriages` is the candidate's PREVIOUS marriage (year granularity + divorce history),
 *    written by the full profile PUT. Nothing to do with a marriage this platform produced.
 *  - `homepage_success_stories` is a CMS table with free-text names and a `wedding_date` that binds
 *    to no profile, no Suchak and no agreement.
 *  - The only representation of a marriage today is a `suchak_collaboration_stage_events` row with
 *    `stage_key = marriage` — a CLAIM ON AN ENGAGEMENT, not an outcome on a person, and its
 *    `claimed_at` / `confirmed_at` are WHEN IT WAS REPORTED, never when the wedding happened.
 *
 * ── WHY A TABLE AND NOT COLUMNS ON SOMETHING EXISTING ────────────────────────────────────────
 *
 * Four homes were considered and rejected, each for a reason that is a property of the row and not
 * a matter of taste:
 *
 *  1. **`suchak_collaboration_stage_events` + a generic `event_occurred_on` date.** Tempting: the
 *     rung and its date on one row, and साखरपुडा has a date too (§6a). Rejected on grain. That
 *     table is keyed `(owner, stage_key)`, so TWO engagements on the same candidate can each carry
 *     a `marriage` rung and both would be equally valid — which is the exact ambiguity §6.2 exists
 *     to remove ("the largest sum in the system has no owner"). It also cannot hold the customer
 *     agreement revision NOT NULL: a stage event hangs off an engagement whose
 *     `suchak_commission_agreements.customer_agreement_id` is nullable. And a date column that is
 *     null on 13 of 14 rungs is a column that can hold a meaningless value, which the same model's
 *     `assertPriorAcquaintanceRelease()` already refuses on identical reasoning.
 *  2. **`matrimony_profiles` (a `married_on` beside `lifecycle_state`).** Rejected: it can hold the
 *     date and the person but names no engagement, no agreement revision and no evidence, so it
 *     answers "did they marry" and never "who is credited" — and §6.2 is entirely the second
 *     question. The lifecycle state stays what it is: visibility.
 *  3. **`profile_marriages`.** Rejected outright — that is the candidate's marriage HISTORY at
 *     intake. Writing a platform outcome into it would put "the marriage we produced" and "the
 *     marriage they arrived with" in one table, and the divorce columns beside it would then read
 *     as ours.
 *  4. **`suchak_success_fee_tranches`.** Rejected: it already carries per-tranche attribution
 *     (`released_by_collaboration_request_id` + `released_by_stage_event_id`, §7.4) and that is
 *     correct and untouched here. But a tranche is MONEY — a customer with `post_marriage_fee_mode
 *     = none` has no tranche rows at all, and their marriage would then be unrecordable. The
 *     outcome must exist whether or not anybody is owed anything.
 *
 * What IS deliberately not duplicated: no amount, no currency, no actor columns, no confirmation
 * columns and no spouse-name text. Who claimed and who confirmed live on `stage_event_id`'s row;
 * the money lives on the tranche ledger; the candidate's name lives on his profile.
 *
 * ── THE WEDDING DATE, SEPARATED FROM THE REPORTING INSTANT ───────────────────────────────────
 *
 * `married_on` is a DATE and is supplied by the person recording the marriage. `created_at` here,
 * and `claimed_at` / `confirmed_at` on the evidencing stage event, are TIMESTAMPS written by the
 * server at the moment of reporting. They are different facts and a family that marries in April
 * and is recorded in July must produce a row that says exactly that — M3 keys the cross-Suchak
 * share on "a fixed number of days after a recorded Marriage", and computing that from the report
 * instant would pay a helper later the longer the claim was suppressed, which M3 forbids in its
 * own sentence ("Suppressing the record must ACCELERATE the obligation, never kill it").
 *
 * `married_on` is a DATE and not a timestamp because a wedding is a day. Storing a time would
 * invent a precision nobody has and would make two people disagree about which day it was across a
 * timezone boundary.
 *
 * ── PHASE 3'S DISCIPLINE, COPIED: A TERMINAL FACT PLUS ARITHMETIC ────────────────────────────
 *
 * Production may never invoke `schedule:run`, and the notifications and governance queues have had
 * no worker since 2026-06-17. So nothing here waits for a job. The row is a TERMINAL FACT written
 * synchronously by the act that records the marriage, and M3's clock is pure ARITHMETIC over
 * `married_on` ({@see \App\Models\SuchakMarriageOutcome::shareFallsDueAt()}) — the same shape as
 * `SuchakVisitConfirmation::isClaimLapsed()`, which answers correctly on a production where no
 * timer has ever fired. There is no `share_due_at` column, because a stored copy of
 * `married_on + N days` would be a second home for one fact and the first thing to go stale when
 * the constant moves.
 *
 * ── WHY EVERY FOREIGN KEY IS NOT NULL ────────────────────────────────────────────────────────
 *
 * `customer_agreement_id` is the sharp one. `suchak_commission_agreements.customer_agreement_id`
 * is nullable and in practice usually null, and until the gate fixed in the same commit
 * (`SuchakCollaborationService::assertStageClaimant()` returned early for CLAIMANT_EITHER_SUCHAK,
 * ABOVE the null-agreement refusal) the three terminal rungs could be claimed on an engagement with
 * no agreement revision behind it at all. Closing that gate is what makes NOT NULL possible here,
 * and NOT NULL is the point: an attribution row that cannot name the terms it attributes under is
 * the §6.2 row with nothing to attribute — a claim on the largest sum in the system resting on
 * nothing. The two are one change and were made in one commit for that reason.
 *
 * ── THE UNIQUE INDEXES, AND WHY EVERY ONE OF THEM IS SCOPED TO THE LIVE ROW ──────────────────
 *
 *  - `collaboration_request_id` — one LIVE marriage per engagement.
 *  - `stage_event_id` — one LIVE outcome per evidencing rung; the row can never be attributed to
 *    a second piece of evidence at the same time.
 *  - `married_matrimony_profile_id` and `spouse_matrimony_profile_id` — one person marries once,
 *    and the marriage is credited once. These two columns are NOT a reading copy of the
 *    engagement's candidate columns (readers take the pair from the engagement, and a `saving`
 *    guard on the model refuses any row whose pair disagrees with it); they exist because the
 *    constraint that closes §6.2's ambiguity cannot be carried by a derived value. Helper A and
 *    helper B holding two engagements on the same candidate can each claim a `marriage` rung —
 *    both rows are legal on the ladder — and without a candidate-level unique both would produce an
 *    attribution and the ₹1,00,000 would have two owners.
 *
 * Neither MySQL nor SQLite can express "this profile appears in NEITHER column of any other row"
 * as one index, so the cross-column half is asserted under a row lock in
 * `SuchakMarriageOutcomeService::record()`. The four indexes close the same-column half absolutely,
 * which is what still holds if a second door is ever added and forgets the service.
 *
 * ── `void_seq`, AND THE FIRST-WRITE-WINS THAT IT UNDOES ──────────────────────────────────────
 *
 * §6.2's opening sentence is "two Suchaks can hold simultaneously valid representations … on the
 * same candidate". So a rival Suchak holding his own engagement on candidate X may claim a marriage
 * on it — and with a bare candidate UNIQUE, that UNCONFIRMED claim took the candidate FOREVER: the
 * real engagement could never write its attribution, the rows are undeletable by design, and no
 * correction path existed. The largest sum in the system was decided by whoever tapped first, and a
 * claim is only a Suchak's word (D23) until the family confirms it (D26).
 *
 * The correction door (`SuchakMarriageOutcomeService::voidClaim()`, admin only, unconfirmed claims
 * only) sets the row aside rather than erasing it — the discipline every evidentiary row in this
 * domain already carries. `void_seq` is what lets a set-aside row stop blocking: it is `0` on a
 * live row and the row's OWN id once voided, so each unique index above becomes "one LIVE row per
 * key" and admits any number of superseded ones. A partial/filtered unique index would say the same
 * thing in one column, but MySQL has none and SQLite's is not portable through Laravel's schema
 * builder, so the discriminator is a real column and the model's `saving` guard refuses any row
 * where `void_seq` and `voided_at` disagree.
 *
 * `voided_at` / `voided_by_user_id` / `void_reason` are the human record — WHEN, WHO and WHY — and
 * are not a second answer to "is it void": the guard binds them to `void_seq`, which is the only
 * thing the database reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_marriage_outcomes', function (Blueprint $table): void {
            $table->id();

            // The engagement credited with the introduction (§6.2). The engagement IS
            // suchak_collaboration_requests + suchak_commission_agreements (§6.1) — there is no
            // suchak_engagements table and this does not invent one.
            $table->unsignedBigInteger('collaboration_request_id');

            // The agreement revision in force, copied from the engagement's commission agreement at
            // record time. A revision is a ROW (agreement_revision + supersedes_agreement_id), so
            // this names the exact terms the success fee is a fee under — a year later, when the
            // rates have moved twice, this is the only thing that says which figure applies.
            $table->unsignedBigInteger('customer_agreement_id');

            // The ladder rung that evidenced it — stage_key = marriage. Who claimed it, who
            // confirmed it and when are that row's columns and are never copied here.
            $table->unsignedBigInteger('stage_event_id');

            // The two people. `married_` is the candidate on the CUSTOMER-OWNING side (whose
            // agreement and success fee this row is about); `spouse_` is the helping side's
            // candidate — the person §6.2 calls the counterparty and `lifecycle_state` never named.
            $table->unsignedBigInteger('married_matrimony_profile_id');
            $table->unsignedBigInteger('spouse_matrimony_profile_id');

            // WHEN THE WEDDING HAPPENED. A day, supplied by the recorder — never `now()`.
            $table->date('married_on');

            // THE CORRECTION DOOR. `void_seq` is 0 while the row is live and the row's own id once
            // an admin sets it aside; it is the only thing the unique indexes below read. The other
            // three are the human record of that act and are bound to it by the model's guard.
            $table->unsignedBigInteger('void_seq')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->string('void_reason', 500)->nullable();

            $table->timestamps();

            $table->unique(['collaboration_request_id', 'void_seq'], 'suchak_marriage_outcome_engagement_unique');
            $table->unique(['stage_event_id', 'void_seq'], 'suchak_marriage_outcome_event_unique');
            $table->unique(['married_matrimony_profile_id', 'void_seq'], 'suchak_marriage_outcome_candidate_unique');
            $table->unique(['spouse_matrimony_profile_id', 'void_seq'], 'suchak_marriage_outcome_spouse_unique');

            // M3's read: every recorded marriage whose share window has run out. Date first because
            // that is what the arithmetic compares; the agreement narrows it per customer.
            $table->index(['married_on', 'customer_agreement_id'], 'suchak_marriage_outcome_due_idx');
            $table->index('customer_agreement_id', 'suchak_marriage_outcome_agreement_idx');
            // Every read in the app is scoped to live rows by the model's global scope, so this is
            // the column the planner sees on all of them.
            $table->index('void_seq', 'suchak_marriage_outcome_void_seq_idx');

            $table->foreign('collaboration_request_id', 'suchak_marriage_outcome_engagement_fk')
                ->references('id')->on('suchak_collaboration_requests')->restrictOnDelete();
            $table->foreign('customer_agreement_id', 'suchak_marriage_outcome_agreement_fk')
                ->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
            $table->foreign('stage_event_id', 'suchak_marriage_outcome_event_fk')
                ->references('id')->on('suchak_collaboration_stage_events')->restrictOnDelete();
            $table->foreign('married_matrimony_profile_id', 'suchak_marriage_outcome_candidate_fk')
                ->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('spouse_matrimony_profile_id', 'suchak_marriage_outcome_spouse_fk')
                ->references('id')->on('matrimony_profiles')->restrictOnDelete();
            $table->foreign('voided_by_user_id', 'suchak_marriage_outcome_voider_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_marriage_outcomes');
    }
};
