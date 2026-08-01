# Navri Matrimony — Product Map

> Purpose: full-product knowledge map for AI agents and developers, so decisions are made from
> verified code facts, not assumptions. Built from a three-track deep read on 2026-07-22
> (consent/contacts, Suchak module + member engines, both Flutter apps), refreshed 2026-07-26
> after the payments / consent-first / matching-engine wave.
> Update this file when architecture or product decisions change — stale rows here cause the
> exact class of mistakes it exists to prevent.
>
> Status vocabulary used throughout: **DONE** (merged, tested), **IN PROGRESS** (being built in a
> live session — do not assume the shape is final), **DECIDED** (product call made, not built).

## 1. Product & actors

- **Member** — a matrimony user managing their own profile (`flutter-apk` app + web).
- **Suchak** — a matchmaker/operator who creates and manages candidate profiles on behalf of
  real people (`Suchak-apk` app + web). Free by default; capabilities unlocked by paid plans.
- **Admin/staff** — web-only: bulk biodata intake (OCR), review/approve, governance.

Core objects: `User`, `MatrimonyProfile` (+ sub-record tables), `SuchakAccount`,
`SuchakProfileRepresentation` (Suchak↔profile link), `SuchakCustomerContext` (CRM lifecycle),
`SuchakConsent`, `BiodataIntake` (OCR pipeline), `SuchakPlan`/`SuchakSubscription` (platform
billing), `SuchakCustomerPlan` (Suchak's own reusable customer plans — §3a),
`SuchakPaymentRequest` (Track A collection + tracking), `SuchakMatchSuggestion` (append-only
suggestion/decision log — §6a).

## 2. Repos & surfaces

| Surface | Where |
|---|---|
| Laravel backend (SSOT) | `laravel-matrimony` — web member, web suchak, web admin, `/api/v1` member + suchak APIs |
| Member Android app | `flutter-apk` (package `flutter_matrimony_android`) |
| Suchak Android app | `Suchak-apk` (package `suchak_apk`) |

Doc hierarchy and working rules: see root `CLAUDE.md`. Laravel operating contract:
`laravel-matrimony/docs/DEVELOPER-OPERATING-CONTRACT.md`. Field/engine ownership (the
no-duplicate check): `laravel-matrimony/docs/FIELD-OWNERSHIP-MAP.md`.

Suchak API routes live in `routes/api/suchak.php` (member routes in `routes/api/member.php`).

## 3. Suchak domain

**Representation** (`SuchakProfileRepresentation`): statuses `pending, consent_pending, active,
revoked, expired, rejected, suspended, candidate_deactivated`; modes include
`manual_form_by_suchak`, `uploaded_by_suchak`, `matched_existing_profile`. Consent status is
denormalized on the row (`not_requested … revoked`). Delete-proof model (delete() throws).

**Creation paths** (all in `SuchakRepresentationService`, all inside lockForUpdate, all reject
same-account+same-profile duplicates):
1. Manual wizard → `createPendingManualProfile`
2. OCR source-link (admin approves intake) → `createPendingFromSourceLink`
3. Link existing member by mobile → `createPendingMatchedExistingProfile`
   — **since 2026-07-26 this creates only a pending CLAIM, not a link.** See §4A.

**Pending consent claim** (DONE 2026-07-26) — the single most important behavioural change of
the day. `isPendingConsentClaim()` = `representation_mode ∈ CONSENT_GATED_EDIT_MODES`
(today: `matched_existing_profile` only) **and** `! hasValidConsent()`. While true, the row
behaves as if it did not exist:
- invisible in customer list / detail / share card / dashboard
  (`scopeExcludingPendingConsentClaims`);
- `suchakMayReadProfile()` and `suchakMayEditProfile()` both false → **403 `consent_required`
  on reads AND writes** (PUT profile, save-step, photo, preferences auto-draft), enforced once in
  `authorizedContext(forWrite:)`;
- no `SuchakCustomerContext` is created — a non-consenting person is not a customer.

Accepting consent runs `promoteConsentedClaimToCustomer()` (idempotent, inside all three
acceptance paths: public decision page, OTP accept, manual proof upload) and the same row becomes
the real representation. `rejected / expired / revoked` simply stay a claim — no teardown.
The web `ManualProfileController` was closed the same way.

**Slot limit**: `SuchakLimitService::assertActiveProfileSlotAvailable` counts
pending+consent_pending+active against plan entitlement `active_profile_limit`.

**Manual create validation** (`SuchakManualProfileApiController::store`): candidate_name required;
candidate_gender required (master_genders key); registering_for in
self/parent_guardian/sibling/relative/friend/other. Mobile normalized via
`MobileNumber::normalize` (10-digit Indian). Existing-mobile → 422 (no profile) / 409
(`existing_profile_confirmation_required`) / consent-first claim (outcome `consent_requested`,
`linked:false`, `pending_claim:true`, plus the consent hand-off block).

**Duplicate check** (`SuchakCandidateDuplicateCheckService`, DONE 2026-07-26):
- classifies each match's `owner_type` — `mine` / `other_suchak` (**only when that rival
  representation holds valid consent** — see §4A rival rule) / `platform_member` (self-registered,
  verified) / `unrepresented`. Bulk-loaded, no per-match queries.
- Recall improvements: works without a DOB (name-token pass capped at `low` confidence),
  birth-year ±1 window, deterministic ordered passes so the 300-row cap can't drop the real match,
  optional `location_id`/`caste_id` soft signals, `low` tier + `is_hard_stop`/`hard_stop` so the
  client knows when to stop onboarding.
- Name masking lives once, in `App\Support\CandidateNameMask`.

**Plans / "premium"**: no boolean premium flag. Two *different* things share the word "plan" —
never conflate them:

| Table | Meaning |
|---|---|
| `suchak_plans` + `suchak_plan_features` | **Platform subscription catalog** — what the Suchak buys from us (Track B) |
| `suchak_customer_plans` | **Per-Suchak reusable customer plans** — what the Suchak sells to a family (Track A, §3a) |

Platform feature keys: `active_profile_limit, monthly_upload_limit, lead_request_limit,
collaboration_request_limit, pdf_download_share_limit, ledger_features, crm_features,
priority_support, bulk_upload_access`.
**OCR/biodata intake (`POST /suchak/intakes`) is paid-gated**: 403 unless
`SuchakPaymentStatusService::activeSubscriptionFor(account)` returns a live subscription.

**Customer rows** (`SuchakCustomerListService`, used by list + detail APIs): identity, photo, age,
consent_label/status + action flags (`can_request_consent`, `can_renew_consent`,
`pending_consent_id`), lifecycle_label, profile completion, Paid/Free (Track A) chip,
`view_url`/`edit_url`/`manage_url`. Pending consent claims are excluded from every one of these.

## 3a. Suchak → customer payments (Track A) — DONE 2026-07-25/26

Track A = the Suchak collects money from the family. Track B (Suchak pays the platform: plans,
PayU checkout, billing status) is unchanged.

**Reusable customer plans** — new table `suchak_customer_plans`, model `SuchakCustomerPlan`,
engine `SuchakCustomerPlanService`, API `/suchak/customer-plans` (deliberately **not**
`/suchak/plans`, which is the platform catalog).
- `preset_key` NULL = a fully custom reusable plan. `preset_key` `basic`/`premium` = an
  **override row** for a code-defined preset in `App\Modules\Suchak\Support\SuchakDefaultPlans`
  (Basic ₹2000 / Premium ₹5000, auto-published). Presets stay code-defined; DB rows only override
  or add. Unique on (suchak_account_id, preset_key) — NULLs are distinct, so many customs are fine.
- `services_json` is the **only** JSON column (`[{name, name_mr}]`). Duration
  (`six_months / one_year / till_marriage`), the two fees, discount "was" price, `private_note`,
  `is_visible`, `sort_order` are typed columns.
- Service: CRUD, transactional reorder, toggleVisibility, upsertPresetOverride, `resolveCarousel`
  (code presets + overrides + visible customs, ordered), `resolveForManagement`, last-visible-plan
  guard, presets not deletable. `private_note` is never exposed to a customer.
- **Send-time model is unchanged**: a chosen plan still materializes a `SuchakServicePackage` via
  the existing `createCustomPackage()`, with **no FK back** to `suchak_customer_plans`.
- ⚠ Backend supports a preset **price** override and a test pins it — but the **app deliberately
  no longer exposes it** (decision 2026-07-25): in the app a preset is hide/reorder only. For a
  different price, make a custom plan.

**The two opt-in fees are DISCLOSED NOTES, never money owed.** `per_meeting_fee_amount` and
`post_marriage_fee_mode/amount` (`as_wished / fixed / none`) are rendered on the plan card and in
the WhatsApp message so the family knows up front — they are **never** added to `amount_due`,
which comes only from the agreement/plan price. Verified: nothing in
`SuchakPaymentRequestService` or `SuchakPaymentRequestsApiController` sums a fee into `amount_due`.
- **Set once in the plan, never re-entered per send (fixed 2026-07-26).** The payment-options
  `default_plans` payload (`SuchakPaymentRequestOptionsApiController::carouselPlansPayload`) now
  also carries the plan's `duration`, `per_meeting_fee_amount`, `post_marriage_fee_mode` and
  `post_marriage_fee_amount` — additively; nothing renamed. Previously it dropped them even though
  `resolveCarousel` had them, so the app's send screen re-seeded from hardcoded defaults (1 year /
  both fee rows unchecked / ₹999 / "as wished"). App side: one shared `PlanFeeTerms`
  (`lib/features/payments/widgets/plan_shared_widgets.dart`) resolves "included" for BOTH the plan
  editor and the send screen — positive meeting fee = opted in; post-marriage mode `fixed`/
  `as_wished` = opted in, `none`/null = out. Fees stay OPT-IN; presets send all four keys as null.

**Sending a request**:
- Records are created **only on send**, not on preview (a preview never wrote a package).
- `acceptOrReviseTerms()` auto-supersedes a stale pending agreement with a fresh revision instead
  of throwing the old "create a new agreement revision" 422.
- The package lookup is scoped to the **selected** plan by `package_name`, so a different plan's
  package can never be silently reused.
- Terms are a **per-request** choice ("customer accepted terms"), settled via `acceptTerms` —
  not an admin bypass, and not a global policy flip (that flip was reverted).
- The WhatsApp message attaches the **UPI QR image**: `SuchakQrCodeImageService::pngBytes()` +
  read-only route `GET /suchak/payment-requests/{token}/qr.png` (512px, `upi://pay?pa=…&am=…`),
  wired into page-specific og:image so WhatsApp unfurls the QR. Falls back to the uploaded
  `payment_qr`, else **no image at all** — never the site homepage kalash. The preview crawler
  uses `findPublicRequestForAsset()` so fetching the QR never flips SENT → OPENED.

**Payment-received tracking feed** — built on the EXISTING `SuchakPaymentRequest`,
**zero new tables/columns/migrations**. Read model: `SuchakPaymentRequestTrackingService`.
- `GET /suchak/payment-requests` — list (customer name + mobile, plan, amount, status, opened?,
  paid?, sent_at), search by name/mobile, filter pending/paid/all, pagination, and a summary.
  One row per customer (latest request via `ROW_NUMBER() OVER (PARTITION BY customer_context_id …)`)
  so repeat requests neither duplicate rows nor inflate the amount due.
- `POST /{id}/mark-paid` — works without a proof reference (Track A self-confirmation): the
  payment record / ledger / invoice / receipt / activity log are all created and the request
  becomes paid, but with `proof_status = required` so it stays flagged in the Cash/Proof risk
  review queue. Audit is deferred, not lost. Note persists as `collection_note`.
- `POST /{id}/reverse-paid` — mandatory non-empty reason, reverses paid → opened, audited via the
  existing `suchak_payment_request_events` + `SuchakActivityLog`. A deliberate correction, not a
  one-tap undo. Full ledger reversal stays owned by `SuchakCustomerPaymentCorrectionService`.

## 4. Consent — TWO distinct systems (do not conflate)

**A. Suchak customer consent** (`SuchakConsent` + `SuchakConsentService`):
- Statuses: `requested → link_opened → otp_sent → otp_verified → accepted` plus
  `rejected / expired / cancelled / revoked`. Types: one_year / two_year / until_revoked.
- **CONSENT-FIRST (PO rule 2026-07-26, DONE).** Asking to represent an existing person creates
  only a pending claim (§3). The representation becomes real **only** when consent is accepted.
  Nobody is attached to a Suchak before agreeing.
- **Rival rule (PO ruling 2026-07-26, DONE — this was widened then deliberately reverted).**
  Another Suchak blocks a consent request **only when they hold a representation with VALID
  CONSENT** (`scopeWithValidConsent()`, nothing looser). Merely having the person in their
  customer list — *including a profile that Suchak typed in themselves* — does **not** count as
  managing them and must not stop anyone else from asking that person for permission. Permission
  from the customer is the whole point. When a rival *does* hold consent: 409
  `represented_by_other_suchak` with a named Marathi message, and duplicate-check's
  `can_link_existing` is false.
- **Pending-consent list** — `GET /suchak/consent-requests`
  (`SuchakPendingConsentListService`, `scopeOnlyPendingConsentClaims`): a claim is invisible
  everywhere by design, so without this a lost response was a dead end. Read-only, no new table;
  returns masked name + masked mobile, consent status, requested/expires, `can_resend`.
- **Consent hand-off**: linking returns `consent_id/status/url/forward_message/whatsapp_url/reused`
  using the same field names as the customer-detail consent sheet; `whatsappShareUrl` lives once,
  in `SuchakConsentService`.
- Number targeting: `intended_mobile` is **manually chosen by the Suchak per request**
  (required in `SuchakConsentsApiController`); `submitted_mobile` + `mobile_match` recorded.
- 7-day token expiry; sha256-hashed token; public web link (`/consent/{token}`) with accept/reject;
  OTP path via WhatsApp (`MetaWhatsAppCloudService`, dev_show mode for testing).
- Manual (offline) signed-consent upload exists in the app for the paper path.
- **Fallback suggestions (2026-07-22)**: `GET /suchak/nxt/{representation}/consent-contacts`
  (`SuchakConsentContactSuggestionService`) lists every number already stored on the profile in
  try-order with owner labels + masked digits, flags `already_tried` from consent history, and
  sets `suggest_alternate` after `whatsapp.bulk_consent_no_response_hours` (72h) of silence.
  Suggests only — never auto-sends. Consent may target **only** stored numbers.
- Read-only consent audit trail on customer detail.
- Schema note: the status column is `consent_status`, **not** `status`.

**B. Bulk-intake WhatsApp permission** (admin pipeline, `App\Services\Intake`):
- `BulkIntakeCandidateContactPlanService` builds a **role-ordered queue**:
  SELF → FATHER (`father_contact_1..3` in snapshot) → MOTHER → OTHER_FAMILY → OCR_OTHER,
  excluding Suchak/bureau header numbers. Plan persisted in `item_meta_json->consent_contact_plan`.
- `BulkIntakeWhatsAppConsentService` sends to `activeMobile()`; on no-response/wrong-number,
  `advanceAfterAttemptFailure` increments `active_index` → next number; exhausted → `contacts_exhausted`.
- `bulk-intake:whatsapp-consent-no-response` command auto-advances after
  `whatsapp.bulk_consent_no_response_hours` (default **72h**) of silence.
- This is the existing "try another number after a period" engine — reuse its queue/role logic
  when building the Suchak-side fallback suggestions.

## 5. Contact-number ownership model (who owns which number)

Ownership is already modeled — three complementary mechanisms:

| Store | Ownership semantics |
|---|---|
| `profile_contacts` (DB-table access, no Eloquent model) | **Canonical.** `contact_relation_id` + `contact_name` + `is_primary` + `visibility_rule` + `verified_status`. Primary contact = candidate's own number (falls back to `user.mobile`) |
| `matrimony_profiles.father_contact_1/2`, `mother_contact_1/2` | Owner encoded in column name (slot 3 columns were dropped) |
| `profile_siblings.contact_number/_2/_3` | Owner = the sibling row (relation_type + name) |
| `profile_relatives.contact_number` | Owner = the relative row (relation_type + relative_details) |

⚠ **Trap (cost a prod 500 on 2026-07-26):** the relation column on `profile_contacts` is
`contact_relation_id`. `relation_type` lives on `profile_siblings`, **not** on `profile_contacts` —
reading it there throws SQLSTATE 42S22.

**No contact columns exist on**: `profile_marriages`, `profile_addresses`,
`profile_alliance_networks`. Legacy `matrimony_profiles.contact_number` column was **dropped**;
`contact_number` is now a virtual accessor over `profile_contacts`.
(`docs/CONTACT_NUMBER_USAGE_MAP.md` predates that drop — partially stale.)

✅ **Resolved 2026-07-22 (Laravel side):** validation had been opened for marriage/address/
alliance contact keys which have **no columns** — silently dropped at the MutationService
column-intersect. Those keys are `prohibited` again (honest 422 beats silent loss), and
`relatives.*` narrowed to its single real `contact_number`. Sibling/relative/parent slots +
`profile_contacts` cover the real (consent-fallback) use case. Flutter's dead inputs for
marriage/address/alliance were removed 2026-07-22.

## 6. Member profile engines (Laravel)

- **Web wizard**: `ProfileWizardController`; the dependent-marital UI is
  `resources/views/matrimony/profile/wizard/sections/marital_engine.blade.php` ("MaritalEngine:
  single canonical UI"), included by wizard basic_info, onboarding step2, bulk-intake register.
  Year-sanity rules (divorce/separation/death ≥ marriage, no future) live in
  `ProfileWizardController::buildMarriagesSnapshot` and are pinned by `MaritalEngineValidationTest`.
- **Mobile API PUT engine**: `MatrimonyProfileApiController::updateForProfile` — parallel marital
  implementation (`MOBILE_MARRIAGE_DETAIL_STATUS_KEYS`, per-status masking, never_married clearing).
  **Divergences vs web**: `remarriage_reason`/`notes` accepted then nulled.
  The dependent-status list `['divorced','annulled','separated','widowed']` is copy-pasted in ≥4 places.
- **Step snapshot service**: `MobileProfileStepSnapshotService` — steps
  `profile_for_whom, basic_info, religion_caste, location, education, career, lifestyle, family, astro`.
  `marriages` in a step payload → **422**; `children` accepted then dropped (only never_married
  clearing works). Own income lives in **career** step; family income in **family** step.
  Unknown keys hard-rejected → any client field-list must match exactly.
- **MutationService**: the governed write path (snapshot → tables + `profile_change_history`);
  column-intersects on write (unknown keys silently dropped at DB layer).
- **Completeness engines (one engine, shared)**: `ProfileCompletionEngine` (read SSOT, cached),
  `ProfileCompletenessService` (percentages, 70% search-visibility SQL),
  `ProfileCompletionService` (five-section weights + per-section completed/incomplete/warning).
  Also feeds the Suchak profile GET and the Suchak customer list. Do not write a second calculator.
- **Partner preferences**: pivots (`profile_preferred_*`) + `profile_preference_criteria` +
  `partner_preference_metadata`. **Defaulting engines exist**:
  `PartnerPreferenceSuggestionService` (age/height/marital/location/diet/religion/caste/income
  derived from own profile) + `RegistrationPartnerPreferenceService` (auto-draft at registration,
  refuses to overwrite manual edits). API: `/onboarding/preferences/auto-draft*`,
  `/profile/partner-preference-options`.
  ⚠ `RegistrationPartnerPreferenceService` **auto-seeds the seeker's own caste** into
  `profile_preferred_castes` at strictness `preferred`. Any engine reading that pivot must treat it
  as a default, not a stated demand — see the community-lock guard in §6a.
- **Minimum age** — `App\Support\MarriageAgePolicy` (2026-07-22): female 18 / male 21,
  future DOBs rejected. Wired into all three write surfaces and, since 2026-07-26, into every
  matching pool query as a never-relaxed floor.
  ⚠ Trap: `DB::table('master_genders')->whereKey($id)` silently matches nothing —
  the table has a column literally named `key`, so `whereKey()` becomes a dynamic where on it.
  Use explicit `where('id', …)`.
- **Marital dependency rules** — `App\Support\MaritalDependencyRules` (2026-07-22) is the
  canonical source: detail-status list, per-field applicability, cross-field year sanity.
- **Validation gaps still open**: `gender_mode` (ask/male/female per registering-for) is
  client-side advisory only — backend never cross-checks.

## 6a. Matching, ranking & suggestions — ONE engine (2026-07-26)

The largest change of 2026-07-26. **DONE unless marked otherwise.**

**Convergence.** The Suchak side previously carried three separate ad-hoc boolean heuristics
(same caste / same district / age gap ≤ 8) inside `SuchakCrossSearchService`,
`SuchakDailyOpportunityService` and `SuchakCollaborationService`. All three are **deleted**.
Everything now goes through `SuchakMatchFitService`, which owns **no scoring logic** and delegates
to `MatchingService::isEligiblePair()` / `computeMatchBreakdown()` — the same engine the member
feed uses. Do not reintroduce a Suchak-specific scorer.
- `CandidatePoolStrategy` makes the wider universe **opt-in** (`findMatchesForPool`): platform
  members + publicly-routable represented candidates + the caller's own book, excluding pending
  consent claims. `findMatches`/`findMatchesForTab` never set a pool and the persisted match cache
  is gated to the members pool, so member behaviour is provably unchanged (a test asserts it).
- Results pass through existing masking + scopes — a mobile is never in the payload.
- Actor boost/behaviour adjustments are skipped for a Suchak run (a dormant represented account
  has no activity of its own, and the Suchak's activity is about their work, not this pair);
  attribution is reported as `acting_actor`. The **candidate-intrinsic** quality delta still
  applies — strictly either/or with `applyBoost()`, because both together would double-count.

**Correctness defects fixed** (each was quietly wrong before):
1. **Hard filters were dead for sparse seekers** — `ensureDefaults()` sat inside a guard while
   religion/caste read from outside, so any seeker with no `profile_preference_criteria` row (the
   majority) had every hard filter disabled. Hoisted.
2. **Age was wrong four ways** — the max cohort was excluded (25–30 never showed a 30-year-old),
   min-only preferences were ignored, DOB-less profiles were deleted at SQL level, and
   `MarriageAgePolicy` was never consulted. Bounds are now inclusive and independent, DOB-null is
   graded *unknown*, and a gender-aware legal floor applies to every pool query and never relaxes.
3. **A taluka-only location preference matched nothing** and emptied the whole feed. Added
   exact/nearby-taluka and district branches, reusing `LocationService::nearbyTalukasByCoordinate()`
   behind `NearbyGeographyResolver` (a per-run memo, not a second haversine).
4. **Income and height were silent hard filters** — a near miss deleted the pair. Now a score
   penalty plus a visible warning; they exclude only when the seeker declared must-match. The same
   defect in `MobileMoreMatchesSectionService` ("looking for me" rows) was routed through the one
   shared `evaluatePreferenceBuild()` tolerance rule.
5. **Community intent was written but read by nobody** — `interested_in_intercaste` and
   `strictness_json` had no effect, so a different-religion candidate still scored community points.

**Community lock** — `App\Services\Matching\CommunityLockResolver`, config
`matching.community_lock`. Locks a seeker to their own community **only on an explicit signal**:
(1) the `profile_partner_community_flags` row EXISTS and `interested_in_intercaste = false`;
(2) `strictness_json` marks caste/religion required; (3) `profile_preferred_castes` holds only the
seeker's own caste — **and (3) is ignored whenever the metadata says `preferred`/`open`**, because
registration auto-seeds exactly that pivot and honouring it blindly would caste-lock the entire
base. The column is `boolean default(false)`, so "never asked" is byte-identical to "said no" —
an absent or defaulted row never locks anyone.

**Derived preferences** (owner-approved, config `matching.derived_preferences`): fill an empty
field only, never overwrite a stated value, are **soft** (can move a score, can never exclude) and
are flagged `assumed` for the UI. Age/height/education/marital/district assumptions are all in
config.

**Relaxation ladder** — `MatchRelaxationLadder` + `config/matching.relaxation`. The engine walks
tiers in order and stops at the **first** tier reaching `floor` (default 12, env-tunable). Every
returned row carries the tier it was admitted at; `lastRelaxationSummary()` reports the tier
reached plus which fields were loosened, so the UI states exactly what was relaxed instead of
silently widening.

| Tier | Relaxes |
|---|---|
| T0 | nothing (strict) |
| T1 | income, height |
| T2 | location (widens to nearby geography, 75 km / max 12 talukas) |
| T3 | caste (religion stays locked) |
| T4 | गुणमिलन — **IN PROGRESS**, uncommitted in a live session |

**Never relaxed at any tier**: opposite gender, the legal minimum marriage age, lifecycle
exclusions (suspended/married/archived), explicitly rejected pairs, religion.

**Ranking rebalanced — trust outranks money.** `MatchBoostService` had been reading the old
`match_boost_settings` singleton while the seeded, versioned, admin-editable
`matching_boost_rules` table was read by **no runtime code**. That table is now the single runtime
source (the singleton survives only as a fallback and the AI on/off switch). New weights
(`MatchBoostSettingDefaults`): verified_kyc 7, photo 7, completeness 0–6 graded, verified_mobile 5,
active/recency 5 (full ≤7 days, decaying to 0 at 180), similarity 3 — and the **commercial tier
totals at most 4** (premium 2 + gold_extra 2 + silver_extra 1); it used to be worth +12 while KYC,
photo and completeness bought nothing. Aggregate cap 20 → 25, and **paid is trimmed first** when
the cap binds, so a plan can never push a trust signal out. `explainBoost()` returns per-signal
reasons trimmed with the cap, so the listed reasons always sum to the applied delta. Reused
existing sources — no new tables. Activity = `max(users.last_seen_at, profile.updated_at)`, because
Suchak-created accounts never log in.

**Suggestion log** — new `suchak_match_suggestions` (`SuchakMatchSuggestion`,
written only by `SuchakMatchSuggestionLogService`). **Append-only**, recording both halves of one
fact: what was SHOWN (seeker, candidate, score + reason snapshot frozen at suggestion time) and
what the Suchak DECIDED (chosen / rejected + reason code + optional note / ignored). Idempotency
key = (seeker, candidate, `run_key`); the upsert updates only score/reasons so a re-render can
never erase a decision, and a later run appends a NEW row so history is never rewritten. This is
the foundation for per-Suchak learning.
⚠ Duplicate trap, checked and documented: `profile_matches` is a **replace-on-write cache**
(delete+reinsert, unique pair, no history, no decision); `user_match_behaviors` is member-user-keyed
with no record of what was offered; `profile_match_tab_skips/interests/shortlists` are single-signal,
one-row-per-pair, no Suchak actor. None of them can serve this purpose.

**Suggestions API** — `GET /suchak/representations/{rep}/suggestions` returns ranked, masked
matches (score, fit label, reasons, warnings, source, owning-Suchak label when the candidate is
someone else's). Rows are assembled field-by-field so the masked contact block is never copied.
`POST …/suggestions/{profile}/decision` records chosen/rejected/ignored, with a **required reason
code on rejection**; the next listing echoes the decision so a decided card never asks again.
Cooling is tiered: candidates suggested within 30 days are excluded outright, never-suggested ones
preferred, and only when nothing new remains do repeats return with `showing_cooled_off: true`.
Auth: the representation must belong to the caller and pass `suchakMayReadProfile` (a pending
claim 403s with the shared `consent_required` shape); an unsuggested pair 404s.

**Explainability speaks Marathi.** `lang/mr/preference_match.php`, `matching_engine.php` and
`match_boost.php` did not exist at all and are now created (each `array_merge`'d over the English
file so a future English key renders instead of a raw key); `lang/mr/matching.php` gained the keys
that were silently falling back; 10 `boost_reason_*` keys existed in **no** lang file, so the
service was always taking its hardcoded English fallback. The `*_assumed` reason family
deliberately denies the premise first ("हा आमचा अंदाज, त्यांची अट नव्हे") so a Suchak can never
mistake our guess for the family's stated condition, while the `_locked` pair says
"स्पष्टपणे सांगितली आहे". A coverage script asserts mr has every en key, is **not byte-identical**
(which would be an undetected fallback), keeps placeholders/plural forms, and contains **zero
Devanagari digits** (73 were found and fixed across 14 files — ३६ पैकी १८ → 36 पैकी 18).

**Performance** (the endpoint was timing out at the Cloudflare edge, HTTP 524):
52,943 ms / 10,882 queries → 1,537 ms / 1,208 queries on the worst representation; all 24
representations 297 s → 50 s, then 15.7 s after the geo fix. Causes were an unindexed
~670k-row village-centroid self-join, tier collection wiping the build/component caches on entry
(re-running everything ~4×), `fit()` flushing runtime caches per candidate (528 queries/candidate),
and 3,620 uncached `information_schema` round trips (now one `App\Support\SchemaPresence` helper).
`MatchingPipelineQueryBudgetTest` pins ≤2 pool fetches across 4 tiers, ≤3 bulk preference loads and
<12 queries/candidate; both guards were confirmed to FAIL on the pre-fix code. Results were
fingerprinted across all 24 representations and proven **byte-identical**.
Known and left alone: the member feed's `applyBoost` path still costs ~150 queries/candidate.

## 6b. गुणमिलन — the 36-guna engine (2026-07-26)

**Terminology is frozen: गुणमिलन, never कुंडली.**

The Ashtakoota engine already existed and was **wrong in three ways** plus far too slow to put in
the matching pipeline. All three are fixed (**DONE**); wiring it into matching is **IN PROGRESS**
in a separate live session (`GunamilanPairEvaluator`, `GunamilanMatchingIntegrationTest`, tier T4
and the `MatchingService`/`SuchakMatchFitService` hookup are still uncommitted).

1. **Duplicated yoni vocabulary → 4 of 36 points wrong on every autofilled profile.** The yoni
   master carried the same 14 animals twice — Sanskrit keys (which `master_nakshatra_attributes`
   actually autofills) *plus* English duplicates — while `YONI_ENEMY_PAIRS` listed only the English
   ones, so the enemy rule never fired and one animal under two spellings failed `===`. Sanskrit is
   now canonical: the migration repoints FKs, **deactivates (never deletes)** the duplicates,
   relabels as "Horse (Ashwa)", and an alias map still resolves retired spellings so production
   drift scores the same. Three seeders that recreated the split are fixed, including one inventing
   a third spelling set. Same class of bug fixed for vashya `keet` (silently 0.5) and the `other`
   rows (compared equal and invented a Nadi dosha).
2. **"No data" read as "incompatible."** `available` only proved a row EXISTED, so an all-NULL
   horoscope returned 0/36 and a naive `>= 18` called it *incompatible*. There is now
   `computable` / `state`, and `is_compatible` is **NULL when not computable**, with
   `missing_fields` saying what is absent. **This is the rule the whole layer rests on.**
3. **Threshold was off by one.** `<= 18.0` meant 19+. Now **18 inclusive** passes
   (`COMPATIBLE_THRESHOLD = 18.0`, owner decision).
4. Nadi and Bhakoot dosha were computed and discarded; both are exposed now — the owner wants the
   family to see everything a pandit would check.

**Mangal is judged separately, at low weight.** It is genuinely not part of the 36. It is a
separate verdict off the **manually entered** `mangal_dosh_type_id`, weight **0.05**
(`MangalCompatibility::WEIGHT`): both clear or both manglik → compatible, exactly one → not, and
**either side unknown is NOT-COMPUTABLE, never a rejection**.

**Performance**: masters are snapshotted once and a profile flattened to a koota key
(`GunamilanKootaKey`, `GunamilanMasterData`), so a pair compare is array math —
**24 queries / 14.2 ms per pair → 0 queries / 0.2 ms** (19,200 queries per feed request → 0).
Three tests assert a 0-query ceiling.

**Column added, not yet wired**: `profile_preference_criteria.gunamilan_required` (default false).

⚠ **Reach is small today: only ~13% of profiles carry nakshatra + rashi.** For the other ~87% the
verdict is `unknown` and must stay invisible to the score — which is exactly why the
missing-data-is-not-a-rejection rule matters more than the scoring itself.

## 6c. Geography data quality — a real, recorded data problem (2026-07-26)

**The village coordinates in `addresses` are not authoritative and never were.** LGD publishes no
coordinates, so village rows were geocoded **by name**, and villages sharing a name across India
were handed each other's points. Measured on Maharashtra: **44,853 villages hold only 10,220
distinct coordinates (77.2% carry some other village's point)**; 25.8% sit >25 km from their own
taluka's median and **9.1% sit >100 km away**. Every bad point is *inside* the state box, so a
bounding-box check catches exactly zero of them. Kolhapur/Gaganbawada has 29 of its 42 villages
geocoded near Akola, putting its median **635 km** from the real taluka.

**DONE — taluka/district centres on the existing `addresses.lat/lng`, behind a quality gate.**
The suggestions feed was recomputing taluka centroids at request time (an AVG over 671k addresses,
63× per request, 16.1 s of a 22 s request) for a value that never changes. The fact now lives on
the address row itself — **no parallel column, no side table, no cache key** (a real column
survives the `optimize:clear` every deploy runs). `GeoCentroidBackfillService::accept()` gates
every write with three checks, all of which must pass, and a failure writes **nothing**:
bounds (inside the state box), **consensus** (≥70% of the taluka's villages within 25 km of the
median), and **district distance** (≤100 km from its own district centre). Thresholds come from the
measured distribution, not invention. Districts are written first because the taluka check depends
on them; districts themselves are bounds-checked only (a median over tens of thousands of villages
cannot be moved by a minority).

- Result: **35/35 districts and 240/358 talukas written; 118 talukas stay NULL** — including
  Gaganbawada, correctly. A NULL taluka falls back to self-only: **narrower, never mis-pointed.**
- Cross-checked against OpenStreetMap on 20 talukas: accepted centres err by a **median 14.2 km
  (max 24.4 km)**; all 7 catastrophic cases (196–635 km) were rejected.
- Commands: `locations:backfill-geo-centroids` (writes), `locations:audit-geo-centroids`
  (read-only, exits 1 if a WRITTEN centre would now fail the gate).
- Behaviour that genuinely changed: `nearbyTalukasByCoordinate` now ranks by a taluka's true stored
  centre rather than the centroid of whatever villages fell inside the query box. It moved none of
  the 24 test results, but it is a real change, not a no-op.

**IN PROGRESS (separate live session) — village-coordinate repair from India Post.** A read-only
evaluation (`geo:analyze-pincode-source`, writes nothing) scored the India Post office directory
through the *same* acceptance gate: it carries a coordinate on the postal record itself rather than
a name lookup, coordinate reuse is **23.2% vs our 77.2%**, and the two datasets agree on district
for **97.92%** of Maharashtra villages by pincode (the 2.08% that disagree are excluded, not
guessed). On rows where the true post office is known, our current point is p50 24.8 km / p90
108.3 km off while the whole-pincode median is p50 5.8 km / p90 28.0 km off. Adopting it is
projected to raise accepted taluka centres from 240 to roughly 340 with about half the residual
error. `geo:repair-village-coordinates` is dry-run by default, journals every prior coordinate in
`address_geo_repairs` for exact rollback, stamps a `geo_source` per row
(`india_post_name_pincode` = a real point for that village; `india_post_pincode_area` = a coarse
shared pincode point, applied only where that pincode's own offices are tight), writes village rows
only, and **never makes a row worse** (a village already inside an accepted taluka's consensus
radius is kept if the CSV would throw it out). Taluka/district centres must be re-derived
afterwards. **None of this is committed yet — do not assume village coordinates are fixed.**

## 7. Flutter apps

**Suchak-apk** (go_router, 5-tab shell: home/customers/search/work/profile):
- Suchak self-registration: staged flow (mobile→otp→identity→[org steps]→location→email→password).
- Add Customer wizard (`manual_customer_wizard.dart`): **6 steps since 2026-07-22** —
  `identity, marital, religion_caste, location, education_career, photo`. Every step scrollable.
  identity merges contact+basic so the duplicate probe can run before the profile is created;
  education_career merges two short forms and uses a SECOND host controller (`bind()` is
  last-writer-wins). marital reveals dependent fields for divorced/annulled/separated/widowed and
  sends those rows via the full PUT (save-step 422s `marriages` by design). Photo delegates to
  `PhotoUploadScreen`. Finish drafts partner preferences then shows completion % + missing sections.
  **2026-07-26:** a duplicate hit now truly **stops** onboarding and hands off to consent
  (`consent_handoff.dart`) instead of letting the wizard continue.
  Reuses **local forked copies** of member step widgets; `_apiAllowedFields` hand-mirrors backend
  step fields (drift risk).
- Edit: `EditProfileHubScreen` (11 sections) → `EditRepresentedProfileScreen` (heavy, perf
  workarounds documented in-code); photo → `PhotoUploadScreen` (gallery+camera, no crop).
  Suchak's own profile photo is editable (with crop) from Settings.
- **Consent UI (2026-07-26):** consent-first buttons on customer rows, a
  **प्रतीक्षेत संमती** screen (`pending_consents_screen.dart`) so an unanswered request can be
  resent, a consent chip on the list, a given-badge + audit trail on detail, and manual
  (offline) signed-consent upload. The 9 backend states are still collapsed into one label.
- **Payments (2026-07-25):** plan management + plan editor
  (`plan_management_screen.dart`, `plan_editor_screen.dart`, `/plan-management`) reached from the
  **Profile tab**, entered via a gear icon. Presets are **hide/reorder only**; the editor is for
  custom plans. Per-plan duration, opt-in per-meeting and post-marriage fees grouped under their own
  heading and rendered as disclosed notes on the card and in the WhatsApp message. The carousel
  shows custom plans alongside presets. **Payment-received tracking feed lives in the Work >
  Payments tab** — compact rows with the customer's mobile, visible mark-paid / remind actions,
  search and filters. The paid badge reads **प्रीमियम / Premium** (was भरले / Paid).
- **Matches (2026-07-26):** **सुचवलेली स्थळं** screen (`suggested_matches_screen.dart`) —
  score, reasons and one-tap chosen/rejected decisions against the suggestions API.
- **Dark mode (2026-07-26):** real app-wide support via a single `AppPalette` theme extension
  (`lib/design_system/tokens/app_palette.dart`) + `AppStatusColors`. Light values are the exact
  Material shades used before, so light mode renders pixel-identically; dark values are hand-picked
  for near-black surfaces. Pinned by `test/design_system/dark_mode_palette_test.dart`.
- **Digits:** every user-facing numeral is Latin 0-9, in both languages, with a test that keeps it
  that way. Full ARB localisation (mr/en); the chosen language is sent to the server on every
  request.
- No completeness indicators on the list (the detail has them).

**flutter-apk (member)**: 15-step onboarding (`smart_onboarding_screen.dart`, server-driven order,
includes maritalStatus, motherTongue, lifestyle, astro, family, photo, partnerPreference,
setPassword); edit screen with the same 11 sections; **photo crop exists twice** —
`photo_gallery_screen.dart` `_PhotoCropSheet` (drag-handles on photo, mandatory, 3:4) and
`photo_step.dart` zoom/slider dialog (optional); `photo_upload_screen.dart` has none.
Server always re-covers to 720×960 WebP (`ImageOptimizationService::cover`), so clients crop
locally and upload cropped bytes — **no endpoint accepts crop coordinates**.
Fully ARB-localised (mr/en) with a guard against English leaks and Devanagari digits.
**Untouched on 2026-07-26** — but it consumes the shared `MatchingService`, so member matching
improved as a side effect of §6a. It has **no** dark-mode palette yet.

**Step-widget duplication** (Suchak copies of member files, all diverged):
career_step (181 changed lines), location_step (141), onboarding_step_helpers (80),
basic_candidate_info_step (43), education_step (22), religion_caste_step (16), scaffold (diff).
No compile-time link — every backend contract change must be mirrored twice by hand.

## 8. "One engine" debt (approved direction: consolidate, never duplicate)

1. ~~Marital dependent rules ×4~~ — **done 2026-07-22**: `MaritalDependencyRules` is canonical.
   Remaining copy: the Blade/Alpine `x-show` expressions (a view — acceptable until the wizard
   is re-templated).
2. Photo crop ×3 states (drag-handle / slider / none) — future **shared Flutter package goal**
   (decided 2026-07-22: separate goal, not now; drag-handle version is the keeper).
3. Step widgets forked ×7 files between apps — candidate for the same shared package.
4. `_apiAllowedFields` client mirror of backend step fields — replace with server-driven field list.
5. ~~Dead contact fields on marriage/address/alliance~~ — **done 2026-07-22** both sides.
6. ~~Consent fallback logic~~ — **shared 2026-07-22**: role order/labels in
   `App\Support\ConsentContactRole`, consumed by `BulkIntakeCandidateContactPlanService`.
7. Completion logic — single engine reused: `ProfileCompletionService` feeds member web, the
   Suchak profile GET and the Suchak customer list. Do not write a second completeness calculator.
8. Partner-preference drafting — single engine reused: `RegistrationPartnerPreferenceService` is
   called by both the member route and the Suchak adapter
   (`POST /suchak/nxt/{representation}/preferences/auto-draft`).
9. ~~Suchak match heuristics ×3~~ — **done 2026-07-26**: cross-search / daily-opportunity /
   collaboration each had their own boolean "same caste, same district, age gap ≤ 8" scorer.
   Deleted; `SuchakMatchFitService` delegates to `MatchingService`. See §6a.
10. ~~Boost rules read from two places~~ — **done 2026-07-26**: `matching_boost_rules` is the single
    runtime source; the `match_boost_settings` singleton is only a fallback + the AI switch.
11. ~~Name masking ×2~~ — **done 2026-07-26**: `App\Support\CandidateNameMask`.
12. ~~Yoni vocabulary ×2 (Sanskrit + English)~~ — **done 2026-07-26**: Sanskrit is canonical,
    duplicates deactivated (not deleted), alias map covers production drift. Three seeders fixed.
13. ~~Ad-hoc `information_schema` memos ×4~~ — **done 2026-07-26**: `App\Support\SchemaPresence`.
14. **Open:** two near-duplicate preference tolerance helpers were collapsed into
    `evaluatePreferenceBuild()`, but `MobileMoreMatchesSectionService` and the main feed still
    reach it by different routes — watch for drift.

## 9. Product decisions log

| Date | Decision |
|---|---|
| 2026-07-21 | Sub-record contact numbers editable; privacy via authorization scoping (own view + representing Suchak see them; browse-others strips them). Member also sees own. |
| 2026-07-21 | Progressive disclosure in onboarding is intentional — keep. |
| 2026-07-21 | Server = pre-production; all data is test data until explicit go-live. |
| 2026-07-22 | OCR intake is a paid-plan feature (gate already exists in backend). |
| 2026-07-22 | Onboarding restructure: 8→6 steps — 1) ओळख (mobile, profile-for, gender, name, DOB, height) 2) marital + dependents 3) धर्म/जात (mother tongue REMOVED) 4) ठिकाण 5) शिक्षण+व्यवसाय+उत्पन्न 6) फोटो. Every step scrollable. |
| 2026-07-22 | `candidate_mobile` becomes REQUIRED (every profile needs ≥1 number — consent depends on it). |
| 2026-07-22 | Minimum age enforced server-side: female 18 / male 21. DOB always required (approximate allowed). |
| 2026-07-22 | Duplicate detection after step 1: mobile+name+DOB+gender combined scoring (mobile alone NOT decisive — shared family numbers). Fuzzy name (token-set + transliteration normalize). On match → suggest consent-on-existing-profile, skip remaining steps. Suchak decides; never hard-block. |
| 2026-07-22 | Consent may only target numbers already in the profile. Fallback = system SUGGESTS other stored numbers after no response — reuse bulk-intake contact-plan logic. Bulk intake keeps its own upfront consent flow. |
| 2026-07-22 | Partner preference filled at END of registration using the existing auto-draft engine; then show per-section "still missing" completeness. |
| 2026-07-22 | Photo: separate step; crop = drag-handles ON the photo; shared-package extraction deferred to its own goal. |
| 2026-07-22 | Remove "Edit on website" from Suchak app — native editor only. |
| 2026-07-22 | Marital step in Suchak onboarding uses the full PUT (`update()`), not saveStep. |
| 2026-07-22 | Duplicate probe runs BEFORE profile creation, once per wizard session; reports evidence and never blocks — Suchak decides. |
| 2026-07-22 | Photo step delegates to `PhotoUploadScreen`; the wizard's gallery-only picker was deleted rather than maintained alongside. |
| 2026-07-22 | Partner preferences are DRAFTED (not asked) at the end of registration via a Suchak-scoped adapter; both the % and the missing sections are advisory — a failure never blocks finishing. |
| 2026-07-24 | Terms are chosen **per payment request** ("customer accepted terms"), settled via `acceptTerms` — not a global policy flip (that flip was made and reverted the same day) and not an admin bypass. |
| 2026-07-24 | Two ready-made Track A plans ship in code: Basic ₹2000 / Premium ₹5000, auto-published. |
| 2026-07-24 | The dormant package-template engine is removed rather than kept as a second way to define a plan. |
| 2026-07-25 | A Suchak's reusable customer plans live in a NEW table `suchak_customer_plans` — not `suchak_plans` (the platform catalog they buy) and not `SuchakServicePackage` (the send-time artefact). The send-time model is unchanged; no FK back. |
| 2026-07-25 | **Disclosed fees are never billed.** Per-meeting and post-marriage fees are opt-in notes shown on the plan card and in the message; they are never added to `amount_due`. |
| 2026-07-25 | Presets are **hide/reorder only in the app**. The backend keeps a price-override capability, but a Suchak wanting a different price makes a custom plan. (Reversed the half-done preset price-edit UI.) |
| 2026-07-25 | Payment records are created **only on send**, never on preview. |
| 2026-07-25 | A payment link must unfurl as the Suchak's UPI QR — never the site's homepage image; if there is no QR, no image at all. |
| 2026-07-25 | Track A mark-paid works **without** a proof reference (the Suchak confirming their own cash), but the request stays flagged `proof_status = required` in the risk queue. Audit is deferred, not waived. |
| 2026-07-25 | reverse-paid requires a non-empty reason and is audited — a deliberate correction, not a one-tap undo. |
| 2026-07-25 | **Every user-facing digit is Latin 0-9, never Devanagari — in both apps, both languages, and in every generated message.** (FROZEN) |
| 2026-07-26 | **Consent establishes management.** Linking is consent-FIRST: asking to represent an existing person creates only a pending claim (invisible, 403 on reads and writes, no customer context); the representation becomes real only on acceptance. |
| 2026-07-26 | **A rival Suchak blocks a consent request ONLY when they hold a CONSENTED representation.** Merely having the person in their list — even a profile they typed in themselves — is not managing them and must not stop anyone else from asking. (A widening of this was shipped and then deliberately reverted the same day.) |
| 2026-07-26 | Represented-profile WRITES are consent-gated for `matched_existing_profile`; a Suchak's own manually-created profiles are not. A revoked/expired consent re-closes the gate. |
| 2026-07-26 | A pending claim must never be a dead end — `GET /suchak/consent-requests` exists so an unanswered request can be found and resent. |
| 2026-07-26 | One matching engine. A Suchak sees the same real score/breakdown/explanation a member gets; Suchak-specific heuristics are forbidden. The wider candidate pool is opt-in so member behaviour is provably unchanged. |
| 2026-07-26 | **Ranking must reward trust, not payment.** Verification / photo / completeness / recency outrank a paid plan (paid: +12 → max +4), and paid is trimmed first when the aggregate cap binds. |
| 2026-07-26 | A seeker is community-locked **only on an explicit refusal of intercaste marriage** (or explicit strictness metadata). The registration-auto-seeded own-caste pivot is a default, never a demand, and must never lock. |
| 2026-07-26 | Derived preferences are allowed, but only to fill an empty field; they are soft, marked *assumed*, and can never exclude. The Marathi wording denies the premise first so a guess is never read as the family's condition. |
| 2026-07-26 | The engine may relax, but must **say what it relaxed** — T0..T3 (income/height → location → caste), stopping at a configurable floor. Gender, legal age, lifecycle, rejected pairs and religion are never relaxed. |
| 2026-07-26 | Everything shown is logged append-only (`suchak_match_suggestions`): what was suggested and what the Suchak chose or rejected and why. Rejection requires a reason code. This is the learning foundation. |
| 2026-07-26 | **Missing गुणमिलन data must NEVER read as a rejection.** `is_compatible` is NULL when not computable; 18/36 is compatible **inclusive**; Mangal is judged separately at weight 0.05 from the manually entered field, and either side unknown is not-computable, never a no. |
| 2026-07-26 | Terminology: **गुणमिलन**, never कुंडली. |
| 2026-07-26 | A wrong location is worse than no location: a taluka centre is stored only if it passes bounds + 70% consensus + district-distance; a failure stays NULL and degrades to a narrower fallback. |
| 2026-08-01 | **The village rule splits by audience.** MEMBER APP: unchanged — a village is never shown to a member, anywhere; a member is choosing for themselves, not sourcing matches. SUCHAK APP (all of it, not only the marketplace): exactly four things are hidden from another Suchak by default — **name, village, detailed address, mobile** — and everything else is shown **including the photograph**, because a matchmaker who cannot see a face cannot propose a match. The originating Suchak may reveal any of the four, per candidate; he knows the family, the platform does not. Rationale: the consent the candidate already signed asks permission to forward the profile "to suitable and appropriate matches", which is exactly what a cross-Suchak view is. Cross-Suchak photographs carry a watermark naming the viewing Suchak — traceability instead of concealment. See `laravel-matrimony/docs/MATCHMAKER-MARKETPLACE-BLUEPRINT.md` D19 / D19a / D19b / D19c. |
| 2026-08-01 | **Cross-Suchak display name is typed, not derived.** `matrimony_profiles.full_name` is a single column with no first/last split, and adding one would be wrong for many Indian names. A Suchak who wants another Suchak to see only a surname (or a surname plus a village) types the display string himself; the default when he types nothing is `CandidateNameMask`. Blueprint D19d. |
| 2026-08-01 | **Screen text carries what the user needs to act, nothing else.** Rules, guarantees and rationale live in the blueprint, not on the screen. Test before any string ships: does the reader do something differently because of it? Blueprint D27. |
| 2026-08-01 | **No cap on chargeable meetings.** How many candidates a family meets is their own decision, and every meeting already requires their individual approval — so a declared ceiling protects against nothing and reads as a quota the Suchak intends to fill. The approval screen shows only that meeting's price — a cumulative spend figure shown while a family is deciding about a person becomes a regret ledger. The cumulative amount is still recorded and belongs on the payments screen. Blueprint D17. |
| 2026-08-01 | **Re-visit fee = visit fee.** Meeting the same candidate again is charged at the same rate, but only when the Suchak arranges it; a family that contacts the other family directly owes nothing for that meeting. The fee is for the arranging, not for the meeting. Protection on the success fee comes from the 12-month viewed-profile clause, not from policing direct contact. Blueprint D24. |
| 2026-08-01 | **Marriage settled, engagement and wedding are three separate ladder stages, in that order.** लग्न ठरले precedes साखरपुडा precedes the wedding. Success-fee installments hang off the first two, and installment triggers may only be chosen from ladder stages, never free text. Blueprint §6a. |
| 2026-08-01 | **The success fee is earned in tranches at past events — nothing is ever refunded.** Each tranche is released by a stage that already happened (e.g. 10% settled / 40% engagement / remainder at the wedding), so no money is held against a future that may not arrive. Shares are percentages **of the total** (never of the remainder), the last tranche is "the remainder" not a percentage, and the shares must sum to 100% — all validated at agreement creation. A later stage releases every earlier unpaid tranche with it. Blueprint D25 / §7.4. |
| 2026-08-01 | **The success fee is paid once per customer in total.** If a settlement breaks and a different match succeeds later, tranches already paid count toward the total and only unpaid tranches fire — a family whose match broke twice never pays more than the one agreed figure. Attribution of the declared cross-Suchak share is therefore recorded **per tranche**, not per customer. Blueprint M9. |
| 2026-08-01 | **Marriage settled, engagement and marriage are each claimed then confirmed**, on the meeting pattern (claim → customer confirms → 7 silent days → dispute), except that **either Suchak may raise the claim**. Blueprint D26. |

## 9a. Device verification log

Verified on a real device (Oppo CPH2573, `adb` at
`C:\Users\shank\AppData\Local\Android\Sdk\platform-tools\adb.exe` — not on PATH) after the
2026-07-22 release build:

- Wizard header reads **"1/6 · Identity"** — the 8→6 restructure is live.
- Identity screen holds mobile, profile-for, gender, full name, DOB and height on ONE scrollable
  screen; progressive disclosure still reveals fields as the previous one is filled.
- Customer detail no longer offers "Edit on website".
- Siblings section shows a SINGLE Cancel + Save section row.
- Abandoning the wizard part-way created no new profile — the duplicate probe runs before creation.

Second device pass, 2026-07-22 (same handset), which found and closed three defects:

- Create User reached **"2/6 · Marital status"** and the new profile appeared in the customer list
  — step-1 persistence confirmed.
- **Defect (fixed):** Edit → Basic Information's mother-tongue and marital-status dropdowns were
  permanently un-openable — `_ensureOptionsForSection(basic)` loaded only genders + religions.
- Marital flow (after the fix): selecting **Divorced** opens Marriage year / Divorce year / Legal
  status plus the Children toggle — the originally-reported defect, closed.
- **Defect (fixed):** the Siblings section header overflowed by 15px (RenderFlex).
- **Retracted:** the `SuchakTickerText` "clipping" was a misreading of correct marquee behaviour;
  a real separate bug (repeated delayed `repeat()` during the start pause) was fixed with a guard.

Third pass, 2026-07-22 (sibling contact-number walkthrough):

- Saving a sibling contact number was rejected by the server ("The siblings.0.contact_number field
  is prohibited") — a **deployment gap, not a code bug**: the app targets production, which was
  still running the pre-fix rule. Branch-side proof:
  `SuchakRepresentedProfileContactNumbersTest` 4/4. No test data reached production.

Production-found defects fixed 2026-07-26 (found by running against the live server, not a
device walkthrough):

- Duplicate-check returned a **500 on every call** — the mobile-hit scan read `relation_type` on
  `profile_contacts`, which has `contact_relation_id` (SQLSTATE 42S22). Now read behind a
  `hasColumn` guard. See the §5 trap.
- A rival Suchak's own manually-created customer was classified `unrepresented`, so a **competing
  consent request was accepted**. The classification and the refusal were tightened, then the
  boundary was settled by the PO ruling in §4A (consent, and only consent, blocks).
- The suggestions endpoint timed out at the Cloudflare edge (HTTP 524) — see §6a performance.

## 10. Open gaps

**Closed 2026-07-22** (Laravel Phase A): consent-fallback suggestions, minimum-age validation,
`candidate_mobile` required, Suchak completeness signal, dead contact fields, mobile-API
year-sanity, duplicate-check endpoint.
**Closed 2026-07-26**: consent-first linking, consent-gated edits, pending-consent visibility,
one matching engine, ranking rebalance, gunamilan correctness, taluka/district centres.

**In flight right now (uncommitted, live sessions — do not assume the shape is final):**

- **Gunamilan → matching wiring.** `GunamilanPairEvaluator`, tier T4 (`gunamilan`), the
  `MatchingService` / `SuchakMatchFitService` / `ProfilePreferenceMatchService` hookup, the
  matching lang additions and `GunamilanMatchingIntegrationTest` are all modified-but-uncommitted.
  Treat gunamilan-in-the-feed as **IN PROGRESS**, not shipped.
- **Village-coordinate repair from India Post** (§6c) — commands, `PostalDirectory`, and the
  `geo_source` / `address_geo_repairs` migration are untracked. Village coordinates are still the
  bad name-geocoded ones.
- `docs/FIELD-OWNERSHIP-MAP.md` is being edited concurrently.

**Still open:**

- **118 of 358 Maharashtra talukas have no trusted centre** and fall back to self-only. Anything
  reasoning about "nearby" must tolerate a NULL centre.
- **Gunamilan applies to very few pairs today — only ~13% of profiles carry nakshatra + rashi.**
  Any product framing that leans on it should assume `unknown` is the common case.
- The member feed's `applyBoost` path still costs ~150 queries/candidate (outside the endpoint that
  was timing out, so deliberately left alone).
- `remarriage_reason` / `notes` are accepted by the mobile API then hard-nulled, while the web
  wizard persists them. Deliberate divergence or bug is still undecided.
- `gender_mode` unenforced server-side (§6).
- Orphan Draft profiles when the wizard is abandoned mid-flow (no cleanup/resume path).
- Consent status is still surfaced as a single `consent_label`; the 9 backend states and the
  `consent-contacts` fallback suggestions are not shown individually in the Suchak UI.
- **NameMatcher Devanagari over-match (P2):** `NameMatcher::normalize()` strips Devanagari vowel
  signs (they are Unicode Marks, not Letters), so two different Marathi-script names sharing a
  consonant skeleton collapse to one and report `exact`. Duplicate detection is advisory and never
  blocks, so this misleads the hint rather than corrupting data. Fix direction in
  `laravel-matrimony/docs/CORE-COMPONENT-REVIEW-2026-07-22.md`.
- **The member app has no dark mode.** `AppPalette` exists only in `Suchak-apk`; a member on a
  dark-themed phone still gets the light UI. Nobody has decided whether to extract it into the
  shared-package goal (§8.2/8.3) or fork it a second time — and forking it would be exactly the
  duplication the no-duplicate rule forbids.
- **`suchak_match_suggestions` is written but never read back for learning.** The log exists and
  decisions are recorded; no engine consumes them yet, so per-Suchak learning is a foundation, not
  a feature.
- **No member-side surface for the relaxation summary.** The engine reports the tier reached and the
  fields relaxed, and the Suchak app shows it; the member app was not touched, so a member still
  cannot see why their feed widened.
- 3 pre-existing test failures on main (`MobileMatrimonyProfileApiTest`: has_children ×2,
  mother_tongue_id fixture) — unrelated; any branch must match this count exactly.

> **Canonical copy.** This file used to live at the workspace root, outside every git repository —
> which meant the product decision log, the one record of *why* things are the way they are, was not
> versioned, not pushed, and one disk failure from gone. It lives here now. The root copy is a stale
> duplicate; delete it.
