# Matchmaker Marketplace — Design Blueprint

> **STATUS: FROZEN v1.0** — 2026-08-01
> **Audience:** Product owner, developers, reviewers
> **Type:** Design contract only — **not** implementation instructions.
> **Depends on:** `DEVELOPER-OPERATING-CONTRACT.md`, `FIELD-OWNERSHIP-MAP.md`, `../../PRODUCT_MAP.md`
>
> **Implementation must not deviate from this document without a change request.**
> §15 records seven rulings from earlier drafts that were wrong and are reversed here. The most
> important: earlier drafts proposed building a meeting → claim → dispute → hold engine that
> **already exists in this codebase**.

---

## 0. Purpose in one sentence

**A Suchak and their customer freeze a written price agreement; the customer may then be
published to a marketplace where other Suchaks compete by proposing specific candidate
profiles; every stage from suggestion to marriage is recorded; the platform holds the
agreement, the evidence and the rules — and never the customer's money.**

Three pillars:

| Pillar | What it does |
|---|---|
| **Commercial governance** | A frozen, customer-accepted agreement between a customer and their own Suchak |
| **Collaboration marketplace** | Other Suchaks see the candidate and compete by proposing specific profiles |
| **Trust & evidence layer** | Every stage, claim, confirmation and behavioural fact is recorded |

**Why a Suchak opens their own customer to competitors:** not commission arithmetic —
**reputation protection**. In a small community, six months of nothing means "he ate the money",
and that ends the referral pipeline. ₹40,000 with a marriage beats ₹1,00,000 with a scandal.

---

## 1. Design freeze rules

| Rule | Meaning |
|---|---|
| This document is a **design contract** | Implementation does not change it; a change request does |
| Nobody is bound by anything not **declared in advance** | The governing principle of the money model |
| **No shared pot** | Each customer pays only their own Suchak, under their own agreement |
| The platform never holds **customer** money | It does hold Suchak payouts — see §7.3. v0.1 stated this wrongly |
| Consent-first survives | Only registered, consented profiles may be published or proposed |
| No-duplicate rule applies in full | Every item in §5.1 binds to the named existing owner |
| **Everything is digital** | Suchak acts in the app; the customer acts over an OTP link. No paper artifacts |

---

## 2. The actors

| Actor | Role |
|---|---|
| **Customer** | The candidate's family. Often has **no login** — `users.mobile` is null whenever the number on file is a household number. Everyone has *a phone*; not everyone has *their own* phone |
| **Originating Suchak** | Holds the customer relationship, the agreement and the collection |
| **Helping Suchak** | Accepts a challenge by proposing one specific candidate of their own |

---

## 3. Decisions taken

| # | Decision |
|---|---|
| D1 | Registration fee, per-meeting **offline** fee, per-meeting **online** fee, success fee and success-fee installments are all set per customer and all adjustable |
| D2 | Offline and online meeting fees are **two fully independent amounts**. No percentage is stored — premium online counselling may legitimately cost more than an offline visit |
| D3 | The **customer** accepts the agreement. On acceptance every amount **freezes** |
| D4 | The challenge **declares the share** the declarer will pay a helper, **upfront**. Accepting the challenge = accepting that share. No negotiation afterwards |
| D5 | A Suchak who declared nothing owes nothing — even if their customer married through the marketplace |
| D6 | A cross-Suchak share becomes payable only **after the customer actually paid** |
| D7 | A helping Suchak cannot press a bare "accept". They must **select a specific candidate profile** to propose |
| D7a | **That selection needs search and filters, not a list.** A working Suchak may hold two hundred candidates; scrolling is not a selection mechanism. Search by name plus filters on age, education, location and income, and — reusing `SuchakCollaborationService::suggestedOpportunities()`, which already ranks candidate pairs — the likely matches shown first |
| D8 | Only **registered** profiles may be proposed. No unregistered person may be named |
| D9 | Meeting states: Scheduled → Completed → Confirmed. The **customer** confirms |
| D10 | Meeting proof is **optional**: GPS, timestamp, OTP or photo. Photo is **never** mandatory |
| D11 | The 12-month anti-circumvention clause binds from **Viewed**, never from merely Suggested |
| D12 | Reputation is **two-sided** |
| D13 | A new Suchak shows a **"New"** badge, never "0 marriages" |
| D14 | The originating Suchak may **rank** suggestions but may **not block** them |
| D15 | The customer sees only their **own** rates. The commission split is never visible to any customer |
| D16 | Rate changes carry a **7-day cooling period** |
| D17 | **No ceiling on meetings.** How many people to meet is the family's own decision, and every meeting already requires their approval one at a time — so a cap protects against nothing while reading as a quota the Suchak intends to fill. Instead the running total (`3 meetings · ₹9,000`) is shown at the moment each new meeting is approved |
| D18 | The marketplace is visible to **verified Suchaks only**, and **every listing open is logged** and shown to the originating Suchak |
| D19 | The **full profile** is visible before accepting a challenge — accepting is a commitment, and a commitment made on partial information is a bad one |
| D19a | **Cross-Suchak visibility, across the whole Suchak app — not only the marketplace.** By default exactly four things are hidden from another Suchak: **name, village, detailed address, mobile**. Everything else is shown, **including the photograph** — a matchmaker who cannot see a face cannot propose a match, and withholding it does not protect anyone, it only stops the work. The originating Suchak may reveal any of the four, per candidate. He knows the family; the platform does not |
| D19b | **The member app is not covered by D19a.** A village is never shown to a member, anywhere. A member is not a matchmaker: they are choosing for themselves, not sourcing matches, so they have no use for a village and the original rule stands there unchanged. Two audiences, two rules — stated here so nobody later applies one to both |
| D19d | **A per-candidate display name the originating Suchak may type.** `matrimony_profiles.full_name` is a single column — there is no first/last split, and adding one would be wrong for a great many Indian names. So a Suchak who wants another Suchak to see only a surname, or a surname plus a village, types it. Default when he types nothing: `CandidateNameMask` ("सुनिती ग."). A bare surname is often useless anyway — dozens of families share one |
| D27 | **Screen text carries what the user needs to act, nothing else.** Rules, guarantees and rationale belong in this document. A line like "the photo carries your name as a watermark" tells a Suchak something he can neither use nor change; it is noise wearing the clothes of transparency. Before any string ships, ask: *does the reader do something differently because of it?* If not, cut it |
| D19c | **Cross-Suchak photographs carry a watermark naming the viewing Suchak.** Nothing is hidden and nobody is blocked; a photograph that later turns up in a WhatsApp group simply says who was holding it. Traceability instead of concealment |
| D23 | **OTP is deferred to the final phase.** Not because it is optional — because the delivery channel does not exist yet (§10 S4). Until it lands, acceptance tiers record what actually happened and claim nothing more (§8) |
| D20 | Customer-side signal is **history, not rating** — "8 meetings, 6 attended, 2 cancelled by the family, 1 marriage". Facts only, **derived from records, never typed by a Suchak**. It stops at marriage |
| D21 | **Leaving does not void the clause.** Ending the engagement stops future fees, but a marriage within 12 months to a profile the customer **viewed** still owes the success fee |
| D22 | **No paper.** The agreement is digital end to end. See §5.4 for what this removes |
| D25 | **The success fee is earned in tranches at past events, so nothing is ever refunded.** Each tranche is released by a ladder stage that has already happened; there is no money held against a future that may not arrive. See §7.4 for the arithmetic rules |
| D26 | **Every terminal stage is claimed, then confirmed.** Marriage settled, engagement and marriage each follow the meeting pattern — claim → customer confirms → 7 days silent → dispute — except that **either Suchak may raise the claim**, not only the helper |
| D24 | **A re-visit is charged at the same rate as a first visit** — but only when the Suchak arranges it. A family who goes back to the same candidate on their own owes nothing. The fee is for the arranging, not for the meeting existing |

---

## 4. Rate governance — resolved

There are **two occasions, not one agreement being re-priced.** The first agreement is the base
relationship between a customer and their own Suchak. The **marketplace is a later option**,
reached when that relationship has not produced a marriage. At that point the pair may continue
under the existing agreement or write a new one.

> **Rule.** A rate change is never an edit. It is a new agreement (or revision) the customer must
> accept. The rate on the customer's currently accepted agreement governs every meeting under it.
> Publication attaches to whichever agreement is accepted at that moment.

Price discovery survives — the customer still raises the rate to attract Suchaks — but a rate can
never move without a fresh, recorded customer act. `agreement_revision` and
`supersedes_agreement_id` already exist, and editing terms already resets acceptances. D16's
cooling period prevents rapid revisions.

---

## 5. What already exists — verified against the schema

**Reuse means bind to the named owner. Adding a parallel field is the single worst outcome under
the frozen no-duplicate rule.** v0.1 violated this; §15 records how.

### 5.1 The meeting engine already exists — and it is the core of this feature

`suchak_visit_confirmations` (35 columns) plus an append-only companion
`suchak_visit_confirmation_events`.

> **D24 note.** The `unique(pipeline_id)` index (B1 below) is what currently makes a re-visit
> impossible — one row per pair, forever. Dropping it and adding `meeting_sequence` is therefore
> not only a "more than one meeting" fix; it is the precondition for the re-visit fee to exist
> at all, and `meeting_sequence > 1` is exactly what marks a charge as a re-visit.

| What the design needs | What the table already has |
|---|---|
| Meeting states | `visit_status` ∈ scheduled / completed / confirmed / disputed / payout_qualified / cancelled |
| Who scheduled it, when | `scheduled_by_user_id`, `scheduled_at`, `scheduled_for`, `schedule_note` |
| Which Suchak marked it done | `suchak_completion_status`, `suchak_completed_by_user_id`, `suchak_completed_at`, `suchak_completion_note` |
| Customer confirmation | `user_confirmation_status`, `user_confirmed_by_user_id`, `user_confirmed_at`, `user_confirmation_note` |
| Who must confirm | `confirmation_policy_mode` ∈ `user_and_admin` / `admin_only` / `user_only` |
| Dispute linkage | `dispute_id` → `SuchakDispute` (open → under_review → resolved / rejected / closed) |
| **Money leverage** | `payout_hold_id` → `SuchakPayoutHold`, which already has `SCOPE_VISIT_CONFIRMATION_DISPUTE` |
| Tamper-proof trail | `suchak_visit_confirmation_events`: `event_type`, `actor_type`, `actor_user_id`, `from_status`, `to_status`, `metadata_json`, `occurred_at` — update/delete/re-save all **throw** |

**Four blockers on this table, all narrow:**

| # | Blocker | Fix |
|---|---|---|
| B1 | `unique(pipeline_id)` — **one meeting per pair, forever** | Drop the index; add `meeting_sequence`. A migration, not a build |
| B2 | **Only two HTTP routes exist**: schedule and complete. `confirmByUser`, `confirmByAdmin`, `disputeVisit`, `qualifyPayoutForVisit` are implemented in the service but have **no route and no controller** — they are reachable only from tests. In production a row can only ever be `scheduled` or `completed` | Expose the missing four. The service logic is already written and guarded |
| B3 | No `helper_suchak_account_id`, no `fee_amount`, no offline/online mode | Three columns |
| B4 | No cancelling actor, no cancellation reason, no attendance, no actual-held-at. `STATUS_CANCELLED` is declared but **never written** | D20 needs these. Cheapest home: new `event_type` values on the existing append-only events table |

### 5.2 Other reuse

| Fact | Canonical owner |
|---|---|
| Registration fee | `suchak_customer_plans.price_amount` → `suchak_service_packages.price_amount` → frozen onto `suchak_customer_agreements.price_amount` |
| Per-meeting **offline** fee | `suchak_customer_plans.per_meeting_fee_amount` — the offline fee **is** this column |
| Success fee | `post_marriage_fee_amount` + `post_marriage_fee_mode` (`MODE_NONE` already encodes D5) |
| Customer acceptance + freeze | `suchak_customer_agreements` + `SuchakAgreementService::acceptTerms()`. `assertTermsActor()` **already permits the customer side** to accept |
| Tokenised public link | The consent-link shape: `token_hash`, `token_expires_at`, `public_token_used_at`, `decided_at` |
| Cross-Suchak share + dual acceptance | `suchak_commission_agreements`. Terms come **from the challenge**, not from negotiation |
| Proposal names both candidates | `SuchakCollaborationService::createRequest()` — both params non-nullable, all six identity columns NOT NULL. **D7 is already structurally enforced** |
| Suggestion accept/reject rate | Derivable from `suchak_match_suggestions` (`decision`, `suchak_account_id`, `decided_at`) — **no rollup column** |
| Per-Suchak behavioural counts | Derivable from `suchak_activity_logs`, which already carries `ACTION_VISIT_SCHEDULED / _COMPLETION_MARKED / _USER_CONFIRMED / _DISPUTED / _PAYOUT_QUALIFIED` and is **already indexed on the exact tuple needed** |
| Counter + derived score + flag pattern | Copy the **shape** of `UserModerationStatsService` / `user_moderation_stats` |

> **Direction note.** Today the *requester* names both sides. In the marketplace the *responder*
> names theirs. No new engine: the Suchak answering a challenge becomes the `requester` — their
> candidate is `requestingRepresentation`, the challenge's candidate is `targetRepresentation`.

### 5.3 The freeze does not currently freeze the numbers that matter

`agreementSnapshotHash()` covers package name, description, price, currency, status, stages and
deliverables. It does **not** cover `per_meeting_fee_amount` or `post_marriage_fee_amount` —
those never leave `suchak_customer_plans`, a **mutable, un-versioned, Suchak-owned preset**.

**Consequence:** today "the customer accepted these fees" is not a true statement about the
meeting fee or the success fee. Copying them at package materialisation and extending the hashed
payload is not an enhancement — it is the precondition for D3 to mean anything.

### 5.4 Paper (D22) — what removing it touches

Paper is **not** a web-only leftover. `offline_signed_proof` is live, shipped Suchak-app UI in two
places. Removal is a Flutter release plus two controllers, one Blade partial, and a legacy
`offline_proof` alias in two `methodFromRequest()` matches.

**The strongest argument for removal:** the uploaded proof is **write-only**. Nothing verifies,
reviews, hashes, or even opens it — there is no download route and no admin action. The only
surface is a Blade line printing the literal text "Signed proof file stored". Acceptance is
granted in the same request as the upload, with no human in the loop. **As built, the paper
evidence cannot be read by anyone, including an admin.**

**Retain** `offline_signed_proof` as a *consent* channel if desired; **remove it as an acceptance
grade for money** (§8).

### 5.5 Genuinely new

| Fact | Note |
|---|---|
| Cross-Suchak display name | One new nullable column (D19d). Falls back to `CandidateNameMask` — do **not** add a second masking path |
| Per-meeting **online** fee | One new column. No meeting-mode dimension exists anywhere |
| Success-fee installments | Dedicated child table. Do **not** reuse `suchak_customer_agreement_stages` — those are service-delivery stages with no money column |
| The challenge object | Published / withdrawn, declared share, chargeable ceiling |
| Suggestion → **Viewed** → Interested | `suchak_match_suggestions` records suggestion but not customer-side viewing |
| **Engagement / assist object** | See §6. Nothing today can hold one customer and two Suchaks |
| **Success attribution** | See §6 |
| Marriage outcome | **Nothing in the Suchak domain records a marriage.** `payout_qualified` is the terminal state; `SuchakPipeline::STATUS_CONVERTED` exists but is never written |
| Behavioural aggregates | Greenfield for meetings-held, cancellations, no-shows, response time, success rate, marriages. **Add a reader over existing event tables, not a new event log** |

---

## 6. The two objects that must exist before anything else

Both force a redesign if deferred.

**6.1 The engagement (assist) object.** There is no row that can hold *one customer and two
Suchaks*. A helper's meeting claim today has nowhere legal to be written. Shape:
`originating_suchak_account_id`, `helper_suchak_account_id`, `customer_context_id`,
`agreement_id`, `agreement_revision`, `declared_share`. Every suggestion, meeting and claim hangs
off it.

**6.2 Success attribution.** Two Suchaks can hold simultaneously valid representations,
agreements and success-fee terms on the same candidate. When a marriage is recorded, **one row
must name the engagement credited with the introduction**, referencing the agreement revision in
force. Without it the largest sum in the system has no owner and the first success becomes the
first lawsuit.

---

## 6a. The stage ladder

Every stage is a record with an actor and a timestamp. Analytics, reputation, installments and
dispute resolution all read from this one spine. **Installment triggers must be chosen from these
stages, never free text.**

```
Registration
  → Agreement proposed
  → Agreement accepted        (freeze)
  → Published to marketplace  (challenge live, share declared)
  → Profile suggested         (helper names their candidate)
  → Viewed                    (customer opens it — the 12-month clause attaches HERE)
  → Interested
  → Meeting scheduled         (offline | online, first or re-visit)
  → Meeting completed         (helper marks)
  → Meeting confirmed         (customer)
  → Marriage settled          (both families agree — लग्न ठरले)   ── tranche 1
  → Engagement                (साखरपुडा — the ceremony)          ── tranche 2
  → Marriage                  (the wedding)                      ── remainder
  → Share settled             (helper marks; closes the loop)
```

**Three terminal events, not two.** The installment example — *50% when the marriage is settled,
50% on the engagement day* — only works if **settled precedes engagement**, which is how it runs
in practice: both families agree (लग्न ठरले), then the साखरपुडा, then the wedding. An earlier
draft carried only Engagement → Marriage and could not express that schedule.

**Each of the last three stages is claimed, then confirmed** (D26), on the meeting pattern:
claim → the customer confirms → seven silent days → dispute. The one difference is that **either
Suchak may raise the claim**. Evidence gets easier as the stakes rise — a साखरपुडा has an
invitation, a date and photographs; "both families agreed" has none of that, which is exactly
why its tranche is the smallest (§7.4 T4).

**Why `Share settled` exists:** without it a declared share costs nothing to promise. Publishing
each declarer's *realized vs declared* ratio is what stops inflated declarations (§9a A7).

---

## 7. Money rules

| Rule | Statement |
|---|---|
| M1 | No shared pot. Each customer pays **only their own Suchak**, under their own agreement |
| M2 | The only cross-Suchak obligation is the share the declarer **declared in advance** |
| M3 | A share falls due when the customer has paid — **or** a fixed number of days after a recorded Marriage, whichever is earlier. Suppressing the record must *accelerate* the obligation, never kill it |
| M4 | **No fee falls due without the customer's confirmation** — but silence is not a refusal. Silence opens a dispute (§7.2), never an automatic zero |
| M5 | **No meeting is charged that the customer did not approve.** Approval is per meeting; the running total appears at the moment of approval (D17). Information, not a limit |
| M9 | **The success fee is paid once per customer, in total.** Tranches already paid count toward it whichever match triggered them. A settlement that breaks never re-charges a paid tranche, so a family whose match broke twice never pays more than the one agreed figure (§7.4) |
| M10 | **A later stage releases every earlier unpaid tranche with it.** A wedding held without a साखरपुडा still owes the engagement tranche |
| M8 | **Second and later meetings with the same candidate are charged at the same rate**, when the Suchak arranges them (D24). Self-arranged contact between two families that have already met carries no fee — and no fee is due for a meeting the customer did not approve, so the family always chooses which of the two it is |
| M6 | The accepted agreement's rate governs (§4) |
| M7 | Fees are gated by acceptance tier (§8). A ₹1,00,000 claim may not rest on a Suchak's word alone |

### 7.1 Worked example — both sides on the platform

Boy's family ↔ Suchak A: success ₹1,00,000, offline meeting ₹3,000.
Girl's family ↔ Suchak B: success ₹80,000, offline meeting ₹2,000.
Suchak A publishes a challenge declaring **70/30**. Suchak B proposes the girl. They marry.

| Flow | Amount |
|---|---|
| Boy's family → Suchak A | ₹1,00,000 |
| Suchak A → Suchak B (declared share) | ₹30,000 |
| Girl's family → Suchak B | ₹80,000 |
| Suchak B → Suchak A | **nothing** — Suchak B declared nothing |

Suchak B earns from both sides. Correct: two customers, two agreements, two pieces of work — and
no obligation exists that was not declared.

### 7.2 A disputed meeting — using the engine that exists

```
Helper marks the meeting complete        (suchak_completion_status)
  → Customer confirms                    → user_confirmation_status = confirmed → fee due
  → Customer silent for 7 days           → disputeVisit() → SuchakDispute (open)
                                                          + SuchakPayoutHold
```

v0.1 invented a parallel claim object and an "unresolved" state. Both are dropped. The dispute
path, the dispute record and the payout hold **already exist and are already wired to each
other**; only the HTTP routes are missing (B2).

**Resolution, without an adjudicator sitting in judgement of each case:**

1. The **originating Suchak** answers within 7 days. He arranged the meeting.
2. He is **not neutral** — he pays the share — and this blueprint does not pretend otherwise. Set
   `confirmation_policy_mode = user_only` for marketplace visits so an admin is not silently
   required, and publish an immediate, raw counter on his card: *"6 helper claims unanswered,
   oldest 91 days."* A raw count from the first event beats a ratio that needs volume to move.
3. **Stop-loss instead of statistics.** A helper may not accept a new challenge from the same
   originating Suchak while 2 claims, or ₹5,000, sit past their window. This needs no
   adjudicator and no denominator — it works on day one, which "the pattern is the enforcement"
   does not.
4. **Disputes lapse.** An unanswered dispute terminates at 90 days: never revivable, never due,
   still counted, still visible. Otherwise a claim outlives its evidence.
5. **Clocks start on delivery, not dispatch**, and both windows run in parallel, not 7-then-7.

### 7.3 The platform does hold money — and that is the real leverage

v0.1 claimed the platform "never collects, holds, splits or refunds" and concluded that every
rule produces evidence but no enforcement. **That was factually wrong about this codebase.**
`PayuController` and `SuchakPayoutHold` both ship, and `SCOPE_VISIT_CONFIRMATION_DISPUTE`
already exists.

> **Correct statement:** the platform does not stand between a customer and a Suchak for
> Suchak-earned fees. It **does** control Suchak payouts, and a payout hold is a real lever.

Every claim-abuse consequence should route through the existing hold and freeze machinery rather
than through reputation alone.

---

### 7.4 Success-fee tranches

The customer and the Suchak set the split **at agreement time**, and it freezes with everything
else. A worked example on ₹1,00,000:

| Released by | Share | Amount |
|---|---|---|
| Marriage settled (लग्न ठरले) | 10% | ₹10,000 |
| Engagement (साखरपुडा) | 40% | ₹40,000 |
| Marriage (the wedding) | the remainder | ₹50,000 |

**Why this removes the refund question entirely.** A refund argument only exists when money was
taken for a future that then failed. Here every tranche is released by an event that has
**already happened**, so each rupee is already earned. Nothing is held; nothing is owed back. If
the settlement breaks after the first tranche, the Suchak keeps ₹10,000 for work he did, and
nothing further falls due.

**Arithmetic rules — all four exist to prevent a dispute, not to be clever:**

| # | Rule | Why |
|---|---|---|
| T1 | Every share is a percentage **of the total**, never of the remaining balance | "40% of the remainder" is ₹36,000, but almost everyone reads it as ₹40,000. Two people will compute two figures and both will believe they are right |
| T2 | The **final tranche is "the remainder"**, never a percentage | Rounding otherwise leaves a rupee unaccounted for. The parts must sum to the whole, exactly |
| T3 | The declared shares must **sum to 100%**, validated when the agreement is created | Otherwise `10 / 40 / 40` ships and 10% of the fee belongs to nobody |
| T4 | The **first tranche should be the smallest** | It is released by the softest evidence (two families agreeing is a conversation, not a ceremony) and it is non-returnable. A dishonest Suchak will push hardest exactly there, so keep the prize small |

**When a settlement breaks and a different match succeeds later (M9).** The paid tranche stands.
Only the **unpaid** tranches fire on the new match. The family's total exposure stays at the one
agreed figure however many settlements break — and it will be a family whose hopes have already
been broken twice.

**Consequence for the declared share (§6.2).** If helper A's match produced the settled tranche
and helper B's match produced the wedding, **attribution is recorded per tranche, not per
customer** — A's declared share applies to the tranche A's work released, B's to B's. Without
this, M9 would silently hand one helper the fruit of another's work.

---

## 8. Acceptance tiers — and what each tier may unlock

Everyone has a phone; not everyone has their own. Paper is gone (D22). Three tiers remain:

| Tier | How | Strength |
|---|---|---|
| **A** | Link opened and accepted from the **candidate's own** number (+ OTP once §10 S4 lands) | Strongest |
| **B** | Link opened and accepted from the **household** number, relation recorded (+ OTP later) | Strong |
| **D** | Suchak declares, nothing else | Weakest |

**Until OTP exists (D23),** tier A and tier B are distinguished only by *which stored number the
link was sent to* and *what relation was declared* — not by proof that the holder acted. The
record must say exactly that: `mobile_match = false` until an OTP verifies it, exactly as
`acceptManualProof()` already does honestly today. **No tier may claim a verification that did
not happen** — that fiction already exists once in this codebase
(`recordPublicConsentDecision()` writes `mobile_match => true` unchecked) and must not be
repeated here.

**Gate money by tier, not only visibility.** v0.1 gated marketplace visibility and left money
ungated — so a ₹1,00,000 claim could rest against a family that never touched the platform.

| Fee | Minimum tier |
|---|---|
| Registration | any |
| Per-meeting | B |
| **Success fee** | **A** |
| Marketplace publication with full identity | A or B |

> **Note:** there is **no acceptance-grade concept in the codebase today**, and money setup does
> not check consent at all — `SuchakPaymentSetupApiController` creates the customer context with
> zero consent predicate. This grade is being **introduced**, not tightened.

**Two guards the tiers depend on:**

- **Freeze the allow-list.** "OTP to a number already on the profile" is not a constraint while a
  Suchak can add any number in one API call — which the consent-contacts endpoint now permits.
  Snapshot `allowedMobiles()` onto the agreement **at proposal time**; numbers added later are
  usable for contact but **not for acceptance**.
- **Candidate notice.** Any acceptance not from the candidate's own number fires a plain-Marathi
  notice with a one-tap objection to **every other number on the profile**. The objection window
  closes before any profile is viewed — after that, D21 governs.

---

## 9. Visibility matrix

| Item | Customer | Originating Suchak | Helping Suchak | Other verified Suchaks |
|---|---|---|---|---|
| Own agreed fees | ✅ | ✅ | ✅ (as declared) | ✅ |
| **Another customer's fees** | ❌ | — | — | ✅ (market economics) |
| Commission split | ❌ **never** | ✅ | ✅ | ❌ |
| Profile incl. **photograph** | ✅ | ✅ | ✅ | ✅ (D19 / D19a) |
| Name, village, detailed address, mobile | ✅ | ✅ | hidden unless the originating Suchak reveals them (D19a) | hidden unless revealed |
| Suchaks who accepted, profiles suggested per Suchak | ✅ | ✅ | — | — |
| Suchak reputation | ✅ | ✅ | ✅ | ✅ |
| Customer **history** (D20) | ✅ own | ✅ | ✅ before accepting | ✅ |

> **Enforcement note.** The split is private today only because no customer-facing screen renders
> it — **no rule prevents it**. Any future serializer that eager-loads the commission agreement
> would leak it silently and nothing would fail.

---

## 9a. Abuse cases and the rules that close them

Each rule below exists because of a concrete attack found in adversarial review. Losing this
table loses the reason several rules exist.

| # | Attack | Closing rule |
|---|---|---|
| A1 | A rate is moved after the customer committed, and the difference is billed | §4 — no rate moves without a fresh customer acceptance |
| A2 | The Suchak opens the acceptance link himself and manufactures a ₹1,00,000 obligation | §8 tiers + Phase 6 OTP. Until then the record must not claim a verification that did not happen |
| A3 | Meetings are logged that never happened, and customer silence bills the family | §7.2 — silence opens a dispute; no fee is due without confirmation |
| A4 | Farming: repeated low-value meetings against the same pair | One billable meeting per candidate pair per day. Re-visits are legitimate (D24) but each one still needs the customer's approval, which is what makes farming visible rather than automatic |
| A5 | Dumping profiles so any later marriage triggers the 12-month clause | D11 — the clause binds from **Viewed**, plus a monthly cap on binding views |
| A6 | The clause traps a family who already knew the other family | A one-tap **"we already know them"** flag at view time removes the binding for that profile |
| A7 | Declaring 70/30 and never paying | The **share-settled** stage, markable only by the helper, plus a public *realized-vs-declared* ratio on every declarer's card |
| A8 | Withdrawing or re-publishing a challenge to escape a declared share | The share **sticks to candidates already suggested under it** for the full 12 months; a live split cannot be edited for those candidates |
| A13 | The family meets a candidate through the Suchak, then goes back to that family directly for every later meeting and pays nothing for the arranging | D24 makes an arranged re-visit chargeable. It does **not** make direct contact chargeable — that would be unenforceable and would punish families for talking to each other. What protects the Suchak on the real prize is D11/D21: a marriage within 12 months to a **viewed** profile still owes the success fee, however the later contact happened |
| A9 | Unbounded meeting-fee exposure on a poor family | Closed by the flow itself — a meeting exists only if the customer approved it, individually. The running total at approval (D17) makes the cost visible without taking the decision away |
| A10 | One person running two Suchak accounts and colluding | Same-account pairing is already blocked; tie marketplace participation to the **verification badge** |
| A11 | A customer takes many meetings and rejects everything | D20 — customer history visible to a Suchak **before** accepting a challenge |
| A12 | Adverse selection: only hard candidates ever get published | Show each listing's marketplace history (days since registration, times published, helpers who left). The declared share then becomes an honest screening signal |

---

## 10. Must ship before Phase 1

| # | Item | Requirement |
|---|---|---|
| **S4** | **The OTP channel does not exist.** Verified on production 2026-08-01: `APP_ENV=production`, `mobile_verification_mode='dev_show'`, and **no WhatsApp credentials at all** (`access_token`, `phone_number_id`, `otp_template_name` all empty). `POST /suchak/register/start` therefore returns the plaintext OTP in its response, and today's OTP verification stops nobody | **This is the single dependency that unblocks everything else.** It is a non-engineering track owned by the product owner: WhatsApp Business credentials + an approved OTP template (and, if SMS is ever wanted, TRAI DLT entity/header/template — 3–6 weeks). The setting cannot simply be switched off first: with no delivery channel, that breaks login entirely |
| S1 | Two independent plaintext-OTP paths: `MobileOtpService::shouldExposeDebugOtp()` (env + config + admin setting) and `SuchakRegistrationService::issueOtp()` (**AdminSetting only, defaulting to `dev_show`, no environment guard**) | The moment S4 lands, both must become impossible in production. Consent/agreement OTPs get their own purpose that hard-codes the debug value to null and **fails closed** |
| S2 | `POST /api/v1/auth/mobile-otp/send` has **no throttle middleware** | Rate-limit it before OTP guards any money |
| S3 | `resolveDeliveryMode()` returns only `dev` or `whatsapp` — **no SMS provider exists**. Its non-production fallback is `dev` | Assert `APP_ENV=production` at deploy. A typo silently downgrades to plaintext OTP |
| S5 | Cross-Suchak reads return the exact village under key `city` with a hardcoded `is_broad => true` — a **false label** | The flag is a bug whatever the policy is: it must never claim "broad" while carrying a village. Under D19a the village is hidden by default and revealable by the originating Suchak, so the flag must report what was actually sent |
| S6 | Name, village, address and mobile leak inconsistently across cross-Suchak paths — some mask, some do not | One rule, one place: the four defaults of D19a applied to **every** cross-Suchak read, with the originating Suchak's per-candidate overrides on top. Reuse `SuchakCandidateMaskingService` / `CandidateNameMask`; do not add a second masking path |

---

## 11. Build order

| Phase | Contents | Gate |
|---|---|---|
| **0** | S5, S6. **Start S4 immediately as a parallel non-engineering track** — every later phase's evidentiary strength waits on it | No village or full-name leak on candidates who never entered the marketplace |
| **1** | Copy the fees onto the package and into the hashed snapshot (§5.3); the engagement object (§6.1); customer-side acceptance page over the tokenised link; allow-list snapshot at proposal time; online-fee column; installments; per-meeting approval showing the running total | **A customer can accept a complete price agreement, and the acceptance actually freezes it.** Fold the hash change into the acceptance commit — alone it is invisible and will be deferred |
| **1a** | Meeting engine unblock: drop `unique(pipeline_id)`, add `meeting_sequence`, `helper_suchak_account_id`, `fee_amount`, mode; **expose the four missing routes** (B2) | More than one meeting per pair is possible, and a meeting can actually reach `confirmed` outside a test |
| **2** | The challenge object; marketplace listing (D18/D19); accept-by-proposing (reuse `createRequest`, reversed) | A Suchak can publish; another can propose |
| **3** | Viewed / Interested; the 12-month clause (D11, D21); dispute routes + stop-loss + lapse (§7.2) | The trail exists for a dispute a year later |
| **4** | Marriage outcome + success attribution (§6.2); cross-Suchak owed-vs-paid | The largest sum in the system has an owner |
| **5** | Behavioural readers: Suchak reputation, customer history (D20); per-candidate proposal inbox; market economics view | The market can sort itself |
| **6** | **OTP** (D23), once S4 has landed: S1–S3 closed, OTP on acceptance, real `submitted_mobile` and honestly computed `mobile_match`, and the tier→money gates of §8 switched from *recorded* to *enforced* | An acceptance proves who acted. Until this phase, every tier claim in §8 is a record of what was attempted, not proof of who agreed — and the product must say so |

**Smallest slice worth putting in front of ten real Suchaks:** Phase 1 + a manually-published
challenge. It tests the only premise that matters — *will a Suchak open their own customer to a
competitor for a declared share?* — before any of Phases 2–5 is built.

---

## 12. Explicitly not in scope

- The platform holding **customer** money.
- Admin adjudication of individual disputes.
- Proposing unregistered people (D8).
- Paper artifacts of any kind (D22).
- Any second suggestion, ledger, consent, agreement, meeting or dispute engine.
- A second 0–100 Suchak score. `SuchakQualityControlService::qualitySummary()` already computes
  one (a compliance/restriction score). A behavioural score is a genuinely different fact and
  must be named differently, or the two will be confused in every conversation.

---

## 13. Known traps

| Trap | Why it bites |
|---|---|
| `user_match_behaviors` | Looks like the natural home for behavioural history. **Cannot carry a Suchak actor** |
| `suchak_customer_agreement_stages` | Looks like a home for installments. Service-delivery stages, no money column |
| `STATUS_CANCELLED` on visits | Declared but never written, and there is no cancel route. A "cancelled by whom" metric is impossible today |
| `SuchakQualityControlService` | Named like a quality engine; is an admin-restriction risk score, derived-on-read |
| `AdminDashboardMetricsService` | Platform-wide only, never scoped to a Suchak or customer. Not extendable into one |
| Paper's mobile contradiction | The paper path requires a mobile yet never verifies it — evidence that it was never an acceptance grade |

---

## 14. Registration in other documents

On freeze:

- `FIELD-OWNERSHIP-MAP.md` — one row per §5.5 item, in the same commit that creates it.
- `PRODUCT_MAP.md` — a new subsystem section; dated rows for D1–D22; and an explicit dated
  amendment recording that **the village rule now has a marketplace exception** (D19). That rule
  is frozen and must be changed in the open, never silently.
- `MOBILE_API_CONTRACT.md` — every new endpoint before the Suchak app consumes it.

---

## 15. Corrections from v0.1

| v0.1 said | Correct |
|---|---|
| Build a meeting → claim → dispute → hold flow | It **already exists**: `suchak_visit_confirmations` + `SuchakDispute` + `SuchakPayoutHold`, wired together. Only four HTTP routes and four columns are missing |
| "The platform never holds money; every rule produces evidence, not enforcement" | Wrong. PayU and payout holds ship today. The platform holds no **customer** money, but it controls Suchak payouts — which is the real lever |
| "No confirmation in 7 days = ₹0" | Silence is not a refusal. Silence opens a dispute. (Product owner's correction) |
| Two-step profile reveal (screen, then unlock) | Rejected. Accepting is a commitment; a commitment on partial information is a bad one. Full profile before accepting (D19). (Product owner's correction) |
| Rate at meeting time, same for all | The accepted agreement governs. The marketplace is a *later, separate* occasion, so nothing is retro-priced. (Product owner's clarification) |
| Drop customer reputation — one-shot player, no future exposure | Wrong question. The signal exists to **inform the helping Suchak** during a six-month, ten-meeting window, not to deter the family. History, not rating (D20). (Product owner's correction) |
| A paper tier for customers without a phone | Everyone has a phone, and the paper evidence is unreadable by anyone as built. All digital (D22). (Product owner's correction) |
| Tiers gate marketplace visibility | Tiers must gate **money** too, or a ₹1,00,000 claim rests on a Suchak's word (§8) |
| Masking protects the candidate from other Suchaks | Backwards. This is a matchmaker platform: too little information means no match can be made at all, and the photograph is the one item without which nobody can decide. The consent the candidate already signed says the profile may be **"forwarded to suitable and appropriate matches"** — showing it to another matchmaker is exactly that. Four defaults hidden, everything else shown, the Suchak overrides (D19a). (Product owner's correction) |
| A declared ceiling on chargeable meetings protects the family | It protects nothing — a meeting only exists if the family approved it, one at a time — and it reads as a quota to be filled. How many people to meet is their decision alone. Replaced by a running total shown at approval (D17). (Product owner's correction) |
| OTP is a Phase 1 line item | It cannot be: the delivery channel does not exist on production and switching the current setting off would break login. OTP is Phase 6, gated on S4 (D23) |
| Village is legitimate only for a marketplace listing | Narrower than needed. Village, address and mobile are each the **originating Suchak's per-candidate choice** — he knows the family, the platform does not (D19a) |

---

## 16. Client impact

**Suchak app** — new: agreement builder with four fee inputs, challenge publish with declared
share, marketplace browse and accept-by-proposing, per-candidate proposal inbox, meeting log with
confirm/dispute, reputation profile. **Changed:** the two existing offline-signed-proof surfaces
are removed (D22).

**Member app** — **not affected.** The marketplace is Suchak-only and the customer accepts over a
public link precisely because they may have no login.
