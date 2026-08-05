# Suchak Account Deletion — SSOT

**Status:** blueprint accepted, implementation blocked pending Product Owner decisions.
**Basis:** Runtime Truth Audit (2026-08-05). Every claim below marked VERIFIED was read out of
the code, not inferred.

**Hard constraints agreed before this document was written:**

- No new tables.
- No new columns.
- No new lifecycle states, enums or ownership model.
- No duplicated state — derive it.
- No new service where an existing one can be extended or composed.
- Existing routing and contact-visibility behaviour must not change.

Each section is tagged:

| Tag | Meaning |
|---|---|
| **VERIFIED** | Confirmed against the code. Treat as fact. |
| **PO DECISION** | Blocked. Needs the Product Owner's explicit answer. |
| **IMPLEMENTATION** | Future work, unblocked only once the decisions above it are approved. |

---

# A. VERIFIED FACTS

## A1. Who manages a candidate is already derived — VERIFIED

`SuchakProfileRepresentation::scopeWithValidConsent()` (`app/Models/SuchakProfileRepresentation.php:283`):

```
representation_status = 'active'
AND consent_status    = 'accepted'
AND revoked_at IS NULL
AND candidate_deactivated_at IS NULL
AND (consent_valid_until IS NULL OR consent_valid_until >= now())
```

`scopePubliclyRoutable()` (`:350`) adds: the Suchak account must be
`VERIFICATION_VERIFIED` **and** `PUBLIC_ACTIVE`.

**Consequence:** archiving a Suchak account makes every one of its representations
non-routable automatically. No per-representation action is needed to stop contact
routing. This cascade is the single most important fact in this document.

`scopeWithCandidateGivenConsent()` (`:310`) additionally requires
`consent_is_suchak_declared = false` — a Suchak cannot declare consent on a
candidate's behalf and use it to block another Suchak.

## A2. The profile is platform-owned; the Suchak owns only the link — VERIFIED

- `matrimony_profiles` has **no** `suchak_account_id`. Ownership is not expressible in the schema.
- The relationship lives only in `suchak_profile_representations`,
  `unique(suchak_account_id, matrimony_profile_id)`.
- `SuchakProfileRepresentation::delete()` (`:358`) **throws**:
  `'Suchak profile representations cannot be deleted.'`

Revocation is the only exit. History is preserved by design, not by convention.

## A3. Candidate contact and Suchak contact never mix — VERIFIED

- Candidate: `profile_contacts` where `is_primary = true`, read by
  `MatrimonyProfile::getPrimaryContactNumberAttribute()` (`:363`), falling back to `user.mobile`.
- Suchak: `suchak_accounts` → `contactNumbers`, a separate relation.
- `ManualProfileController:71` makes `candidate_mobile` **required** when a Suchak creates a
  candidate, and that number is what lands in `profile_contacts`.
- `ContactAccessService::consumeRoutedContactReveal()` (`:458`) is documented as
  *"intentionally does not read the candidate's profile contact payload."*

**Consequence:** there is no risk of a departed Suchak's number sitting on a candidate profile.
Validating this needs a query, not a schema change.

## A4. Representation history is already recorded — VERIFIED

`suchak_activity_logs` carries `matrimony_profile_id`, `suchak_account_id`, `actor_user_id`,
`action_type`, `metadata_json`, `occurred_at`.

Existing action types sufficient to rebuild a full timeline:

```
representation_created · representation_status_changed
representation_candidate_deactivated
consent_requested · consent_otp_sent · consent_verified
consent_rejected · consent_expired · consent_renewed
```

Plus row-level timestamps on the representation: `first_uploaded_at`, `consent_verified_at`,
`revoked_at`, `candidate_deactivated_at`.

A "Representation History" view is a **read**, not a new table.

## A5. Contact is already blocked after archive — VERIFIED

`ContactActionApiController:55`:

```
if (SuchakContactRouting::isRouted($profile)) {
    return $this->revealSuchakContact(...);   // Suchak's business number
}
// otherwise → profile_visibility_settings → ContactRevealPolicyService
```

`isRouted()` (`SuchakContactRouting:36`) is built on `publiclyRoutable()`. Once the Suchak
account is archived it returns false, and the code falls through to the candidate's own
visibility settings, where `ContactRevealPolicyService` enforces visibility case, premium
viewer, accepted interest, quota and plan.

Safe default for Suchak-created profiles is `SHOW_CONTACT_NO_ONE`
(`ProfileVisibilitySettingsDefaultsService:61`).

**Consequence:** "profile visible, contact blocked" is an emergent property of `archive()`.
It must not be built.

## A6. Every required lifecycle state exists — VERIFIED

| Layer | States |
|---|---|
| Representation | `pending`, `consent_pending`, `active`, `revoked`, `expired`, `rejected`, `suspended`, `candidate_deactivated` |
| Consent | `not_requested`, `requested`, `accepted`, `rejected`, `expired`, `revoked` |
| Suchak account | `pending`, `verified`, `rejected`, `suspended`, `archived` × `hidden`, `active`, `inactive` |
| Profile | `draft` … `active`, `suspended`, `archived`, `archived_due_to_marriage` |

"Awaiting representation" = all representations revoked and none active — **derived**.
"Managed by Suchak" = a `publiclyRoutable()` row exists — **derived**.

## A7. `archive()` does not require an admin — VERIFIED

`SuchakAccountLifecycleService::archive(SuchakAccount $account, User $admin, string $reason, …)`
(`:110`). The parameter is named `$admin` but is typed plain `User` and is used only for audit
attribution (`transition()` → `writeAdminAuditLog`, `actor_user_id`). No `isAdmin()` assertion
exists anywhere in the service.

The Suchak's own `User` can therefore be passed as the actor. The only real guard is the
precondition: `verification_status` must be `verified` or `suspended` (`:114`).

## A8. The candidate-chooses-a-Suchak flow already exists — VERIFIED

`MemberSuchakRequestApiController` → `SuchakRequestPipelineService::createRequest()`
(`app/Modules/Suchak/Services/SuchakRequestPipelineService.php:50`), routed at
`routes/api/member.php` as *"CREATE SUCHAK REQUEST"* and *"CANDIDATE ANSWERS"*.

Phase 5's transfer path is an existing flow, not a new one.

## A9. Non-WhatsApp consent channels already exist — VERIFIED

`SuchakConsent::CHANNELS` (`app/Models/SuchakConsent.php:72-85`):

```
whatsapp_deep_link · sms_otp · voice_otp
offline_proof · admin_assisted
suchak_relayed_link · offline_signed_proof
```

`admin_assisted` and `offline_signed_proof` are already valid, already validated by
`SuchakConsentService::assertAllowedValue()`. A WhatsApp outage or a pending Meta approval does
not require new architecture — it requires choosing a different existing channel.

## A10. Money is not the platform's — VERIFIED

`suchak_customer_agreements.suchak_account_id` with `restrictOnDelete`
(`2026_06_10_103000_create_suchak_customer_agreement_tables.php:59`). The agreement binds the
Suchak and the family; the platform is not a party. Published Terms clause 7 says the same.

The FK means the database itself refuses to delete a Suchak account while agreements exist.
That is a safety net, not a bug.

---

# B. PRODUCT OWNER DECISIONS

**Implementation is blocked until every decision below is explicitly approved.**

## B1. Does the account archive immediately on request, or on confirmation? — PO DECISION

- **Option 1 — archive immediately.** Contact stops the moment the Suchak confirms.
  Matches the member deletion flow already shipped (profile hides at once, erase at day 31).
- **Option 2 — stay active for 30 days.** The Suchak keeps working during notice.

Evidence bearing on it: archiving is what makes representations non-routable (A1, A5). Under
option 2, nothing protective happens for 30 days, and candidates are notified about a departure
that has not yet taken effect.

**Recommendation:** Option 1 — consistent with member deletion, and the protective effect is the
point of the notice period.

## B2. Are the four candidate options final? — PO DECISION

Self Managed · Choose Another Suchak · Archive Profile · Delete Account.

All four map onto existing services (see C3). No new mode is required for any of them.

**Awaiting confirmation that this list is closed.**

## B3. Is "no response" the final default? — PO DECISION

Proposed default: profile stays visible · contact stays blocked · no representation exists.

Per A5 this is what the runtime already does with zero code. The alternative — treating silence
as consent to become self-managed — is weak under DPDP.

**Awaiting confirmation.**

## B4. On cancellation during notice, do revoked representations stay revoked? — PO DECISION

- **Option 1 — stay revoked.** Candidates were already told their Suchak was leaving and some
  have already decided. Silently restoring would reverse a decision they made.
- **Option 2 — automatic restoration.** Reverses candidate decisions without asking them.

**Recommendation:** Option 1. The Suchak's account returns; their customers do not return
automatically. Re-consent is required, through the existing consent flow.

## B5. Do pending agreements block deletion, or route to admin review? — PO DECISION

- **Option 1 — block.** `restrictOnDelete` already enforces this at the database level.
- **Option 2 — admin review queue.** Purge is attempted, failure is caught, the case is surfaced.

Note these are not exclusive: the FK blocks regardless, so option 2 is really *"how the block is
surfaced"*.

**Recommendation:** treat the FK as the block and add an admin queue for visibility, so a stuck
deletion is never silent.

## B6. Confirm the platform never participates in pricing — PO DECISION

Proposed: the platform never negotiates, never arbitrates, never transfers money. A receiving
Suchak must state their own terms directly to the candidate **before** any representation is
granted. The frozen figures in `suchak_customer_agreements` may be displayed as information only.

**Awaiting explicit confirmation**, because this is a commercial policy, not a technical one.

## B7. OTP fallback while Meta approval is pending — PO DECISION

Candidate activation currently needs an OTP, and production OTP delivery is WhatsApp-only
(`config/otp.php`: production always uses `whatsapp`). Meta approval is outstanding and the
contact number goes live in ~3 days.

Existing channels that need no new architecture (A9):

| Fallback | Cost | Note |
|---|---|---|
| `admin_assisted` | manual effort | Already a valid channel; admin verifies identity and records consent |
| `offline_signed_proof` | manual effort | Already valid; for families who cannot use a phone flow |
| `sms_otp` | needs a gateway | **No SMS provider is configured today** (verified) |

**Recommendation:** `admin_assisted` as the interim channel, switching to
`whatsapp_deep_link` once Meta approves. Volume will be low at launch, so manual handling is
tolerable and no code changes when the switch happens.

**Awaiting confirmation** that manual admin handling is acceptable at launch volume.

---

# C. IMPLEMENTATION PLAN

**Do not start until every item in section B is approved.**

## C1. Phase 1 — Suchak initiates deletion — IMPLEMENTATION

- Precondition: `verification_status` ∈ {`verified`, `suspended`} (A7).
- Idempotent: a second request must not restart the clock — same rule as
  `MemberAccountDeletionService::requestDeletion()`.
- Notice period read from `MemberAccountDeletionService::GRACE_DAYS`. **No second constant.**
- Impact summary shown before confirming, all derived: routable candidates,
  agreements by `terms_status`, pending meetings.
- No read-only flag. `archive()` produces read-only as a side effect (A1).

## C2. Phase 2 — Orchestrator — IMPLEMENTATION

One new service, `SuchakAccountDeletionService`. It **calls; it does not decide**.

1. Validate (C1).
2. `SuchakAccountLifecycleService::archive($account, $suchakUser, $reason)` — actor is the
   Suchak's own `User` (A7). **Contact routing stops here** (A5).
3. Collect `publiclyRoutable()` candidates → set `revoked_at` + `representation_status = revoked`.
4. Notify candidates (C3), **paid customers first** (`terms_status`), free candidates after.
5. Set `User.deletion_requested_at` — existing column.
6. Log via `SuchakActivityLogger`.

## C3. Phases 3-5 — Notification, decision, transfer — IMPLEMENTATION

**Notification:** `SuchakConsentService` secure link + OTP + `consent_text_snapshot`
+ `consent_text_version`, unchanged. New copy and a landing page only. Channel per B7.

**Decision mapping — every one an existing service:**

| Decision | Service |
|---|---|
| Self Managed | none; representation already revoked, `isRouted()` false, candidate's own gates apply. Log only. |
| Choose another Suchak | `SuchakRequestPipelineService::createRequest()` (A8) |
| Archive profile | `ProfileLifecycleService::transitionTo($profile, 'archived', $user)` |
| Delete account | `MemberAccountDeletionService::requestDeletion()` |

**Transfer, privacy-safe order:**

```
candidate picks from a list   (candidate chooses; the platform never assigns)
   → receiving Suchak sees SuchakCandidateMaskingService::maskedSummary() only
   → Suchak expresses interest
   → receiving Suchak discloses their own terms (B6)
   → SuchakConsentService → OTP → candidate's explicit consent
   → representation active + consent_accepted → full identity
```

`scopeWithCandidateGivenConsent()` already prevents a Suchak-declared consent from counting (A1).

## C4. Phase 6 — Contact behaviour — IMPLEMENTATION

**Nothing to build.** Profile stays visible; `isRouted()` false; reveal falls through to
`profile_visibility_settings`; default `SHOW_CONTACT_NO_ONE`; premium, accepted-interest, quota
and plan gates unchanged (A5).

## C5. Phase 7 — Final deletion — IMPLEMENTATION

Extend the existing `account:purge-due-deletions` command and its daily `03:40` schedule.
**No second scheduler.**

Per due Suchak: confirm representations are revoked →
`UserAccountDatabasePurger::purgeUserAccount($suchakUser, keepCounterpartConversations: true)`
→ log. A purge blocked by `restrictOnDelete` (B5) is caught and surfaced, never silent.

## C6. Phase 8 — Activity log — IMPLEMENTATION

**Exactly one new `action_type`:** `representation_candidate_decision`, with the chosen option in
`metadata_json`. Everything else reuses existing action types (A4). No new logging system.

## C7. Phase 9 — UI — IMPLEMENTATION

| Surface | Screen | Reuse | New |
|---|---|---|---|
| Laravel | Consent landing page | `SuchakConsentService` link machinery | four-option page |
| Laravel | `/delete-account` | existing legal page | Suchak section |
| Suchak app | Settings → Delete account | member-app flow shipped 2026-08-05 | impact summary |
| Member app | Who manages my profile | `showForProfile` (exists) | decision buttons |
| Member app | Choose a Suchak | `MemberSuchakRequestApiController` | list screen |
| Admin | Deletion queue | existing admin layout | queue view |

## C8. Phase 10 — Edge cases — IMPLEMENTATION

| Case | Handling |
|---|---|
| Pending meetings | Never batch. Route to admin queue — a marriage in progress must not be archived. |
| Pending contact requests | Existing `ContactRequestService` expiry. No new rule. |
| Active Suchak subscription | The Suchak's own; `restrictOnDelete` blocks; admin resolves. |
| Showcase profiles | Never held by a Suchak. Untouched. |
| Already archived account | `archive()` precondition fails; go straight to the 30-day clock. |
| Repeated delete request | Idempotent; the clock does not restart. |
| Cancellation in notice | `cancelDeletion()` + `reactivate()`, subject to B4. |

## C9. Phase 11 — End-to-end sequence — IMPLEMENTATION

```
Suchak → Delete account
   ↓ validate + impact summary
archive()  ─────────────►  publiclyRoutable = false
   ↓                        contact blocked at this instant
representations → revoked
   ↓
deletion_requested_at = now()        [30-day clock]
   ↓
notify candidates    (paid first → free after)
   SuchakConsentService: secure link + OTP
   ↓
candidate decision
   ├─ self managed        → log only
   ├─ another Suchak      → masked → interest → terms → consent → full
   ├─ archive profile     → ProfileLifecycleService
   └─ delete account      → MemberAccountDeletionService
   ↓
no response → profile visible, contact blocked   (automatic)
   ↓
day 31: account:purge-due-deletions
   ↓
UserAccountDatabasePurger (tombstone mode)
   ↓
SuchakActivityLogger
```

## C10. Total footprint — IMPLEMENTATION

| | |
|---|---|
| New tables | 0 |
| New columns | 0 |
| New lifecycle states | 0 |
| New services | 1 (`SuchakAccountDeletionService`, orchestration only) |
| New `action_type` | 1 |
| New pages/screens | landing page + 5 UI surfaces |
| Extended | `account:purge-due-deletions` |

**External blocker:** C3 depends on the consent channel chosen in B7. Every other phase is
independent of Meta.
