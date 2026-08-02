<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint §7 M2 / M3 / §9a A7 — THE FIRST OBJECT IN THIS SCHEMA THAT CAN
 * SAY "SUCHAK A OWES SUCHAK B".
 *
 * ── WHAT COULD NOT SAY IT, VERIFIED ONE LINE EACH BEFORE THIS FILE WAS WRITTEN ───────────────
 *
 *  - `suchak_ledger_entries` has the whole receivable vocabulary — TYPE_SUCCESS_FEE_EXPECTED,
 *    statuses expected/due/paid/waived, `due_date`, `paid_at`, even a `collaboration_request_id` —
 *    but ONE `suchak_account_id` and a NOT NULL `matrimony_profile_id`. It is one Suchak's
 *    receivable against a PERSON. There is no payer column, so it can say "A expects X on
 *    collaboration Y" and can never say "A owes B". It is also HAND-TYPED: `entry_type` is a
 *    `Rule::in(SuchakLedgerEntry::TYPES)` choice on two Blade forms (`CrmLedgerController::store`,
 *    `CollaborationController`), written by no engine — so a human could type
 *    `success_fee_expected` and manufacture the largest figure in the system.
 *  - `suchak_platform_payouts` is PLATFORM → one Suchak. `suchak_account_id`, no payer column,
 *    and its `payout_reason` values are all platform rewards (§7.3 is about this money, not this).
 *  - `suchak_customer_payments` is CUSTOMER → one Suchak. It names the agreement and the package
 *    but has no `fee_type`, no `collaboration_request_id`, no tranche FK and no challenge FK — it
 *    cannot say WHICH fee it paid. (It does not need one: see M3 below.)
 *  - `suchak_commission_agreements` is acceptance-only — pending/accepted/rejected/cancelled, two
 *    acceptance timestamps, and no due/paid/settled anywhere. `groom_side_share` / `bride_side_share`
 *    are a two-way CREDIT split that must sum to 100, not a one-directional debt.
 *  - `suchak_marketplace_challenges` holds the DECLARATION (D4) and nothing about whether it was
 *    honoured; `suchak_success_fee_tranches` is what the CUSTOMER owes, per installment, to one
 *    Suchak; `suchak_marriage_outcomes` (2026_08_04_100000) is the marriage and its attribution.
 *  - `grep -rn "payer_suchak\|payee_suchak\|cross_suchak"` over `app/` and `database/migrations/`
 *    returned nothing. The one near-miss, `owed_to_suchak_account_id`, is a READ key inside
 *    `SuchakTwelveMonthClauseService`'s payload — that service states outright that it is "the
 *    RECORD and the QUESTION" and that "nothing here moves, schedules, holds or invoices a rupee".
 *
 * ── WHY A TABLE AND NOT A PAYER COLUMN ON THE LEDGER ENTRY ───────────────────────────────────
 *
 * Adding `payer_suchak_account_id` to `suchak_ledger_entries` was the first thing considered and
 * it fails on three properties of that row, none of them a matter of taste:
 *
 *  1. `matrimony_profile_id` is NOT NULL there and a cross-Suchak share is NOT owed by a candidate.
 *     Filling it with the married candidate makes the row read as a debt of that family.
 *  2. The type is chosen by a human on a form. A debtor/creditor pair whose existence depends on
 *     somebody picking the right string from a dropdown is not an obligation, it is a note.
 *  3. Two creditor shapes in one table means every existing reader — the CRM ledger screen, the
 *     dashboards, `SuchakIncomeAnalyticsService`, `SuchakWorklistSourceQueries` — silently starts
 *     counting cross-Suchak debts as customer receivables, because none of them filters on a
 *     column that did not exist when they were written.
 *
 * ── THE GRAIN IS THE TRANCHE, BECAUSE §7.4 SAYS SO ───────────────────────────────────────────
 *
 * *"If helper A's match produced the settled tranche and helper B's match produced the wedding,
 * attribution is recorded per tranche, not per customer — A's declared share applies to the tranche
 * A's work released, B's to B's."* So one row per (marriage outcome, tranche). `success_fee_tranche_id`
 * is NULLABLE for the honest case where the agreement carries no installment plan at all: then the
 * declared share is one obligation over the whole success fee.
 *
 * ── THE AMOUNT IS FROZEN, AND THAT IS NOT A DUPLICATE OF THE DECLARATION ─────────────────────
 *
 * A derived amount would be `declared_share_percent` × `suchak_service_packages.post_marriage_fee_amount`,
 * and THAT COLUMN IS EDITABLE FOR THE LIFE OF THE PACKAGE. The customer agreement freezes it only
 * as an ingredient of `agreement_snapshot_hash` — a digest, from which no rupee figure can be read
 * back. So a live-derived obligation would silently re-price itself the moment the PAYER edited his
 * own package: the one party with an interest in the number moving, holding the only column it
 * depends on. Freezing the figure here is the same act `suchak_customer_agreements.price_amount`
 * performs for the customer's side, and it is why there is a column rather than an accessor.
 *
 * Everything else about the declaration is NOT copied: `marketplace_challenge_id` points at the
 * type, the percent and the fixed amount, and that row is undeletable (`SuchakMarketplaceChallenge::
 * delete()` throws) precisely so A7 and A8 can still read a declaration its publisher regrets.
 *
 * ── THE TWO ACCOUNT COLUMNS ARE DERIVABLE AND ARE STILL COLUMNS ──────────────────────────────
 *
 * `payer` is `collaborationRequest->customerOwnerSuchakAccountId()` and `payee` is
 * `helpingSuchakAccountId()` — both derived from `customer_owner_side`, by ROLE and never by
 * direction. They are stored anyway for the reason the §6.2 row stores its two candidate columns:
 * EVERY read of this table is BY ACCOUNT ("what do I owe", "what am I owed", A7's ratio for one
 * declarer), and an index cannot be built on a value that only exists after a join and a PHP
 * method. They cannot become a second, drifting answer because
 * `SuchakCrossSuchakObligation::assertMatchesItsOrigin()` runs on `saving` and refuses any row
 * whose pair disagrees with the engagement's roles — for every writer that ever exists, including
 * one added later that forgets the service.
 *
 * ── NO STATUS COLUMN, AND NO STORED DUE DATE. M3 IS ARITHMETIC ───────────────────────────────
 *
 * M3: *"A share falls due when the customer has PAID — or a fixed number of days after a RECORDED
 * MARRIAGE, whichever is earlier. Suppressing the record must ACCELERATE the obligation, never
 * kill it."*
 *
 *   half A, "the customer has paid"   `suchak_success_fee_tranches.settled_at` (+ `customer_payment_id`),
 *                                     the ONE object in this schema that can say a customer payment
 *                                     was the success fee. `suchak_customer_payments` gets NO
 *                                     `fee_type` column: that would be a second home for the fact
 *                                     the tranche pointer already owns.
 *   half B, the elapsed days          `SuchakMarriageOutcome::shareFallsDueAt()` =
 *                                     `married_on + SHARE_DUE_DAYS_AFTER_MARRIAGE`, arithmetic over
 *                                     the WEDDING DAY and never over the report instant.
 *
 * Half B is what makes suppression ACCELERATE rather than kill: `married_on` is the day of the
 * wedding, so a marriage suppressed for six months and recorded late produces an obligation that is
 * ALREADY overdue on the day it is raised. And the marriage may be recorded by EITHER Suchak
 * (`STAGE_MARRIAGE => CLAIMANT_EITHER_SUCHAK`), so the payer cannot withhold the fact that starts
 * the clock — the payee can record it himself.
 *
 * There is therefore no `due_at` column and no `status` column. A stored copy of
 * `married_on + 30 days` is the first thing to go stale when the constant moves, and a status
 * string is a second answer to a question two timestamps already answer. Production may never
 * invoke `schedule:run` and the notifications and governance queues have had no worker since
 * 2026-06-17 (the discipline copied from `SuchakVisitConfirmation::isClaimLapsed()` and migration
 * 2026_08_03_400000): every predicate here is correct on a production where nothing has ever swept.
 *
 * ── THE UNIQUE INDEX, AND THE HALF IT CANNOT CLOSE ───────────────────────────────────────────
 *
 * `unique(marriage_outcome_id, success_fee_tranche_id)` — one obligation per marriage per tranche.
 * MySQL and SQLite both treat a tuple containing NULL as distinct, so the no-installment-plan case
 * (`success_fee_tranche_id IS NULL`) is NOT closed by it; that half is asserted under the engagement
 * row lock in `SuchakCrossSuchakObligationService::raise()`. Stated rather than hidden, exactly as
 * 2026_08_04_100000 states the cross-column half it cannot express either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_cross_suchak_obligations', function (Blueprint $table): void {
            $table->id();

            // ── THE TWO PARTIES. The whole point of the table. ──
            // The declarer, who holds the customer, the agreement and the collection (M1).
            $table->unsignedBigInteger('payer_suchak_account_id');
            // The helper, who brought the match and to whom the declared share is owed (M2).
            $table->unsignedBigInteger('payee_suchak_account_id');

            // ── THE ORIGIN. Four pointers, each answering a different "which". ──
            // WHICH ENGAGEMENT: suchak_collaboration_requests + suchak_commission_agreements ARE the
            // engagement (§6.1). There is no suchak_engagements table and this does not invent one.
            $table->unsignedBigInteger('collaboration_request_id');
            // WHICH MARRIAGE: the §6.2 attribution row. Also the source of M3's half-B clock, which
            // is why this is NOT NULL — a share with no recorded marriage has no deadline at all.
            $table->unsignedBigInteger('marriage_outcome_id');
            // WHICH DECLARED SHARE: D4's challenge. NOT NULL because M2 admits no other source of a
            // cross-Suchak obligation, and D5 — "a Suchak who declared nothing owes nothing".
            $table->unsignedBigInteger('marketplace_challenge_id');
            // WHICH TRANCHE (§7.4, attribution per tranche). NULL = the agreement carries no
            // installment plan, so the declared share is one obligation over the whole fee.
            $table->unsignedBigInteger('success_fee_tranche_id')->nullable();

            // ── THE MONEY. Frozen; see the docblock for why it is not derived. ──
            $table->decimal('amount', 12, 2);
            // Copied from SuchakMarketplaceChallenge::declaredShareCurrency() at raise time — the
            // agreement's frozen currency, falling back to its package. Never a caller input: a
            // share is a slice of money that already has a currency, and a fee can never carry
            // another (the rule `2026_08_02_200000` learned by deleting its own `share_currency`).
            $table->string('currency', 3)->default('INR');

            // ── THE SETTLEMENT. A7: markable only by the HELPER, who is the payee. ──
            $table->timestamp('settled_at')->nullable();
            // WHICH PERSON in the payee's account pressed it — the one settlement fact that is not
            // derivable. The ACCOUNT is not stored: it is always the payee, guarded on `saving`.
            $table->unsignedBigInteger('settled_by_user_id')->nullable();
            $table->string('settlement_reference', 160)->nullable();
            $table->text('settlement_note')->nullable();

            $table->timestamps();

            $table->unique(
                ['marriage_outcome_id', 'success_fee_tranche_id'],
                'suchak_cross_obligation_outcome_tranche_unique',
            );

            // "What do I owe, and how much of it is still open" — A7's denominator and numerator in
            // one index. Account first because every read is scoped to one account.
            $table->index(['payer_suchak_account_id', 'settled_at'], 'suchak_cross_obligation_payer_idx');
            // "What am I owed" — the helper's side of the same question.
            $table->index(['payee_suchak_account_id', 'settled_at'], 'suchak_cross_obligation_payee_idx');
            $table->index('collaboration_request_id', 'suchak_cross_obligation_engagement_idx');
            $table->index('marriage_outcome_id', 'suchak_cross_obligation_outcome_idx');
            $table->index('marketplace_challenge_id', 'suchak_cross_obligation_challenge_idx');
            $table->index('success_fee_tranche_id', 'suchak_cross_obligation_tranche_idx');

            $table->foreign('payer_suchak_account_id', 'suchak_cross_obligation_payer_fk')
                ->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('payee_suchak_account_id', 'suchak_cross_obligation_payee_fk')
                ->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('collaboration_request_id', 'suchak_cross_obligation_engagement_fk')
                ->references('id')->on('suchak_collaboration_requests')->restrictOnDelete();
            $table->foreign('marriage_outcome_id', 'suchak_cross_obligation_outcome_fk')
                ->references('id')->on('suchak_marriage_outcomes')->restrictOnDelete();
            $table->foreign('marketplace_challenge_id', 'suchak_cross_obligation_challenge_fk')
                ->references('id')->on('suchak_marketplace_challenges')->restrictOnDelete();
            $table->foreign('success_fee_tranche_id', 'suchak_cross_obligation_tranche_fk')
                ->references('id')->on('suchak_success_fee_tranches')->restrictOnDelete();
            $table->foreign('settled_by_user_id', 'suchak_cross_obligation_settled_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suchak_cross_suchak_obligations');
    }
};
