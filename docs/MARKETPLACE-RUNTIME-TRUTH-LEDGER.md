# Marketplace Runtime Truth Ledger

Permanent memory for marketplace discoveries that changed implementation or planning and would
otherwise be re-derived (or worse, re-argued) in a future session. **Not an SSOT** — the
implementation authority is `EXECUTION-SSOT.md`. Nothing here creates work.

**Growth rule — this ledger must stay short.** A new entry is admitted only when BOTH hold:

1. it **changes implementation**, and
2. it **cannot be derived** from an existing entry or a documented runtime truth.

An interesting fact that changes nothing is not a truth worth a row. If the ledger ever needs a
table of contents, it has already failed.

Anything already in `MARKETPLACE-MASTER-EXECUTION-SSOT.md` is referenced, not repeated.

---

## Already documented in EXECUTION-SSOT.md — do not re-derive

Contact-routing cascade (§1.2) · tombstone is an UPDATE so FKs never fire (§1.1) ·
lazy expiry is intentional, no scheduler (§1.6) · meeting timeout = `SuchakClaimSilenceService`
(§1.6) · cancellation actor already known, customer cannot cancel (§1.6) ·
`candidate_deactivated_at` single-writer doctrine (§1.6 + U1) · `shared_display_name` is a
runtime alias, erased at purge (§1.6 + U1) · member meeting endpoints are exactly two, hence the
U9a/U9b split (§1.6) · purge race + cancel-after-purge guard (U1) · `cancelled_rate` excludes
admin cancellations (U7) · candidate is never a debtor (§1.6) · platform never negotiates money
(§2.3) · revoked representations stay revoked on Suchak cancel (§2.4) · suspension/deletion
mid-engagement is an open product decision (§5) · HISTORICAL records are legally untouchable (§3).

---

## Newly documented

----------------------------------------
Finding ID: MRT-01
Title: Canonical notification receiver is `scopeWithValidConsent()` — anything looser leaks
Code Evidence: `SuchakProfileRepresentation:283` (the scope); `scopeOnlyPendingConsentClaims()`
exists precisely to hide un-consented claims from the Suchak who filed them.
Why it matters: A predicate of only `revoked_at IS NULL AND candidate_deactivated_at IS NULL`
notifies a Suchak whose claim the candidate never accepted — revealing that the person has an
account at all. Expired consent leaks the same way.
Implementation impact: Every Suchak-facing "about this candidate" notification resolves its
receivers through `withValidConsent()`. Never hand-roll the predicate.
Current owner: U2 (contract, validated); doctrine for U3, U8, U12.
----------------------------------------
Finding ID: MRT-02
Title: Notify only on an atomic state flip — read-then-check idempotency is a race
Code Evidence: `MemberAccountDeletionService::requestDeletion()` guards with
`if ($user->deletion_requested_at !== null) return;` before its transaction — two concurrent
requests both read null, both proceed, both would notify.
Why it matters: "The service is already idempotent" is true sequentially and false concurrently.
A double-tap produces duplicate notifications (and, pre-U1, could have double-run side effects).
Implementation impact: Fire a notification only when `whereNull(col)->update(...)` (or the
mirror `whereNotNull`) reports exactly 1 affected row. One query, race closed, no new machinery.
Current owner: U2; pattern for every future state-flip notification.
----------------------------------------
Finding ID: MRT-03
Title: The Suchak app has no notifications inbox — the database row is an audit record only
Code Evidence: `Suchak-apk/lib/features/` has no notifications surface;
`suchak_api_repository.dart` fetches no notifications endpoint. Delivery to a Suchak is FCM push
only, via `SendPushForDatabaseNotification`.
Why it matters: Quiet hours (22:00–08:00) SUPPRESS a push — they do not delay it
(`push.quiet_hours_suppressed`, no retry). A night-time event may therefore never reach the
Suchak's eyes even though the row exists. Best-effort is the platform decision; do not promise
more, and do not "fix" this by dispatching to the `notifications` queue — that queue has NO
worker in production (328 jobs once sat there since 2026-06-17).
Implementation impact: Suchak notifications are synchronous database writes + best-effort push,
nothing else. Copy must not assume it was seen.
Current owner: U2, U3, U12.
----------------------------------------
Finding ID: MRT-04
Title: A new push type carries its own defaults — no admin action needed to go live
Code Evidence: `PushTypeRegistry` rows declare `'default_push' => true/false`;
`NotificationPlatformSettingsService::pushTypeEnabled()` falls back to that row default;
`UserNotificationPreferencesService::NEW_CATEGORY_USER_DEFAULT = true` (opt-out).
Why it matters: With `default_push => true` a new type delivers immediately; with `false` it is
silent until an admin flips it. Choosing the wrong default is invisible in tests.
Implementation impact: U2/U3/U8/U12 registry rows ship with `default_push => true` deliberately.
Current owner: U2 (first registry addition).
----------------------------------------
Finding ID: MRT-05
Title: Money mutations are already replay-safe — this risk was carried for days and is CLOSED
Code Evidence: `SuchakCrossSuchakObligationService::settle()` re-loads under `lockForUpdate()`
and throws on `isSettled()` (:263-272); the tranche ledger is derived under the same lock (:501),
its docblock arguing against "the same rupee charged twice".
Why it matters: Three planning documents carried "replay on money mutations" as the
highest-risk unknown. It is not unknown and it is not a risk. Do not re-audit it.
Implementation impact: None — evidence that closes an argument.
Current owner: none (closed).
----------------------------------------
Finding ID: MRT-06
Title: Marriage outcomes use one-live-row uniqueness with `void_seq` supersession
Code Evidence: `suchak_marriage_outcomes` migration — unique indexes read a sentinel that equals
`void_seq` on live rows and the row's own id once voided: "one LIVE row per key" admitting any
number of superseded ones; a void never erases the original claim.
Why it matters: Anyone adding a query or constraint that assumes plain uniqueness, or that a
void deletes, will corrupt attribution — the largest sum in the system hangs off this table.
Implementation impact: Read `void_seq` semantics before touching marriage outcomes.
Current owner: Out of Scope (engine complete).
----------------------------------------
Finding ID: MRT-07
Title: Representation restoration is re-consent, and the path already exists
Code Evidence: Passing test "revoked consent can be requested again without deleting evidence"
(`SuchakConsentFoundationTest`); `SuchakConsentService` re-request flow.
Why it matters: Frozen decision §2.4 says revoked representations stay revoked — the unstated
half is that the recovery path is NOT a restore flag but a fresh consent request through the
existing engine. Nobody should build an "undo revoke".
Implementation impact: None now; doctrine for any future reactivation feature.
Current owner: Out of Scope.
----------------------------------------
Finding ID: MRT-08
Title: Proposal withdrawal does not exist, and the collaboration `cancelled` status is never written
Code Evidence: No cancel/withdraw route for a proposer in `routes/api/suchak.php`;
`SuchakCollaborationRequest::STATUS_CANCELLED` written nowhere (the only `:2115` write is on
`SuchakCommissionAgreement`). Only the publisher can `reject`.
Why it matters: A proposer whose candidate became unavailable cannot retract; a stale proposal
can be accepted and produce an engagement for an unavailable candidate. Deliberately parked —
needs a one-sentence product decision (withdraw before view, or before accept?).
Implementation impact: None until decided. Do not "discover" this again.
Current owner: Out of Scope (product decision pending).
----------------------------------------
Finding ID: MRT-09
Title: `FEATURE_COLLABORATION` gates creation only — nothing governs a running engagement
Code Evidence: `SuchakQualityControlService::assertFeatureAvailable()` is called at
`createRequest()` time; `suspend()` and account deletion touch no collaboration, meeting,
obligation or tranche.
Why it matters: This is the evidence behind the open decision "what happens to work in flight
when the arrangement changes". The gate people assume exists, does not.
Implementation impact: None until the §5 decision lands.
Current owner: Out of Scope.
----------------------------------------
Finding ID: MRT-10
Title: Marketplace exposure is outside the candidate's recorded consent text
Code Evidence: `SuchakConsentService::CONSENT_TEXT_V1` — "show/share biodata for suitable
matches… contact will happen through Suchak." Nothing about listing to competing Suchaks for a
declared revenue share (`suchak_marketplace_challenges`).
Why it matters: The superseded scenario audit flagged it; the superseding EXECUTION-SSOT's
out-of-scope list does not name it. Without this entry the finding lived only in chat. It is a
legal-consistency gap, not a runtime defect — candidates enter the marketplace only through the
challenge flow their Suchak controls, and masking still applies.
Implementation impact: None now. Before marketplace onboarding of new candidates scales, the
consent text needs a version that names marketplace exposure.
Current owner: Out of Scope (product/legal decision).
----------------------------------------
Finding ID: MRT-11
Title: Notification class naming is `<Subject><EventPastTense>Notification`
Code Evidence: All 26 existing classes — `ContactRequestAccepted…`, `InterestRejected…`,
`ImageApproved…`. Mixed-tense pairs (Leaving/Stayed) were rejected in contract validation.
Why it matters: U2's classes were renamed to `SuchakCustomerDeletionRequestedNotification` /
`SuchakCustomerDeletionCancelledNotification`; future marketplace notifications (U3, U8, U12)
should be named the same way without re-litigating.
Implementation impact: Naming only.
Current owner: U2.
----------------------------------------

## Footer

Already documented: 15 (referenced above, not repeated)
Newly documented: 11 (MRT-01 … MRT-11)
Duplicates avoided: 15
Planning changes: 0
New implementation units: 0
