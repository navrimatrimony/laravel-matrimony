# Suchak Account Deletion — SSOT (V1 only)

**Scope:** the smallest change that makes the Suchak app compliant with Google Play's
account-deletion policy without breaking any existing business rule.

**Hard limit:** total implementation under one hour, one developer.

**Basis:** Runtime Truth Audit, 2026-08-05. Facts below were read out of the code, not inferred.

Everything is classified **REQUIRED FOR V1** / **NOT REQUIRED FOR V1** / **FUTURE VERSION**.
Anything not required for V1 is stated once and then absent from the plan.

---

# A. VERIFIED FACTS

Only the four that V1 actually depends on. The rest of the audit is history, not requirements.

## A1. Archiving the Suchak account stops contact routing by itself — VERIFIED

`SuchakProfileRepresentation::scopePubliclyRoutable()` (`app/Models/SuchakProfileRepresentation.php:350`)
requires the Suchak account to be `VERIFICATION_VERIFIED` **and** `PUBLIC_ACTIVE`.

`SuchakContactRouting::isRouted()` (`app/Support/Suchak/SuchakContactRouting.php:36`) is built on
that scope. `ContactActionApiController:55` branches on it and otherwise falls through to the
candidate's own `profile_visibility_settings`, where `ContactRevealPolicyService` enforces the
existing gates. Safe default for Suchak-created profiles is `SHOW_CONTACT_NO_ONE`
(`ProfileVisibilitySettingsDefaultsService:61`).

**Consequence:** "profile stays visible, contact is blocked" needs no code. It is what
`archive()` already causes.

## A2. `archive()` does not require an admin — VERIFIED

`SuchakAccountLifecycleService::archive(SuchakAccount $account, User $admin, string $reason, …)`
(`:110`). `$admin` is typed plain `User` and used only for audit attribution; no `isAdmin()`
assertion exists in the service. The Suchak's own `User` can be the actor.

Precondition: `verification_status` ∈ {`verified`, `suspended`} (`:114`).

## A3. The existing sweep already covers Suchaks — VERIFIED

`MemberAccountDeletionService::dueForPurge()` (`:168`) selects **every** `User` with
`deletion_requested_at` set. A Suchak account is owned by a `User`, so setting that one column
puts the Suchak into the sweep that already runs daily at `03:40`.

**Consequence:** zero scheduler work.

## A4. Tombstone mode does not trip the Suchak foreign key — VERIFIED

`suchak_accounts.user_id → users` is `restrictOnDelete`
(`2026_06_09_120000_create_suchak_account_foundation_tables.php:42`), which would normally block
a purge. It does not, because `purgeDue()` calls
`purgeUserAccount(..., keepCounterpartConversations: true)`, and that path ends in
`reduceUserToTombstone()` — an UPDATE, never a delete. The row survives wiped, so the FK is never
violated.

**Consequence:** zero purger work.

---

# B. WHAT V1 IS NOT

Stated once, then gone from this document.

| Item | Class | Why not in V1 |
|---|---|---|
| Proactive candidate notification | FUTURE VERSION | Needs OTP delivery, which needs Meta approval. Contact is already blocked without it, so nothing is exposed while it waits. |
| Consent landing page | FUTURE VERSION | Nothing links to it until notification exists. |
| Transfer to another Suchak | NOT REQUIRED FOR V1 | Already shipped: `MemberSuchakRequestApiController` → `SuchakRequestPipelineService::createRequest()`. A candidate can already pick a new Suchak unaided. |
| Admin deletion queue | NOT REQUIRED FOR V1 | `restrictOnDelete` on `suchak_customer_agreements` blocks the purge and `purgeDue()` already logs the failure. Read the log. |
| Impact summary screen | NOT REQUIRED FOR V1 | Nice to have. Not a Play requirement, not a business rule. |
| Representation history screen | FUTURE VERSION | Data is already in `suchak_activity_logs`. A view can be added any time. |
| New `action_type` | NOT REQUIRED FOR V1 | `archive()` already writes `suchak_account_archived`; representation changes reuse `representation_status_changed`. |
| Pending-meeting special handling | FUTURE VERSION | Real, but no Suchak has a pending meeting at launch. Revisit before the first one does. |

---

# C. PRODUCT OWNER DECISIONS

Only the ones that change V1 code. The rest were removed with the features that needed them.

## C1. Does V1 ship without telling candidates? — PO DECISION

V1 revokes representations and blocks contact, but sends no message. The candidate can already
see who manages their profile (`MemberSuchakRequestApiController::showForProfile`) and can
already request a different Suchak, so they are not stranded — they are simply not told
proactively.

**Recommendation:** yes. The protective action happens immediately; only the courtesy is
deferred, and it is deferred because Meta approval is not in our hands.

## C2. Does the Suchak's account archive immediately on request? — PO DECISION

V1 assumes yes, matching the member flow shipped on 2026-08-05.

**Recommendation:** yes. Archiving is the mechanism that blocks contact (A1); deferring it means
30 days of no protection.

## C3. Confirm the platform never participates in pricing — PO DECISION

No V1 code depends on this, but it is the standing policy the deferred transfer flow will be
built against.

**No implementation blocked by this.**

---

# D. IMPLEMENTATION PLAN — V1

Three commits. Each compiles, passes tests, and is independently deployable.

## D1 — Suchak deletion orchestrator + endpoint — REQUIRED FOR V1 — **25 min**

One service, `SuchakAccountDeletionService`, with one method. It calls; it decides nothing:

1. Assert `verification_status` ∈ {`verified`, `suspended`} (A2).
2. Return early if `deletion_requested_at` is already set — idempotent, no clock restart.
3. `SuchakAccountLifecycleService::archive($account, $suchakUser, $reason)` — **contact stops here** (A1).
4. Set `revoked_at` + `representation_status = revoked` on that account's representations.
5. Set `User.deletion_requested_at = now()` — the existing sweep takes it from there (A3, A4).

One `POST /api/v1/suchak/account/deletion` endpoint calling it, guarded by the existing
`suchak.account` middleware.

One feature test: archive happened, representations revoked, `isRouted()` false, `deletion_requested_at` set.

*Deliberately not calling `MemberAccountDeletionService::requestDeletion()` — that archives the
actor's own matrimony profile, which is a different thing from closing a Suchak business account.*

## D2 — Suchak app: Settings → Delete account — REQUIRED FOR V1 — **20 min**

Play requires an in-app path. Copy the member-app screen shipped on 2026-08-05 and cut it down:
no pause option (a Suchak business account has no pause), reason optional, typed `delete`
confirmation kept.

The grace period is read from the server via the existing `GET /account/deletion`, which needs
only a session and already returns `grace_days`. Not compiled in: this screen must not be able to
promise a window different from the one the sweep enforces. Until it loads there is nothing to
show, so the screen waits and offers a retry rather than guessing.

Entry point: a plain row in `lib/features/profile/profile_screen.dart`, styled like the existing
rows.

*Reuses the member screen's structure and strings pattern; no new design.*

## D3 — Correct the public page — REQUIRED FOR V1 — **5 min**

`/delete-account` section 9 currently tells Suchaks to email support. Once D2 ships that is
false. Replace with the in-app steps, both locales, in `lang/{en,mr}/legal.php`.

Same class of bug as the privacy-policy sentence corrected on 2026-08-05: a page describing a
product we no longer have.

---

# E. TOTAL

| Task | Time |
|---|---|
| D1 orchestrator + endpoint + test | 25 min |
| D2 Suchak app screen | 20 min |
| D3 public page copy | 5 min |
| **Total** | **50 min** |

## Footprint

```
new tables            0
new columns           0
new lifecycle states  0
new action_types      0
new schedulers        0
new purger logic      0
new services          1   (orchestration only)
new screens           1   (Suchak app)
new pages             0
```

## Why it is this small

Three audit findings removed most of the work: `archive()` blocks contact on its own (A1), the
daily sweep already selects any user with `deletion_requested_at` (A3), and tombstone mode
sidesteps the `restrictOnDelete` foreign key (A4). V1 is the orchestration between three services
that already exist, plus one screen.

## Known limitations at V1

1. **Candidates are not proactively told their Suchak has left.** Their contact is blocked the
   moment it happens, so nothing is exposed, and they can already choose a new Suchak in-app.
   Notification ships when Meta approval lands.

2. **Cancelling inside the notice period does not restore the account to `verified`.**
   `SuchakAccountLifecycleService::reactivate()` returns a suspended account to `verified`, but an
   *archived* one lands on `pending` — found during the RC2 device test, where the restored
   account had to be corrected by hand. A Suchak who changes their mind therefore needs
   re-verification. Existing behaviour, not introduced here, and no V1 code depends on it.

3. **A Suchak with pending agreements will not be purged on day 31.** `restrictOnDelete` blocks
   it, `purgeDue()` catches and logs the failure, and it stays stuck until someone reads the log.
   No admin queue in V1.

4. **Candidates mid-process get no special handling.** A Suchak leaving while a meeting or an
   agreement stage is in flight is treated like any other. Revisit before the first real one.

5. **Revoked representations stay revoked on cancellation** (decision B4). The account comes back;
   the customers do not. No UI says so.
