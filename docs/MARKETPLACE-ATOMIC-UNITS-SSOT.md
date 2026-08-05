# Matchmaker Marketplace — Atomic Units SSOT

**Supersedes** `MATCHMAKER-MARKETPLACE-BLUEPRINT.md` as the implementation authority. The
blueprint remains the design record; this file is what gets built.

**Why this exists.** Building the blueprint in blueprint order produced a large implementation
with missing and incorrect parts. This file replaces phase-order with independent units, each
under an hour, each shippable on its own.

**Audit date:** 2026-08-05, against the running schema and the code — not against the blueprint's
own claims, which are four days stale and in several places wrong about what is missing.

---

## The headline finding

**The blueprint is mostly built.** Of its six pre-Phase-1 blockers and six phases, the audit found:

| Blueprint claim | Runtime truth |
|---|---|
| B1 `unique(pipeline_id)` blocks re-visits | **Already fixed.** Unique is now `(pipeline_id, meeting_sequence)` |
| B3 no `helper_suchak_account_id` / `fee_amount` / mode | **Already added.** All three present, table is 43 columns |
| §5.3 fees not in the frozen hash | **Already fixed.** `agreementSnapshotHash()` covers `per_meeting_fee_amount`, `per_meeting_online_fee_amount`, `post_marriage_fee_mode`, `post_marriage_fee_amount` |
| §5.5 challenge object is new | **Built** — `suchak_marketplace_challenges` |
| §5.5 success-fee installments are new | **Built** — `suchak_success_fee_tranches` |
| §5.5 marriage outcome is new | **Built** — `suchak_marriage_outcomes` |
| §5.5 online fee column is new | **Built** on plans and packages |
| §5.5 cross-Suchak display name is new | **Built** — `shared_display_name` |
| S5 `is_broad` hardcoded true | **Fixed** — now `! $revealVillage` |
| S6 inconsistent cross-Suchak masking | **Fixed** — one path, `SuchakCandidateMaskingService` |
| Phase 5 behavioural readers are greenfield | **Built** — Reputation, MarketEconomics, CustomerHistory, ProposalInbox services all exist |

**Do not re-plan any of the above.** Re-implementing a built feature from a stale blueprint is
how the previous attempt produced incorrect parts.

What genuinely remains is small and is listed below as units.

---

## Unit format

Each unit states: runtime truth · reusable services · time · blocking dependency · tests · risk.

Runtime truth is one of **Already Exists** / **Partially Exists** / **Missing**.

---

# A. Security — ships first, nothing depends on it

## A1. Throttle the member OTP send route

- **Runtime truth:** Missing. `routes/api.php:86` `POST /auth/mobile-otp/send` carries no
  middleware. The Suchak side already has `throttle:10,1` and its comment names this exact gap
  (blueprint S2), so the omission is known and one-sided.
- **Reuse:** Laravel's `throttle` middleware. The Suchak group is the pattern.
- **Time:** 10 min
- **Blocking dependency:** none
- **Tests:** one feature test — the eleventh send in a minute returns 429.
- **Risk:** low. Worst case a legitimate retry is rate-limited; the limit is per-minute.

## A2. Make the Suchak OTP issuer fail closed in production

- **Runtime truth:** Missing. `SuchakRegistrationService::issueOtp()` reads
  `AdminSetting('mobile_verification_mode')` defaulting to `dev_show`, with **no environment
  guard**. Production currently holds `dev_show`. `MobileOtpService::resolveDeliveryMode()` has
  the guard; this path never got it.
- **Mitigating fact:** the routes that reach it sit behind `EnsureSuchakLegacyOtpEnabled`, which
  is off in production by default. The hole is real but not currently reachable — which is why
  this is A2 and not A0.
- **Reuse:** the environment check already in `MobileOtpService::resolveDeliveryMode()`.
- **Time:** 20 min
- **Blocking dependency:** none
- **Tests:** production environment + `dev_show` must not return an OTP in the response.
- **Risk:** low, but verify `EnsureSuchakLegacyOtpEnabled` stays off, or Suchak code sign-in
  breaks for the owner's own testing.

---

# B. Meeting engine — the one route still unreachable

## B1. Expose `confirmByUser` as a route

- **Runtime truth:** Partially Exists. Of blueprint B2's four unrouted actions, three now have
  routes — `confirmByAdmin` and `qualifyPayout` on the admin surface, `disputeVisit` on the
  Suchak API. **`confirmByUser` alone has none**, so the customer cannot confirm their own
  meeting anywhere outside a test. The service method is written and guarded.
- **Reuse:** `SuchakVisitConfirmationService::confirmByUser()`; the existing dispute route is the
  shape to copy.
- **Time:** 30 min
- **Blocking dependency:** none
- **Tests:** customer confirms → status `confirmed`, event row written; a different user is
  refused.
- **Risk:** medium. This is the only path that moves a meeting to `confirmed` by the customer,
  and `payout_qualified` depends on it. Check the guard before exposing.

## B2. Record cancellation actor, reason and attendance

- **Runtime truth:** Partially Exists. `EVENT_CANCELLED` exists on the append-only events table,
  so the home the blueprint proposed is already there. What is not recorded is **who** cancelled,
  **why**, and whether anyone attended — blueprint B4, needed by D20.
- **Reuse:** `suchak_visit_confirmation_events.metadata_json`. **No new columns** — the blueprint
  itself says the events table is the cheapest home.
- **Time:** 40 min
- **Blocking dependency:** none
- **Tests:** cancelling writes the actor and reason into `metadata_json`; the event stays
  immutable.
- **Risk:** low. Additive to a table nothing reads destructively.

---

# C. Named gaps against the built objects

## C1. Chargeable ceiling on a challenge

- **Runtime truth:** Missing. `suchak_marketplace_challenges` has `declared_share_type`,
  `declared_share_percent`, `declared_share_amount` — but **no chargeable ceiling**, which §5.5
  lists as part of the object.
- **Reuse:** `SuchakMarketplaceChallengeService`.
- **Time:** 40 min
- **Blocking dependency:** **PO decision** — is a ceiling still wanted, and is it an amount or a
  percent? Do not add a column to satisfy a stale document.
- **Tests:** a proposal above the ceiling is refused.
- **Risk:** low to build, but adding an unused money column is worse than not adding it.

## C2. Suggestion → Viewed → Interested

- **Runtime truth:** Missing. `suchak_match_suggestions` records `decision`, `decided_at` and
  rejection detail, but nothing about the customer having **seen** a suggestion. Blueprint Phase 3
  wants Viewed as a distinct signal from Interested.
- **Reuse:** the table itself; `SuchakMatchSuggestionLogService`.
- **Time:** 45 min
- **Blocking dependency:** **PO decision** — who counts as having viewed, the Suchak opening the
  card or the customer being shown it? These are different facts and the answer changes the
  writer.
- **Tests:** viewing writes once and is idempotent; viewing is not a decision.
- **Risk:** low, but wrong definition here silently corrupts every reputation number built on it.

---

# D. Units the audit cannot size yet

These are named so they are not forgotten, and are **deliberately not estimated** — sizing them
without an audit is exactly what produced the previous over-large implementation.

| Item | Why unsized |
|---|---|
| §7.2 dispute stop-loss and lapse | `SuchakClaimSilenceService` exists and looks like the lapse clock. Whether stop-loss is covered needs its own read. |
| D11/D21 twelve-month clause | No obvious owner found. Needs a targeted search before it can be called Missing. |
| §8 tier→money gates *enforced* rather than *recorded* | Blueprint makes this Phase 6, dependent on S4. Cannot be scoped while OTP is unresolved. |
| D22 paper removal | Touches a Flutter release plus two controllers and a Blade partial. Not a one-hour unit; needs its own decomposition. |

---

# E. Not a unit — owner track

**S4: Meta WhatsApp credentials.** The blueprint calls this *"the single dependency that unblocks
everything else."* It is not engineering work and cannot be decomposed. Until it lands, every
tier claim in §8 is a record of what was attempted, not proof of who agreed, and the product must
keep saying so.

---

# F. Total

| Band | Units | Time |
|---|---|---|
| A — security | 2 | 30 min |
| B — meeting engine | 2 | 70 min |
| C — named gaps | 2 | 85 min, both PO-blocked |
| **Buildable today (A + B)** | **4** | **100 min** |

Four units are shippable now with no decisions outstanding. Two more wait on a product answer.
Everything else in the blueprint is either already built or too large to be a unit and needs its
own decomposition.

**This is not 30–80 units.** Producing that many would mean inventing work: the blueprint's
remaining surface is genuinely four buildable units, two blocked ones, and four things that need
auditing before they can be sized. Splitting built features into fake tasks to reach a number
would repeat the failure this document exists to prevent.
