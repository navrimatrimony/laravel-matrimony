# Matchmaker Marketplace — Planning SSOT

**Final planning authority.** Sits above the Journey WBS, which stays as the build order.
`MATCHMAKER-MARKETPLACE-BLUEPRINT.md` is the design record only and is stale in several places.

**Audit date:** 2026-08-05, against running schema, registered routes, notification classes, push
registry, scheduler, live queue workers, config and the test suite. Nothing is taken from
documentation.

**Classification used throughout**

| Tag | Meaning |
|---|---|
| **COMPLETE** | Built and reachable. Not work. |
| **READY** | Under one hour, no decision needed. |
| **DECISION** | Blocked on a product answer. |
| **AUDIT** | Cannot be honestly sized without its own investigation. |

---

## Three findings that cut across every matrix

1. **The event trail is complete; the notification layer does not exist.**
   `SuchakActivityLog` carries 118 action types, 24 of them marketplace. Every marketplace event
   is already recorded. But of 26 notification classes, **zero** are marketplace, and **zero**
   marketplace push types are registered. The system knows everything and tells nobody.

2. **No marketplace queue, no marketplace worker.** Production runs three workers: `default` ×2
   and `bulk-intake`. Nothing dispatches marketplace work to a queue, and no marketplace-specific
   queue exists. Every marketplace action is synchronous inside the request.

3. **Throttling is two lines wide.** `routes/api/suchak.php` has exactly **two** `throttle:`
   declarations across ~35 marketplace routes. Publish, propose, settle, raise-obligation and
   record-marriage are all unthrottled.

---

# 1. Notification Matrix

Event names are the real `SuchakActivityLog::ACTION_*` values, not invented ones.

Legend: **L** = logged · **S/C/A** = Suchak / Customer / Admin notified

| # | Event | Trigger | Initiator | L | S | C | A | Push | In-app | WhatsApp | Email | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `marketplace_challenge_published` | Suchak publishes | Suchak | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 2 | `marketplace_proposal_received` | Another Suchak proposes | Suchak | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 3 | `collaboration_request_accepted` | Publisher accepts | Suchak | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 4 | `collaboration_request_rejected` | Publisher rejects | Suchak | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 5 | `collaboration_request_expired` | Clock | System | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 6 | `marketplace_challenge_withdrawn` | Publisher withdraws | Suchak | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 7 | `marketplace_challenge_expired` | `expires_at` passes | System | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **AUDIT** — see §7 |
| 8 | `visit_scheduled` | Meeting booked | Suchak | ✅ | ✗ | ✗ | ✗ | ✗ | reminder only | ✗ | ✗ | **READY** |
| 9 | `visit_completion_marked` | Suchak marks done | Suchak | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY — highest value.** This is the moment the customer must act |
| 10 | `visit_user_confirmed` | Customer confirms | Customer | ✅ | ✗ | — | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 11 | `visit_admin_confirmed` | Admin confirms | Admin | ✅ | ✗ | ✗ | — | ✗ | ✗ | ✗ | ✗ | **READY** |
| 12 | `visit_cancelled` | Either side cancels | Suchak/Customer | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 13 | `visit_disputed` | Either side disputes | Suchak/Customer | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 14 | `visit_dispute_settled` | Admin resolves | Admin | ✅ | ✗ | ✗ | — | ✗ | ✗ | ✗ | ✗ | **READY** |
| 15 | `visit_payout_qualified` | Confirmation clears | System | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 16 | `dispute_opened` | Dispute raised | Any | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 17 | `dispute_status_changed` | Admin moves it | Admin | ✅ | ✗ | ✗ | — | ✗ | ✗ | ✗ | ✗ | **READY** |
| 18 | `cross_suchak_obligation_raised` | Share becomes debt | Suchak | ✅ | ✗ | — | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 19 | `cross_suchak_obligation_settled` | Debt paid | Suchak | ✅ | ✗ | — | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 20 | `marriage_outcome_recorded` | Marriage recorded | Suchak | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 21 | `marriage_outcome_voided` | Recorded in error | Suchak/Admin | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 22 | `collaboration_stage_customer_confirmed` | Customer confirms a rung | Customer | ✅ | ✗ | — | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 23 | `collaboration_stage_customer_recorded` | Stage recorded | Suchak | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **READY** |
| 24 | Payment received / failed | PayU webhook | System | ✅ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | **AUDIT** — payment notifications live outside the Suchak log |

**Every row's logging is COMPLETE. Every row's notification is MISSING.**

**Delivery is one switchboard.** `SendPushForDatabaseNotification` already turns any database
notification into a push, honouring the admin switch, the per-type user preference and quiet
hours. Each row above is therefore **one notification class plus one `PushTypeRegistry` row** —
not a delivery mechanism. Roughly 45 minutes each; several share a class with different payloads.

**Retry: COMPLETE.** `SafeNotifier` swallows and logs a notification failure so it can never break
the business action that triggered it.

**Not built anywhere: WhatsApp and email for marketplace events.** WhatsApp is blocked on Meta
credentials (§8). Email is **DECISION** — nobody has said marketplace events warrant email.

---

# 2. Permission Matrix

C = Create · R = Read · U = Update · X = Cancel · F = Confirm · D = Dispute · A = Approve · J = Reject

| Object | Customer | Suchak | Admin | System |
|---|---|---|---|---|
| Challenge | — | C R U X (own) | R | expire |
| Proposal | — | C (other's challenge) · R A J (own challenge) | R | expire |
| Collaboration/engagement | R (own, via portal) | R U (party only) | R A J | expire |
| Meeting | R F D | C R U X F(suchak side) D | R F A | qualify payout |
| Dispute | D | D | R U A J | — |
| Obligation | — | C R (party) · settle | R | — |
| Success-fee tranche | — | R release settle | R | — |
| Marriage outcome | — | C R void | R void | — |
| Agreement | R F (portal) | C R U | R | freeze |

**Enforcement — COMPLETE.** Every marketplace API route sits behind `auth:sanctum` +
`suchak.account`; customer routes are on the member API because the actor is the member;
`assertCustomerSideUserCanConfirm()` scopes confirmation to the right person; ownership is checked
per route (`ownedRepresentation()` and equivalents); cross-Suchak reads are masked by
`SuchakCandidateMaskingService`; `scopeWithCandidateGivenConsent()` stops a Suchak declaring
consent on a candidate's behalf.

**Gap — DECISION:** the customer cannot **cancel** a meeting anywhere. Only the Suchak can. That
may be deliberate; it has never been decided.

---

# 3. State Transition Matrix

| Object | States | Transitions guarded? |
|---|---|---|
| Challenge | `open` → `withdrawn` / `fulfilled` / `expired` | **COMPLETE** in service |
| Collaboration request | `pending` → `accepted` / `rejected` / `expired` / `cancelled` / `admin_review` | **COMPLETE** |
| Visit confirmation | `scheduled` → `completed` → `confirmed` → `payout_qualified`; `disputed`; `cancelled` | **COMPLETE** |
| Dispute | `open` → `under_review` → `resolved` / `rejected` / `closed` | **COMPLETE** |
| Pipeline | `pending` → `expired` / `closed` / `converted` / `cancelled` | **PARTIAL** — `converted` is declared and, per the blueprint, never written. **AUDIT** |
| Obligation | no `STATUS_*` constants — settlement is timestamp-driven | **AUDIT** — a state machine expressed as nullable timestamps is not wrong, but it is unverified |
| Success-fee tranche | no `STATUS_*` — `released_at` / `settled_at` | **AUDIT**, same reason |
| Marriage outcome | no `STATUS_*` — `voided_at` + `void_seq` | **COMPLETE** — void is append-only by design |

**Missing validation — AUDIT, not READY.** Three objects express state as nullable timestamps
rather than an enum. That is a legitimate pattern, but whether every illegal ordering is refused
(settle before raise, release after settle) needs its own read. Do not assume it is broken; do not
assume it is safe.

---

# 4. Payment Matrix

| Flow | Who → whom | Platform's role | Status |
|---|---|---|---|
| Member plan | Member → Platform | principal, PayU | **COMPLETE** |
| Suchak subscription | Suchak → Platform | principal, PayU | **COMPLETE** |
| Registration fee | Family → Suchak | **not a party** | **COMPLETE** — frozen on the agreement |
| Per-meeting fee (offline/online) | Family → Suchak | **not a party** | **COMPLETE** — both columns exist and are in the frozen hash |
| Success fee | Family → Suchak | **not a party** | **COMPLETE** — tranches |
| Cross-Suchak share | Suchak → Suchak | records the debt, does not move the money | **COMPLETE** — obligations |
| Payout hold | Platform withholds | `SuchakPayoutHold`, scope `VISIT_CONFIRMATION_DISPUTE` | **COMPLETE** |
| Refund | Platform → payer | admin-initiated | **COMPLETE** for platform payments |
| Refund of a Suchak's own fee | Family ↔ Suchak | **none** | **DECISION** — deliberate per Terms clause 7, but not stated in-product |
| Failure / retry | PayU | `payments:repair-missing` (5 min), `payments:reconcile` (daily) | **COMPLETE** |
| Outstanding | — | `cross-suchak-obligations` owed-vs-paid | **COMPLETE** |

**Live blocker — READY-adjacent:** production holds `PAYU_ENV=test`. Every money path is exercised
against the sandbox. Not code — an owner switch, and it must not flip before the rest is verified.

---

# 5. Audit Matrix

| Surface | Where | Retention | Status |
|---|---|---|---|
| Marketplace actions (24 events) | `suchak_activity_logs` (`metadata_json`, `occurred_at`, indexed) | none defined | **COMPLETE** logging · **DECISION** on retention |
| Meeting state changes | `suchak_visit_confirmation_events` — update/delete/re-save all **throw** | none | **COMPLETE** |
| Admin actions | `admin_audit_logs` | none | **COMPLETE** |
| Consent | `suchak_consent_events` — immutable | none | **COMPLETE** |
| Pipelines | `suchak_pipeline_events` | none | **COMPLETE** |
| Payments | `suchak_payment_request_events` | none | **COMPLETE** |
| Workflow | `suchak_workflow_timeline_events` | none | **COMPLETE** |

**Nothing is missing from the audit trail.** The gap is the opposite: **no retention policy on any
of it.** `SuchakRetentionArchiveRule` / `_runs` tables exist and `SuchakExportRetentionService`
handles exports, but no rule governs the event logs. **DECISION** — how long marketplace events
are kept, given the blueprint's own twelve-month dispute window.

---

# 6. Error & Recovery Matrix

| Failure | Retry | User-visible | Status |
|---|---|---|---|
| Notification send | swallowed + logged, no retry | none | **COMPLETE** — `SafeNotifier` |
| Queued job | `--tries=2`/`3` on the workers, then `failed_jobs` | none | **COMPLETE** mechanism · **DECISION** — nobody is alerted when a job lands in `failed_jobs` |
| PayU webhook missed | `payments:repair-missing` every 5 min | payment stays pending | **COMPLETE** |
| PayU reconciliation | `payments:reconcile` daily | none | **COMPLETE** |
| API 4xx/5xx to the Suchak app | `userFacingError()` maps to one sentence | yes | **COMPLETE** |
| API failure to the member app | per-screen catch | yes | **COMPLETE** |
| Challenge expiry | see §7 | challenge silently stops appearing | **AUDIT** |
| Meeting no-show | not modelled | none | **AUDIT** — B2.1a records attendance, but no-show is not a state |
| Consent expiry | `suchak:scheduled-jobs` → `expireConsents()` | representation stops being routable | **COMPLETE** |
| QR token expiry | `expireQrTokens()` | link stops working | **COMPLETE** |

---

# 7. Background Processing Matrix

| Job | Cadence | Guarded | Status |
|---|---|---|---|
| `suchak:scheduled-jobs` | daily 04:00, `withoutOverlapping(120)`, `onOneServer` | ✅ | **COMPLETE** |
| ↳ `expireOverduePayments` | inside it | — | **COMPLETE** |
| ↳ `expireConsents` | inside it | — | **COMPLETE** |
| ↳ `expireQrTokens` | inside it | — | **COMPLETE** |
| ↳ **expire challenges** | **not present** | — | **AUDIT → likely READY** |
| ↳ **expire collaboration requests** | **not present** | — | **AUDIT** |
| `payments:repair-missing` | 5 min | — | **COMPLETE** |
| `payments:reconcile` | daily 01:00 | — | **COMPLETE** |
| `governance:queue-health` | 30 min | ✅ | **COMPLETE** |
| `account:purge-due-deletions` | daily 03:40 | ✅ | **COMPLETE** |

**The finding.** `SuchakScheduledJobsConsolidationService` expires payments, consents and QR
tokens. It does **not** expire challenges or collaboration requests, yet both objects declare an
`expired` state and challenges carry `expires_at`. Either something else expires them or nothing
does — and if nothing does, a withdrawn-by-time challenge stays `open` forever and
`marketplace_challenge_expired` is never logged. **AUDIT first**; the fix is probably one method
in an existing service.

**Queues.** Production runs `default` ×2 and `bulk-intake`. **No marketplace queue exists and
nothing marketplace-related is dispatched to one** — every action is synchronous. That is fine at
current volume and is stated so it is a decision rather than an oversight.

---

# 8. External Dependency Matrix

| Dependency | Used for | Configured on prod | Marketplace impact |
|---|---|---|---|
| **PayU** | plan + subscription payments | **`PAYU_ENV=test`** | Money paths run against sandbox |
| **Meta WhatsApp** | OTP, consent links, intake | **no credentials at all** | **The single blocker.** No OTP, no consent link, no marketplace notification by WhatsApp |
| **Firebase** | push (FCM), Google Sign-In, Suchak phone auth | ✅ working | Push delivery is ready and unused by the marketplace |
| **Email (SMTP)** | member mail | ✅ | Not used for marketplace |
| **SMS** | — | **no provider** | No fallback when WhatsApp is down |
| **Maps** | — | not used | — |
| **OCR** — OpenAI, Sarvam, Tesseract, NudeNet, Vision | intake pipeline | OpenAI/Sarvam ✅, Vision configured but unused | Marketplace does not depend on it |

**Marketplace hard-depends on exactly one thing that is missing: Meta.** Everything else it needs
is live.

---

# 9. Security Matrix

| Control | Status |
|---|---|
| Authorization | **COMPLETE** — `auth:sanctum` + `suchak.account`, per-route ownership, member routes for member actors |
| Validation | **COMPLETE** — request validation on every mutation |
| Masking | **COMPLETE** — one path, `SuchakCandidateMaskingService`; `is_broad` now honest |
| Consent integrity | **COMPLETE** — `consent_is_suchak_declared` cannot block a rival |
| Immutability | **COMPLETE** — visit and consent event tables throw on update/delete |
| **Rate limiting** | **READY** — only **2** `throttle:` declarations across ~35 marketplace routes. Publish, propose, settle, raise-obligation, record-marriage all unthrottled |
| **Member OTP send throttle** | **READY** — none at all on `POST /auth/mobile-otp/send` |
| **Suchak OTP fail-closed** | **READY** — `SuchakRegistrationService::issueOtp()` defaults to `dev_show` with no environment guard; production holds `dev_show`. Mitigated by `EnsureSuchakLegacyOtpEnabled`, off in production |
| **Replay protection** | **AUDIT** — no idempotency key was found on any money mutation. A repeated `settle` or `release` may double-apply |
| Abuse — challenge spam | **READY** — an unthrottled `POST /marketplace/challenges` |
| Abuse — proposal spam | **READY** — same on `/proposals` |

**Replay is the one that would hurt.** Everything else on this list is a rate limit; a
double-applied settlement is money.

---

# 10. Analytics / Event Matrix

| Event | Tracked | Feeds reputation | Feeds ranking | Feeds payouts |
|---|---|---|---|---|
| Challenge published / withdrawn / expired | ✅ | ✗ | ✗ | ✗ |
| Proposal received / accepted / rejected | ✅ | ✅ | ✗ | ✗ |
| Meeting scheduled / completed | ✅ | ✅ | ✗ | ✗ |
| Meeting confirmed | ✅ | ✅ | ✗ | ✅ |
| Meeting cancelled | ✅ | **partial** — no actor or reason recorded | ✗ | ✗ |
| Meeting disputed / settled | ✅ | ✅ | ✗ | ✅ |
| Payout qualified | ✅ | ✅ | ✗ | ✅ |
| Obligation raised / settled | ✅ | ✅ (realized-vs-declared ratio) | ✗ | ✅ |
| Marriage recorded / voided | ✅ | ✅ | ✗ | ✅ |
| **Suggestion viewed** | **✗** | would | ✗ | ✗ |

**Ranking is empty everywhere.** No marketplace event feeds any ordering — browse is not sorted by
reputation, and nothing is. **DECISION**: is ranking wanted? The blueprint's §5 assumes the market
"sorts itself", which today it does not.

**Reputation is fed by nine of ten events.** The missing one is Viewed (**DECISION** — who counts
as having viewed) and the partial one is cancellation (**READY** — B2.1a).

---

# Consolidated backlog

## READY — 21 items, no decisions

| Band | Items |
|---|---|
| Notifications (§1) | 22 events × ~45 min each; several share a class. **Start with `visit_completion_marked`** — it is the one that unblocks the customer |
| Security (§9) | throttle member OTP send · Suchak OTP fail-closed · throttle publish · throttle propose |
| Analytics (§10) | cancellation actor/reason into `metadata_json` |

## NEEDS PRODUCT DECISION — 8

1. May a customer cancel a meeting? (§2)
2. Email for marketplace events? (§1)
3. Retention on marketplace event logs? (§5)
4. Alert when a job lands in `failed_jobs`? (§6)
5. Is ranking wanted at all? (§10)
6. Who counts as having viewed a suggestion? (§10)
7. Chargeable ceiling on a challenge — wanted, and in what unit?
8. Is the tokenised web link enough for customer stage confirmation?

## NEEDS RUNTIME AUDIT — 6

1. **Does anything expire challenges?** (§7) — highest value; likely one method
2. Does anything expire collaboration requests? (§7)
3. **Replay protection on money mutations** (§9) — highest risk
4. Timestamp-driven state machines: obligation, tranche (§3)
5. `SuchakPipeline::STATUS_CONVERTED` — ever written? (§3)
6. Payment notifications outside the Suchak log (§1 row 24)

## ALREADY COMPLETE — do not re-plan

Audit trail (all seven event tables) · authorization · validation · masking · consent integrity ·
immutability · state guards on the four enum-driven objects · payment reconciliation and repair ·
payout holds · frozen agreement including all four money fields · consent/QR/payment expiry ·
the entire Suchak UI (15 screens) · the entire admin surface (61 routes) · 20 marketplace feature
tests.

---

# What to do first

1. **The three AUDIT items that could be wrong today** — challenge expiry, replay protection,
   collaboration-request expiry. None is implementation; each is a read.
2. **The four READY security items** — 30 minutes for the two OTP ones, plus two throttles.
3. **`visit_completion_marked` notification** — the single highest-value notification in the
   system, because it is the only thing that would tell a customer to act.
4. Everything else once the eight decisions land.

**Nothing above is implementation. This document exists so that when implementation starts,
nothing is forgotten.**
