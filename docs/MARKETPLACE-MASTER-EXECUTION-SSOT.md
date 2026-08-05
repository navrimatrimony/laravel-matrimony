# MARKETPLACE IMPLEMENTATION IS FROZEN

No more planning.
No more audits.
No more scope expansion.

**Implementation starts from U2.**

A fresh session needs exactly three documents: `../CLAUDE.md` (workspace rules) · this file ·
`MARKETPLACE-RUNTIME-TRUTH-LEDGER.md` (evidence for the truths below, keyed MRT-nn). Everything
under `docs/archive/marketplace-planning/` is **ARCHIVED — DO NOT CONSULT DURING IMPLEMENTATION**.

Requests to audit, plan, review or look for missing features are refused unless a running
implementation proves one of the truths below false — that is the only reopening condition.

---

# A. Runtime Truths (RT)

Every implementation decision below cites exactly one of these.

| ID | Truth |
|---|---|
| **RT-1** | Grace period is `MemberAccountDeletionService::GRACE_DAYS` (30). Never restate the number — read the constant, `config('legal.retention.deletion_request_days')`, or the API's `grace_days`. |
| **RT-2** | The daily sweep `account:purge-due-deletions` (03:40) covers every user with `deletion_requested_at`, Suchaks included. Purge is tombstone (UPDATE), so `restrictOnDelete` FKs never fire. |
| **RT-3** | Archiving a Suchak account makes every representation fail `scopePubliclyRoutable()`; contact reveal falls through to the candidate's own settings (default `SHOW_CONTACT_NO_ONE`). "Visible but contact-blocked" is emergent — never re-implement it. |
| **RT-4** | A notification = one Notification class + one `PushTypeRegistry` row. `SendPushForDatabaseNotification` handles delivery, admin switch, user prefs, quiet hours. `SafeNotifier` swallows failures — business action always wins. |
| **RT-5** | Delivery is synchronous database write + best-effort push in the same request. The `notifications` queue has **no production worker** — never dispatch to it. Quiet hours (22:00–08:00) suppress, not delay. The Suchak app has **no inbox**; the DB row is an audit record (MRT-03). |
| **RT-6** | Receiver resolution for any Suchak-facing candidate notification is `SuchakProfileRepresentation::scopeWithValidConsent()`. Anything looser leaks existence to never-consented claim holders (MRT-01). |
| **RT-7** | Notify only when an atomic state flip succeeded: `whereNull(col)->update(...)` affected exactly 1 row. Read-then-check is a concurrency race (MRT-02). |
| **RT-8** | Who cancelled a meeting is already known — `visitCancelActorType()` returns `ACTOR_ADMIN`/`ACTOR_SUCHAK`, logged to `suchak_activity_logs`. The customer **cannot** cancel. Never add actor tracking. |
| **RT-9** | Member meeting endpoints are exactly two (`confirm`, `dispute`) until U9a adds the list. Nothing can hand the app a visit id before that. |
| **RT-10** | Challenge/proposal expiry is lazy by design (`expireDue()` on browse). Meeting timeout is `SuchakClaimSilenceService` (7-day silence, stop-loss, 90-day lapse). Money mutations are replay-safe (`lockForUpdate` + `isSettled()` throw, MRT-05). **Do not add schedulers or re-audit these.** |
| **RT-11** | `suchak_activity_logs` already logs all 24 marketplace events — use those `ACTION_*` names, never invent event names. Event tables beside them are immutable (models throw on mutate). |
| **RT-12** | The candidate is never a debtor; the platform is never a payment party. HISTORICAL subsystems (9, incl. all financial records) are legally untouchable — 8-year retention. |
| **RT-13** | Notification classes are named `<Subject><EventPastTense>Notification` (MRT-11). ARB strings in Flutter apps are inserted **as text** — a JSON round-trip corrupts `{}` placeholder maps and reformats the file. |
| **RT-14** | Registry rows declare `'default_push' => true/false`; user default is opt-out true. New marketplace types ship `default_push => true` (MRT-04). |

# B. Golden Rules (canonical copy — workspace CLAUDE.md mirrors these)

**Cross-Feature Impact Gate.** Before any feature that deletes/wipes/archives/suspends/transfers
state: name the rows touched → `grep -rn "references('<table>')" database/migrations/` → ask which
*person* is mid-way through using the object → ask what money/consent/legal record attaches →
state the result in one line → an affected dependant is **part of the feature**, not a follow-up.

**Dependency Classification.** Finding references ≠ finding blockers. Classify every reference
BLOCKER / AUTO_CLOSE / NOTIFY_ONLY / HISTORICAL / IGNORE. **Only BLOCKERs grow scope.** (121
references became 3 tasks this way.)

**Plan-change rule.** A discovery changes this document only if it is wrong **today** AND cannot
be fixed inside the unit being implemented. Otherwise absorb it.

**Per-unit flow.** Contract (1 page, if the unit sends notifications or changes money) →
Implementation → Tests → Regression → next unit.

---

# C. Units

Status: **U1 COMPLETE** (`a1e0bf1e`) — representations deactivated + alias wiped on purge, purge
race-safe, cancel-after-purge refused. 10+4 tests green.

**U2 COMPLETE** — Suchak notified on customer deletion request/cancel (atomic flips, `withValidConsent()`,
database+push registry). See latest commit on `main`.

Remaining: U3–U8, U9a→U9b→U10/U11, U12. Only dependency chain: U9a→U9b→U10/U11.

---

## U2 — Suchak told the customer is leaving / stayed — **105 min**

**Goal** The representing Suchak learns at day 0 (not day 31) that a customer requested deletion,
and learns just as promptly if they cancel. One-way would be worse than none — "leaving" without
"stayed" strikes a live customer off the Suchak's list.

**Files** `app/Notifications/SuchakCustomerDeletionRequestedNotification.php` (new) ·
`app/Notifications/SuchakCustomerDeletionCancelledNotification.php` (new) ·
`app/Services/Push/PushTypeRegistry.php` · `app/Services/Account/MemberAccountDeletionService.php` ·
`lang/{en,mr}/account.php` · `tests/Feature/MemberAccountDeletionFlowTest.php` (extend)

**Reuse** switchboard per RT-4 · `SafeNotifier` · `scopeWithValidConsent()` per RT-6.

**Exact implementation**
1. Convert `requestDeletion()`/`cancelDeletion()` state writes to atomic flips per RT-7; notify
   only on affected-rows = 1. Existing guards (`account_deleted_at` refusal) stay.
2. Receivers: distinct Suchak users via representations `withValidConsent()` of the member's
   profile, resolved at event time (per RT-6; B's set may lawfully differ from A's).
3. Payload: customer `full_name` + date. Nothing else — no reason, no contacts (privacy-validated).
4. Two registry rows, `default_push => true` per RT-14. Channels: database only per RT-5 (no mail).
5. Names per RT-13.

**Tests** A fires once per valid-consent Suchak · pending-claim / expired-consent / revoked Suchak
excluded · no-Suchak member → zero · second request → no second A · cancel → B once, same
resolution · double cancel → zero · cancel after purge → zero (existing U1 guard) · payload
carries only name+date.

**Regression** `MemberAccountDeletionFlowTest` + `MemberAccountTombstoneTest` +
`SuchakAccountDeletionTest` unchanged green.

**Rollback** `git revert <sha>` — no schema change.

## U3 — Admin told a dispute party is leaving — **45 min**

**Goal** An open dispute never loses a party silently. NOTIFY_ONLY: dispute state untouched.
**Files** `app/Notifications/DisputePartyDeletionRequestedNotification.php` (new) · registry ·
`MemberAccountDeletionService` · test.
**Implementation** On the same atomic flip as U2-A (RT-7): if the member's profile is a party to a
`SuchakDispute` in `open`/`under_review`, notify admins. Names per RT-13; `default_push => true`.
**Tests** open dispute → admins notified once · resolved/closed → none · dispute row unchanged.
**Regression** dispute lifecycle tests green. **Rollback** revert.

## U4 — Throttle member OTP send — **20 min**

**Goal** The route guarding every sign-in stops being unlimited.
**Files** `routes/api.php` · test.
**Implementation** `->middleware('throttle:10,1')` on `POST /auth/mobile-otp/send` (mirror of the
Suchak group's existing declaration).
**Tests** 11th send in a minute → 429; 10th → passes.
**Regression** OTP flow tests green. **Rollback** revert.

## U5 — Suchak OTP fails closed in production — **40 min**

**Goal** No admin setting alone can emit a plaintext OTP in production.
**Files** `app/Modules/Suchak/Services/SuchakRegistrationService.php` · test.
**Implementation** In `issueOtp()`: production + resolved `dev_show` → do not return the OTP
(mirror the guard in `MobileOtpService::resolveDeliveryMode()`).
**Tests** production+`dev_show` → no OTP in response · local/testing unchanged.
**Regression** confirm `EnsureSuchakLegacyOtpEnabled` remains off in production, or the owner's
own code sign-in breaks. **Rollback** revert.

## U6 — Throttle challenge publish + proposal — **25 min**

**Files** `routes/api/suchak.php` · test.
**Implementation** `throttle:10,1` on `POST /marketplace/challenges` and
`POST /marketplace/challenges/{challenge}/proposals`.
**Tests** 11th of each in a minute → 429. **Regression** marketplace tests green. **Rollback** revert.

## U7 — Honest cancellation record + fair `cancelled_rate` — **65 min**

**Goal** Cancellations carry reason and attendance, and a Suchak is not penalised for an admin's
cancellation. **No actor tracking** — RT-8.
**Files** `SuchakVisitConfirmationService` · `SuchakReputationService` ·
`SuchakMeetingEngineTest` · `SuchakReputationReadTest`.
**Implementation** On cancel, write reason + attendance into the existing event
`metadata_json` (no new columns). In reputation, exclude admin-actor cancellations from the
`cancelled_rate` **numerator** (denominator unchanged) — actor comes from the activity log per RT-8.
**Tests** reason+attendance recorded · event immutable · admin-cancel does not raise the rate ·
Suchak-cancel does. **Rollback** revert.

## U8 — Customer told a meeting awaits them — **60 min**

**Goal** Without this, U9–U11 are reachable but undiscoverable.
**Files** `app/Notifications/SuchakMeetingCompletionMarkedNotification.php` (new) · registry ·
`SuchakVisitConfirmationService` · `lang` · test.
**Implementation** Fire where `visit_completion_marked` is already logged (RT-11), to the
customer-side user. RT-4/5/13/14 apply. Idempotent: marking complete twice notifies once (the
completion transition itself is single-shot).
**Tests** complete → customer notified once · double-complete → once. **Rollback** revert.

## U9a — Member API: meetings list — **40 min**

**Goal** RT-9: nothing can hand the app a visit id until this exists.
**Files** `MemberSuchakMeetingApiController` · `routes/api/member.php` · test.
**Implementation** `GET /api/v1/suchak-meetings` — only meetings whose customer side is the
caller (same guard family as `assertCustomerSideUserCanConfirm()`). Rows: id, status, scheduled
time, Suchak display name.
**Tests** own meetings visible · another member sees none · unauthenticated refused.
**Rollback** revert.

## U9b — Member app: meetings list (read-only) — **90 min** · depends U9a

**Files** `flutter-apk`: `api_routes.dart` · `api_client.dart` ·
`features/suchak/meetings_screen.dart` (new) · `main.dart` · ARB (as text, RT-13).
**Implementation** List over U9a, entry from the existing Suchak-requests screen. Honest empty
state. **Tests** `flutter analyze` clean · widget test renders + empty state ·
`flutter build apk --debug`. **Rollback** revert.

## U10 — Member app: confirm a meeting — **60 min** · depends U9b

**Implementation** Action on U9b rows calling the existing
`POST /suchak-meetings/{visit}/confirm`; offered only in the state the server accepts; refusal
surfaces as one sentence. **Tests** state-gating matches server. **Rollback** revert.

## U11 — Member app: dispute a meeting — **60 min** · depends U9b

**Implementation** As U10 over `POST /suchak-meetings/{visit}/dispute`, reason required.
**Tests** reason required · state gating. **Rollback** revert.

## U12 — Publisher told a proposal arrived — **60 min**

**Goal** A proposal nobody sees is a challenge that fails for no reason.
**Files** `app/Notifications/MarketplaceProposalReceivedNotification.php` (new) · registry ·
challenge service · test.
**Implementation** Fire where `marketplace_proposal_received` is logged (RT-11), to the
publishing Suchak's user. RT-4/5/13/14. Proposer never notified of their own action.
**Tests** publisher notified once · proposer not. **Rollback** revert.

---

## Footer

Planning documents archived: **8** → `docs/archive/marketplace-planning/`
Master document: `docs/MARKETPLACE-MASTER-EXECUTION-SSOT.md`
Implementation units: **13** (U1–U2 complete; U3–U12 remaining)
Estimated implementation time: **~9 h 25 remaining** (12 h 45 total − U1 − U2)
Known out-of-scope decisions: **12** — meeting reschedule · customer meeting-cancel ·
suspension/deletion/Suchak-switch vs live engagements · chargeable ceiling · suggestion Viewed
semantics · marketplace email · event-log retention · `failed_jobs` alerting · ranking ·
web-vs-app stage confirmation · proposal withdrawal (MRT-08) · marketplace consent text (MRT-10)
Next unit: **U3**
