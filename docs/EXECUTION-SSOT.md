# Execution SSOT

**This document replaces all marketplace and account-deletion planning.** Start here. Do not read
the planning documents; they are history and several are stale.

**Planning is frozen (2026-08-05).** No new planning document may be written unless implementation
discovers a *contradiction in runtime truth* — a fact stated below that turns out to be false.
Scope does not grow because something looks interesting.

**Superseded (do not consult):** `MATCHMAKER-MARKETPLACE-BLUEPRINT.md`,
`MARKETPLACE-ATOMIC-UNITS-SSOT.md`, `MARKETPLACE-WBS-SSOT.md`,
`MARKETPLACE-JOURNEY-WBS-SSOT.md`, `MARKETPLACE-PLANNING-SSOT.md`,
`MARKETPLACE-BUSINESS-SCENARIOS-SSOT.md`, `SUCHAK-ACCOUNT-DELETION-SSOT.md`.

---

# 1. Frozen Runtime Truth

Verified against the running system on 2026-08-05. Implementation depends on every line here.

## 1.1 Account deletion (shipped)

- Member and Suchak deletion are **live**. Grace period is `MemberAccountDeletionService::GRACE_DAYS`
  = 30. **Never restate 30 anywhere** — read the constant, or `config('legal.retention.deletion_request_days')`,
  or the API's `grace_days`.
- The daily sweep `account:purge-due-deletions` (03:40) selects **every** user with
  `deletion_requested_at`. A Suchak account is owned by a user, so Suchaks are already covered.
- `UserAccountDatabasePurger::purgeUserAccount($user, keepCounterpartConversations: true)` ends in
  `reduceUserToTombstone()` — an **UPDATE, not a delete**. Foreign keys are therefore never
  reached. This is why `suchak_accounts.user_id` (`restrictOnDelete`) does not block a purge.
- `MatrimonyProfileDatabasePurger::purge($profile, $keepCounterpartConversations)` wipes the
  profile from an **allow-list of columns to KEEP**, so a column added later is erased by default.
- `SuchakAccountDeletionService` archives the Suchak account and revokes its representations.
  Archiving is what stops contact routing — see 1.2.

## 1.2 Contact routing (the load-bearing cascade)

`SuchakProfileRepresentation::scopePubliclyRoutable()` requires the Suchak account to be
`VERIFICATION_VERIFIED` **and** `PUBLIC_ACTIVE`. `SuchakContactRouting::isRouted()` is built on it.
`ContactActionApiController` branches on `isRouted()` and otherwise falls through to the
candidate's own `profile_visibility_settings`, where the safe default for Suchak-created profiles
is `SHOW_CONTACT_NO_ONE`.

**Consequence:** "profile visible, contact blocked" is an emergent property of archiving. It is
never to be re-implemented.

## 1.3 Marketplace — what is built

Suchak side is complete: ~35 API routes, 15 Flutter screens, 20 feature tests. Admin side is
complete: 61 routes. Challenge, tranche, marriage-outcome, obligation tables all exist.
`agreementSnapshotHash()` covers all four money fields. Cross-Suchak masking is single-path
(`SuchakCandidateMaskingService`) and `is_broad` is computed, not hardcoded.

**Do not re-plan or re-verify any of the above.**

## 1.4 Marketplace — what is not built

- **Zero marketplace notifications.** Of 26 notification classes, none is about a challenge,
  proposal, meeting, dispute, obligation or marriage. Zero marketplace push types are registered.
- **The customer has no hands.** These endpoints exist, are guarded and tested, and are called by
  **no app**:
  - `POST /api/v1/suchak-meetings/{visit}/confirm`
  - `POST /api/v1/suchak-meetings/{visit}/dispute`
  - `POST /api/v1/suchak-engagements/{collaboration}/stages/confirm`
- **Those are the ONLY member meeting endpoints.** `MemberSuchakMeetingApiController` has exactly
  two methods; **no list route exists**, so nothing can hand the app a visit id until U9a builds it.
- `shared_display_name` is a **runtime display alias** with no evidentiary role — no snapshot or
  hash carries it, and its only reader (`SuchakCandidateMaskingService::displayName()`) returns
  early once `full_name` is wiped. It is erased at purge (U1); do not treat it as a legal record.
- **Throttling is two lines wide** across ~35 marketplace routes.
- `POST /api/v1/auth/mobile-otp/send` has **no throttle**.
- `SuchakRegistrationService::issueOtp()` reads `AdminSetting('mobile_verification_mode')`
  defaulting to `dev_show` with **no environment guard**. Production holds `dev_show`. Mitigated
  only by `EnsureSuchakLegacyOtpEnabled`, which is off in production.

## 1.5 Notification delivery (already solved — reuse, never rebuild)

`SendPushForDatabaseNotification` turns **any** database notification into a push, honouring the
admin switch, the per-type user preference and quiet hours. A new notification is therefore
**one notification class + one `PushTypeRegistry` row**. `SafeNotifier::notify()` swallows and logs
failures so a notification can never break the business action.

## 1.6 Facts that close arguments

- A cross-Suchak share is **not owed by a candidate** — stated in the obligations migration. The
  candidate is never a debtor.
- Five models throw on mutate: `SuchakActivityLog`, `SuchakVisitConfirmationEvent`,
  `SuchakConsentEvent`, `SuchakPipelineEvent`, `SuchakProfileRepresentation`.
- `suchak_activity_logs` carries 118 action types, 24 of them marketplace. **Every marketplace
  event is already logged.** Use these names; do not invent event names.
- `matrimony_profiles` carries **121 foreign key references**; 31 from the Suchak side are
  `restrictOnDelete`, 2 are `cascadeOnDelete`.
- `suchak_profile_representations.candidate_deactivated_at` **exists and is already read** by
  `SuchakConsentService`, `SuchakCustomerListService` and `DashboardController`. **Nothing writes
  it.**
- **Expiry is lazy by design, not missing.** `SuchakMarketplaceChallengeService::expireDue()` runs
  on browse; `SuchakCollaborationService::expireForAccount()` / `expireIfPastDue()` do the same for
  proposals. The code says so in as many words: *"instead of waiting for a scheduler."* A challenge
  nobody browses stays `open`, which cannot matter — an unbrowsed board is not a market. **Do not
  add a scheduler for either.**
- Meeting timeout is fully handled by `SuchakClaimSilenceService`: a 7-day silence clock, a
  stop-loss counter and a 90-day lapse. A meeting the customer never answers is not an open
  problem.
- **Who cancelled a meeting is already known.** `visitCancelActorType()` returns `ACTOR_ADMIN` or
  `ACTOR_SUCHAK` and the value is written to `suchak_activity_logs`. **Never add actor tracking to
  cancellations.** Only a Suchak or an admin can cancel at all — `assertCancellable()` additionally
  refuses once a meeting is marked completed. **The customer cannot cancel**, so any rule assuming
  they can is wrong.

---

# 2. Frozen Product Decisions

Accepted. Rejected alternatives are omitted deliberately.

1. Suchak account archives **immediately** on deletion request; the erase runs on day 31.
2. Member and Suchak deletion V1 ship **without** WhatsApp notification or a landing page.
3. The platform **never** participates in commercial negotiation between a Suchak and a candidate.
   Policy only; no code.
4. Revoked representations **stay revoked** if a Suchak cancels inside the notice period. The
   account returns; the customers do not.
5. Deleted-member conversations: the counterpart **keeps** the thread; the leaver is
   de-identified inside it.
6. The confirmation word is the English `delete` in both languages.
7. **No BLOCKER exists for member deletion.** Google Play requires unconditional deletion and the
   candidate owes nothing, so deletion always proceeds.

---

# 3. Dependency Classification (Frozen)

For member account deletion against the Suchak domain. **Only BLOCKERs grow scope.**

| Category | Count | Subsystems |
|---|---|---|
| **BLOCKER** | **0** | — |
| **AUTO_CLOSE** | 4 | `suchak_profile_representations` · `suchak_match_suggestions` · `suchak_profile_requests` + `suchak_pipelines` · `suchak_workflow_reminders` |
| **NOTIFY_ONLY** | 2 | active `suchak_visit_confirmations` · open `suchak_disputes` |
| **HISTORICAL** | 9 | activity logs · visit events · consent events · pipeline events · marriage outcomes · payment contexts · platform payouts · CRM ledger · customer lifecycle |
| **IGNORE** | 5 | biodata intake links · pdf/qr · growth rewards · lead allocation · profile update suggestions |

**HISTORICAL is not "do it later" — it is "touching it is a legal violation."** Financial records
carry an 8-year retention obligation.

---

# 4. Golden Rules

Both live in `../CLAUDE.md` and apply to **every** feature, not only these units.

## 4.1 Cross-Feature Impact Gate

Before writing code for any feature that deletes, wipes, archives, suspends, transfers ownership
of, or changes the state of an existing row (pure additions are exempt):

1. Name the rows you touch.
2. Sweep who points at them: `grep -rn "references('<table>')" database/migrations/`.
   `restrictOnDelete` is a hard dependant; `cascadeOnDelete` is a silent one, which is worse.
3. Ask which **person** — not which code — is mid-way through using that object.
4. Ask what money, consent or legal record attaches to it.
5. State the result in one line before writing code.
6. If a dependant is affected, it is **part of this feature**, not a follow-up.

## 4.2 Dependency Classification Rule

Finding references is **not** finding blockers. Classify every reference as BLOCKER /
AUTO_CLOSE / NOTIFY_ONLY / HISTORICAL / IGNORE before letting any of it grow the feature.
**Only BLOCKERs increase scope.** State the counts in one line.

*This pair turned a 121-reference sweep into three tasks.*

---

# 5. Out of Scope

**Do not touch during execution.** Not because they are unimportant — because they are decided,
finished, or blocked.

- Anything HISTORICAL in §3. Modifying it is a legal violation.
- The Suchak-side marketplace UI (15 screens) and the admin surface (61 routes). Complete.
- Challenge, tranche, marriage-outcome and obligation engines. Complete.
- Cross-Suchak masking. Complete and single-path.
- The `agreementSnapshotHash()` payload. Complete.
- The account-deletion engines shipped on 2026-08-05, except where a unit below names them.
- **Meta WhatsApp credentials** — an owner track, not engineering.
- **`PAYU_ENV=test` → live** — an owner switch, not engineering.

**Blocked on a product decision — do not build:** meeting reschedule · customer-initiated meeting
cancel · what suspension/deletion/Suchak-switch does to a live engagement · chargeable ceiling on
a challenge · suggestion Viewed semantics · marketplace email · event-log retention ·
`failed_jobs` alerting · marketplace ranking · whether the tokenised web link suffices for stage
confirmation.

---

# 6. Execution Units

Every unit: compiles, tests pass, ends in a commit, depends on nothing unfinished, ≤2 hours.

Run **U1–U3 first** — they close a defect shipped on 2026-08-05.

---

## U1 — Deactivate Suchak representations when a member is purged, and make the purge race-safe

**Goal** A member who deletes their account stops being an active customer of their Suchak — and a
member who *cancels* can never be purged by a sweep that had already loaded them.
**Scope** The AUTO_CLOSE group, the erasure of the display alias, and the purge race guard.
No notifications; no BLOCKER logic.

**Reuse** `MatrimonyProfileDatabasePurger::purge()` · `suchak_profile_representations.candidate_deactivated_at`
(exists, already read in three places, written nowhere) · `MemberAccountDeletionService::purgeDue()`

**Files** `app/Services/Maintenance/MatrimonyProfileDatabasePurger.php` ·
`app/Services/Account/MemberAccountDeletionService.php` ·
`tests/Feature/MemberAccountTombstoneTest.php` · `tests/Feature/MemberAccountDeletionFlowTest.php`

**Behaviour**

1. In `purge()`, before the tombstone/forceDelete branch: on every representation of that profile,
   set `candidate_deactivated_at = now()` where null **and null `shared_display_name`** — it is a
   runtime display alias with no evidentiary role (verified: no snapshot or hash carries it, and
   its only reader returns early once `full_name` is wiped), so leaving it stored would break the
   published erasure promise. Also close pending `suchak_profile_requests` / `suchak_pipelines`
   rows to their existing `expired`/`cancelled` state. **Do not delete anything** —
   `SuchakProfileRepresentation::delete()` throws.
2. In `purgeDue()`: re-load each user **inside the per-user transaction with `lockForUpdate()`**
   and skip unless `deletion_requested_at` is still set, still past the window, and
   `account_deleted_at` is still null. The sweep materialises its list before iterating, so a
   cancellation landing mid-sweep must be seen by the purge itself, not only by the query.
3. In `cancelDeletion()`: refuse (no-op) when `account_deleted_at` is already set, so a cancel
   racing the commit cannot half-revive a tombstone.

**Tests** A purged member's representation has `candidate_deactivated_at` set,
`shared_display_name` null, and still exists · the Suchak's customer list no longer counts them ·
a representation belonging to another member is untouched · a user who cancelled is not purged by
a subsequent sweep · `cancelDeletion()` after purge is a no-op.

**Runtime verification** `php artisan test tests/Feature/MemberAccountTombstoneTest.php tests/Feature/MemberAccountDeletionFlowTest.php`

**Rollback** `git revert <sha>`. No schema change, so no data risk.

**Time** 95 min

---

## U2 — Tell the Suchak their customer is leaving, and if they stay

**Goal** The Suchak learns at the start of the 30-day window, not on day 31 — and learns just as
promptly if the member changes their mind.
**Scope** Two notifications, both directions. No UI.

**Why both** A one-way notification is worse than none: a Suchak told "your customer is leaving"
and never told "they stayed" will strike a live customer off their list. The reverse is not a
follow-up; it is the other half of this unit.

**Reuse** `SendPushForDatabaseNotification` (already turns any database notification into a push) ·
`PushTypeRegistry` · `SafeNotifier::notify()` · `MemberAccountDeletionService::requestDeletion()`

**Files** `app/Notifications/SuchakCustomerLeavingNotification.php` (new) ·
`app/Services/Push/PushTypeRegistry.php` · `app/Services/Account/MemberAccountDeletionService.php` ·
`lang/{en,mr}/account.php` · new test

**Behaviour** On `requestDeletion()`, for each Suchak with a live representation of that profile,
notify once. On `cancelDeletion()`, notify the same Suchaks that the customer stayed. **Copy
carries no personal data beyond the customer name the Suchak already holds** — name the customer
and the date, nothing else.

**Tests** Requesting deletion notifies each representing Suchak exactly once · a member with no
Suchak notifies nobody · **cancelling notifies the same Suchaks once** · requesting twice notifies
once.

**Runtime verification** `php artisan test --filter=SuchakCustomerLeaving`

**Rollback** `git revert <sha>`.

**Time** 105 min

---

## U3 — Tell the admin when a departing member has an open dispute

**Goal** A dispute does not lose a party silently.
**Scope** Notification only. The dispute is NOTIFY_ONLY — **never change its state.**

**Reuse** the U2 notification path · `SuchakDispute`

**Files** `app/Notifications/DisputePartyLeavingNotification.php` (new) ·
`PushTypeRegistry` · `MemberAccountDeletionService` · new test

**Tests** An open dispute notifies admins · a resolved/closed dispute does not · dispute state is
unchanged after the notification.

**Runtime verification** `php artisan test --filter=DisputePartyLeaving`

**Rollback** `git revert <sha>`.

**Time** 45 min

---

## U4 — Throttle the member OTP send route

**Goal** The route that guards every sign-in stops being unlimited.
**Scope** One middleware. Nothing else.

**Reuse** Laravel `throttle`. `routes/api/suchak.php` already uses `throttle:10,1` and its comment
names this exact one-sided gap.

**Files** `routes/api.php` · new test

**Behaviour** `Route::post('/auth/mobile-otp/send', …)->middleware('throttle:10,1')`.

**Tests** The eleventh send within a minute returns 429; the tenth succeeds.

**Runtime verification** `php artisan route:list --path=auth/mobile-otp` shows the middleware.

**Rollback** `git revert <sha>`.

**Time** 20 min

---

## U5 — Make the Suchak OTP issuer fail closed in production

**Goal** No admin setting alone can hand out a plaintext OTP in production.
**Scope** One guard in one method.

**Reuse** the environment check already in `MobileOtpService::resolveDeliveryMode()`.

**Files** `app/Modules/Suchak/Services/SuchakRegistrationService.php` · new test

**Behaviour** In `issueOtp()`, if `app()->isProduction()` and the resolved mode is `dev_show`,
do **not** return the OTP.

**Tests** Production + `dev_show` returns no OTP in the response · local/testing is unchanged.

**Runtime verification** `php artisan test --filter=SuchakRegistration`. **Also confirm
`EnsureSuchakLegacyOtpEnabled` is still off in production**, or the owner's own code sign-in
breaks.

**Rollback** `git revert <sha>`.

**Time** 40 min

---

## U6 — Throttle challenge publish and proposal

**Goal** One account cannot flood the marketplace board.
**Scope** Two middleware declarations.

**Files** `routes/api/suchak.php` · new test

**Behaviour** `throttle:10,1` on `POST /marketplace/challenges` and
`POST /marketplace/challenges/{challenge}/proposals`.

**Tests** The eleventh publish in a minute returns 429; the same for proposals.

**Rollback** `git revert <sha>`.

**Time** 25 min

---

## U7 — Make the cancellation record honest, and stop charging it to the wrong Suchak

**Goal** A Suchak is no longer penalised for a cancellation they did not make.
**Scope** Exactly three things. Nothing else.

1. Capture the cancellation **reason** (if not already stored).
2. Capture the **attendance outcome** (if not already stored).
3. **Exclude admin-cancelled meetings** from `cancelled_rate`.

**Do not add actor tracking.** `SuchakVisitConfirmationService::visitCancelActorType()` is already
the runtime source of truth — it returns `ACTOR_ADMIN` or `ACTOR_SUCHAK` and the value is already
written to `suchak_activity_logs`. Duplicating it would breach the no-duplicate rule.

**Why item 3 belongs here** Two implemented rules contradict each other today.
`visitCancelActorType()` always knows who cancelled, while
`SuchakReputationService::cancelled_rate` counts every cancellation against the Suchak — including
the ones an admin made on safety grounds or as the outcome of a dispute. The published number then
answers *"how often does a meeting he arranged never happen?"* with events he had no part in. The
data to fix it already exists; only the query does not use it.

**Reuse** `suchak_visit_confirmation_events.metadata_json` · `EVENT_CANCELLED` ·
`visitCancelActorType()` · `suchak_activity_logs.actor_type`

**Files** `app/Modules/Suchak/Services/SuchakVisitConfirmationService.php` ·
`app/Modules/Suchak/Services/SuchakReputationService.php` ·
`tests/Feature/Suchak/SuchakMeetingEngineTest.php` ·
`tests/Feature/Suchak/SuchakReputationReadTest.php`

**Behaviour** On cancel, write reason and attendance into `metadata_json`. In
`SuchakReputationService`, exclude cancellations whose actor was an admin from the `cancelled_rate`
numerator. The denominator (`total`) is unchanged — every meeting arranged still counts.

**Tests** Cancelling records reason and attendance · the event row stays immutable · **an
admin-cancelled meeting does not raise the Suchak's `cancelled_rate`** · a Suchak-cancelled one
still does.

**Runtime verification** `php artisan test tests/Feature/Suchak/SuchakMeetingEngineTest.php tests/Feature/Suchak/SuchakReputationReadTest.php`

**Rollback** `git revert <sha>`. No schema change.

**Time** 65 min

---

## U8 — Tell the customer a meeting is waiting for them

**Goal** The customer learns a meeting was marked complete. Without this, U9–U11 are reachable but
undiscoverable.
**Scope** One notification, fired where `visit_completion_marked` is already logged.

**Reuse** the U2 notification path.

**Files** `app/Notifications/MeetingAwaitingConfirmationNotification.php` (new) · `PushTypeRegistry` ·
`SuchakVisitConfirmationService` · `lang/{en,mr}/…` · new test

**Tests** Marking complete notifies the customer once · marking complete twice notifies once.

**Rollback** `git revert <sha>`.

**Time** 60 min

---

## U9a — Member API: my meetings list endpoint

**Goal** The customer can enumerate their own meetings. Without this, no app screen can exist —
`MemberSuchakMeetingApiController` has exactly two methods (`confirm`, `dispute`) and **no list
route exists anywhere**, so the app has no way to obtain a visit id.

**Scope** One GET route, authorization, tests. Laravel only.

**Reuse** `MemberSuchakMeetingApiController` (same controller, same `viewerContext()` guard family) ·
`SuchakVisitConfirmationService`

**Files** `app/Http/Controllers/Api/MemberSuchakMeetingApiController.php` ·
`routes/api/member.php` · new test

**Behaviour** `GET /api/v1/suchak-meetings` returns only meetings whose customer side is the
caller — the same person `assertCustomerSideUserCanConfirm()` accepts. Each row carries what the
confirm/dispute actions need: id, status, scheduled time, Suchak display name.

**Tests** The caller sees their own meetings · another member sees none of them · an
unauthenticated call is refused.

**Runtime verification** `php artisan test --filter=MemberSuchakMeeting` ·
`php artisan route:list --path=suchak-meetings` shows GET.

**Rollback** `git revert <sha>`.

**Time** 40 min

---

## U9b — Member app: my meetings list (read-only)

**Goal** The customer can see their meetings. No actions yet.
**Scope** `flutter-apk` only. Read-only, over the U9a endpoint.

**Reuse** existing `ApiClient` helpers · the member Suchak-requests screen as the entry point ·
existing ARB pattern (**insert strings as text; a JSON round-trip corrupts empty placeholder maps
and reformats the whole file**).

**Files** `lib/core/api_routes.dart` · `lib/core/api_client.dart` ·
`lib/features/suchak/meetings_screen.dart` (new) · `lib/main.dart` · `lib/l10n/app_{en,mr}.arb`

**Tests** `flutter analyze` clean · widget test that the list renders and empty state is honest.

**Runtime verification** `flutter build apk --debug`.

**Rollback** `git revert <sha>`.

**Time** 90 min · **depends on U9a**

---

## U10 — Member app: confirm a meeting

**Goal** The customer can confirm. This is the act the money model depends on.
**Scope** One action on the U9 screen.

**Reuse** `POST /api/v1/suchak-meetings/{visit}/confirm` (exists, guarded, tested).

**Files** `lib/core/api_client.dart` · `lib/features/suchak/meetings_screen.dart` ·
`lib/l10n/app_{en,mr}.arb`

**Tests** The action is offered only in the state the server accepts · a server refusal surfaces as
one sentence.

**Rollback** `git revert <sha>`.

**Time** 60 min · **depends on U9b**

---

## U11 — Member app: dispute a meeting

**Goal** The customer can say it did not happen.
**Scope** One action, reason required.

**Reuse** `POST /api/v1/suchak-meetings/{visit}/dispute` (exists, guarded, tested).

**Files** same as U10

**Tests** Dispute requires a reason · state gating matches the server.

**Rollback** `git revert <sha>`.

**Time** 60 min · **depends on U9b**

---

## U12 — Tell the publisher a proposal arrived

**Goal** A proposal nobody sees is a challenge that fails for no reason.
**Scope** One notification at `marketplace_proposal_received`.

**Reuse** the U2 notification path · `SuchakMarketplaceChallengeService`

**Files** `app/Notifications/MarketplaceProposalReceivedNotification.php` (new) · `PushTypeRegistry` ·
the publishing service · new test

**Tests** Proposing notifies the publisher once · the proposer is not notified of their own action.

**Rollback** `git revert <sha>`.

**Time** 60 min

---

## Order and totals

| Unit | Time | Depends on |
|---|---|---|
| U1 · U2 · U3 | 4 h 05 | — (closes a 2026-08-05 defect) |
| U4 · U5 · U6 | 1 h 25 | — |
| U7 | 1 h 05 | — |
| U8 | 1 h 00 | — |
| U9a → U9b → U10 → U11 | 4 h 10 | chain, U9a first |
| U12 | 1 h 00 | — |
| **Total** | **12 h 45** | |

Every unit is at or under two hours. The only dependency chain is U9a → U9b → U10/U11.

---

# 7. Definition of Done

Per unit: compiles · tests pass · runtime verification performed · commit made · rollback is a
single `git revert`.

Overall, execution is done when U1–U12 are committed. At that point:

- A member deleting their account no longer leaves their Suchak with a ghost customer.
- The Suchak is told, and the admin is told if a dispute is open.
- No sign-in path can hand out a plaintext code in production, and the OTP and marketplace-write
  routes are rate-limited.
- A cancelled meeting records who cancelled it and why.
- A customer can see, confirm and dispute their meetings, and is told when one needs them.
- A Suchak is told when a proposal arrives.

**Anything not listed in §6 is out of scope.**

## Changing the plan after this point

A newly discovered issue may change the plan **only if it satisfies both**:

1. It produces incorrect runtime behaviour **today**, and
2. It cannot be safely fixed inside the execution unit currently being implemented.

Otherwise **fix it inside the active unit**. Do not reopen planning, do not write a document, do
not add a unit. Discovering something interesting is not a reason to stop building.

Planning was reopened four times to reach this list, and each pass returned less: a journey audit,
a matrix audit, a scenario audit, a transition audit and a business-rule audit between them found
one real defect that changed the plan. The rule above exists so the next discovery is absorbed
rather than escalated.

**Self-containment test:** every unit above names its files, its reused services, its tests, its
verification command and its rollback. No unit requires remembering a conversation.
