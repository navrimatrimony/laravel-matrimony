<?php

use App\Models\SuchakMarketplaceChallenge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matchmaker marketplace blueprint D4 / D18 / section 11 phase 2 — THE CHALLENGE OBJECT.
 *
 * "I hold this customer; I will pay X% of my success fee to whoever brings the match."
 *
 * ── WHY A NEW TABLE, VERIFIED BEFORE IT WAS WRITTEN ───────────────────────────────────────────
 *
 * `suchak_collaboration_requests` carries `requesting_suchak_account_id` AND
 * `target_suchak_account_id`, both NOT NULL from row one (2026_06_09_200000), and every one of its
 * six identity columns is NOT NULL as well. A challenge is published BEFORE any helper exists —
 * that is its entire point — so the engagement pair cannot hold one even in principle. Nothing
 * else could either: `suchak_profile_representations` is one Suchak's mandate over one candidate
 * and carries no money, `suchak_commission_agreements` hangs off a collaboration request by a
 * `unique(collaboration_request_id)`, `suchak_pipelines` is the internal CRM funnel, and
 * `SuchakPublicMarketplaceService` / `PublicMarketplaceController` are the PUBLIC WEBSITE DIRECTORY
 * OF SUCHAK ACCOUNTS — a different marketplace entirely, with no candidate and no share.
 *
 * ── WHAT IT DELIBERATELY DOES NOT CARRY ───────────────────────────────────────────────────────
 *
 * 1. NO copy of the candidate's visible facts. Not a name, not a village, not a photo path, not an
 *    age. Cross-Suchak presentation has exactly one owner — SuchakCandidateMaskingService::
 *    maskedSummary — with the four defaults of D19a and the photograph always shown. This table
 *    holds `representation_id` and nothing else about the person; every read goes through the
 *    masking service, so a Suchak's per-candidate reveal decisions apply here the moment he makes
 *    them, and cannot go stale in a second copy.
 *
 * 2. NO engagement fields. Once a helper proposes and the publisher accepts, the existing
 *    `suchak_collaboration_requests` + `suchak_commission_agreements` pair IS the engagement
 *    (section 6.1, corrected 2026-08-01). The challenge is the invitation, never the engagement.
 *    There is deliberately no `marketplace_challenge_id` column on `suchak_collaboration_requests`
 *    yet: its only writer is accept-by-proposing, which is the NEXT slice, and a nullable FK with
 *    no writer is the exact defect this repository keeps shipping.
 *
 * 3. NO chargeable ceiling, despite section 5.5's line "Published / withdrawn, declared share,
 *    chargeable ceiling". That line predates D17, and section 15 records the product owner
 *    reversing it twice: a ceiling "protects nothing ... and it reads as a quota to be filled".
 *
 * 4. NO `times_published` counter (A12). It is `count(*)` over this table per representation.
 *
 * 5. NO currency. A share is a slice of money that already has a currency — the agreement's, frozen
 *    from the package. A first draft of this file carried `share_currency` and it let a publisher
 *    relabel his own INR success fee as dollars on every browsing Suchak's screen. Full reasoning
 *    beside the declared-share columns below.
 *
 * ── THE DECLARED SHARE: VOCABULARY REUSED, COLUMNS NOT ────────────────────────────────────────
 *
 * `suchak_commission_agreements.groom_side_share` / `bride_side_share` look like the declared share
 * and are not: they are a TWO-WAY split of an EXISTING pair that must sum to 100, and they name
 * both sides by account id. A challenge has no second side. So the split-type / percent-or-fixed
 * VOCABULARY is bound to `SuchakCommissionAgreement::SPLIT_*` (no new strings), and the columns are
 * the challenge's own, one-directional ones. The CURRENCY is neither: see the column list.
 *
 * `to_be_discussed` is excluded by D4 — the share is decided in advance and is not negotiable by
 * the helper, so "to be discussed" is the one thing a challenge can never say. `equal_percent` is
 * excluded because it is `custom_percent` at 50.00 and a second spelling of one number is how two
 * screens end up disagreeing.
 *
 * ── EXPIRY IS NOT THE COLLABORATION SLA ───────────────────────────────────────────────────────
 *
 * `suchak_collaboration_requests.expires_at` is NOT NULL and always set from
 * `SuchakPolicyService::collaborationSlaDays()`, because it is a named counterparty's deadline to
 * answer. A challenge has no counterparty to hold to a deadline; its expiry is the publisher's own
 * decision about how long he will leave his customer open to the market. NULL therefore means
 * "open until I withdraw it", which is a real answer and not a missing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suchak_marketplace_challenges', function (Blueprint $table): void {
            $table->id();

            // The declarer (D4). Restrict-on-delete like every other Suchak-owned row: a published
            // challenge is evidence of what was promised, and A7's realized-vs-declared ratio reads it.
            $table->unsignedBigInteger('suchak_account_id');

            // The candidate this challenge is FOR. The publisher's one input, and the only pointer
            // at the person — the profile id is reachable through it and is deliberately not copied.
            $table->unsignedBigInteger('representation_id');

            // The agreement revision in force. NOT an input: section 4 says "publication attaches to
            // whichever agreement is accepted at that moment", so the service resolves the accepted
            // revision and FREEZES it here. The declared share is a slice of THIS revision's frozen
            // success fee, which is what makes A8 enforceable — a later revision cannot retro-price a
            // share already published, because a rate change is a new agreement row and never an edit.
            $table->unsignedBigInteger('customer_agreement_id');

            // ── the declared share (D4), one-directional ──
            $table->string('declared_share_type', 32);
            // 5,2 holds 0.01 .. 100.00, matching suchak_commission_agreements' share columns.
            $table->decimal('declared_share_percent', 5, 2)->nullable();
            // 12,2 matches suchak_commission_agreements.fixed_amount.
            $table->decimal('declared_share_amount', 12, 2)->nullable();

            // NO CURRENCY COLUMN. Deliberate, and removed from this migration after review rather
            // than added to it — the file had not run anywhere.
            //
            // `suchak_service_packages.currency` owns the currency of this Suchak's money;
            // `suchak_customer_agreements.currency` is its frozen snapshot, copied at proposal time
            // by SuchakAgreementService and covered by agreement_snapshot_hash, and a re-quote may
            // never move it (SuchakPackageCatalogService::applyPlanTerms is "narrow ... so a
            // re-quote can never move a package's name, scope, CURRENCY or publish state"). The
            // same service already states the rule this column broke: "the package currency is
            // already settled by the caller — A FEE CAN NEVER CARRY ANOTHER."
            //
            // A declared share is a SLICE of the success fee on the package that agreement froze,
            // and `customer_agreement_id` above points straight at it. A third currency column here
            // was a third owner, and it did not merely go stale: with the column present, publishing
            // `share_currency=USD` against an INR agreement made listingPayload() render the
            // publisher's own ₹1,00,000 success fee to every browsing Suchak as "USD 1,00,000" —
            // one Suchak relabelling another's money on the screen used to decide whether the work
            // is worth doing. The currency is READ through
            // SuchakMarketplaceChallenge::declaredShareCurrency(), never stored here.

            // D18 / A10: who may see this listing. Written explicitly by the service and READ by
            // SuchakMarketplaceChallenge::audienceAdmits() on every browse, so the rule is a fact in
            // the row rather than a condition living in one query — which is precisely the failure
            // section 9's enforcement note describes for the commission split ("private today only
            // because no screen renders it; no rule prevents it").
            $table->string('audience', 32)->default(SuchakMarketplaceChallenge::AUDIENCE_VERIFIED_SUCHAKS);

            $table->string('status', 24)->default(SuchakMarketplaceChallenge::STATUS_OPEN);

            // What the publisher is asking for, in his own words. His text about the SEARCH, never a
            // restatement of the candidate's facts (those have one owner, the masking service).
            $table->text('publisher_note')->nullable();

            $table->unsignedBigInteger('published_by_user_id');
            $table->timestamp('published_at');
            // Nullable on purpose — see the header. NOT collaborationSlaDays().
            $table->timestamp('expires_at')->nullable();

            $table->unsignedBigInteger('withdrawn_by_user_id')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            // A8 names withdrawal as an attack ("withdrawing or re-publishing to escape a declared
            // share"). Recording the stated reason costs one column and is the only contemporaneous
            // evidence that will exist when the question is asked months later.
            $table->text('withdrawn_reason')->nullable();

            $table->timestamp('fulfilled_at')->nullable();

            $table->timestamps();

            // No partial unique index is available on both MySQL and SQLite, so "at most one OPEN
            // challenge per representation" is enforced in SuchakMarketplaceChallengeService under a
            // row lock. Two live challenges on one candidate at two different shares would be A8's
            // escape hatch standing wide open. This index is what makes that guard cheap.
            $table->index(['representation_id', 'status'], 'suchak_challenge_repr_status_idx');
            $table->index(['suchak_account_id', 'status'], 'suchak_challenge_account_status_idx');
            // The browse read and the expiry sweep both drive off this pair.
            $table->index(['status', 'expires_at'], 'suchak_challenge_status_expiry_idx');
            $table->index('customer_agreement_id', 'suchak_challenge_agreement_idx');
            $table->index('published_at', 'suchak_challenge_published_idx');

            $table->foreign('suchak_account_id', 'suchak_challenge_account_fk')
                ->references('id')->on('suchak_accounts')->restrictOnDelete();
            $table->foreign('representation_id', 'suchak_challenge_repr_fk')
                ->references('id')->on('suchak_profile_representations')->restrictOnDelete();
            $table->foreign('customer_agreement_id', 'suchak_challenge_agreement_fk')
                ->references('id')->on('suchak_customer_agreements')->restrictOnDelete();
            $table->foreign('published_by_user_id', 'suchak_challenge_published_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('withdrawn_by_user_id', 'suchak_challenge_withdrawn_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * A real down(): the table and its keys go, and nothing else is touched. The ladder rows written
     * by publishing are NOT deleted here — they are owned by `suchak_customer_agreements` (see
     * 2026_08_02_100000) and survive this rollback intact, which is correct: "this customer was
     * published to the marketplace on this date" stays true whether or not the challenge row still
     * exists, and it is exactly the kind of fact a dispute a year later turns on.
     */
    public function down(): void
    {
        Schema::dropIfExists('suchak_marketplace_challenges');
    }
};
