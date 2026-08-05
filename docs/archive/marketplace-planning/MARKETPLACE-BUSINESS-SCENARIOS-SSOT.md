# Matchmaker Marketplace — Business Scenario Audit

**Final analysis before implementation.** Sits above the Planning SSOT. Not features, not APIs,
not matrices — the stories that actually happen between people.

**Audit date:** 2026-08-05, against the running system.

**Classification:** COMPLETE · READY (<1 h) · PRODUCT DECISION · RUNTIME AUDIT

Overlapping scenarios are merged and say so. A scenario that is finished is marked COMPLETE and
given no work.

---

## What this audit changed

The matrices said the marketplace is ~90% built with a silent notification layer. Reading it as
business stories found **four scenarios that are not merely unbuilt — they are unmodelled**. The
system has no concept of them at all:

1. **A meeting cannot be rescheduled.** No route, no service, no state. In real matchmaking,
   meetings move constantly. Today the only path is cancel-and-recreate, which loses the thread.
2. **A Suchak can be suspended mid-engagement and nothing happens to the engagement.**
   `suspend()` touches no collaboration, no meeting, no obligation.
3. **A Suchak can delete their account mid-engagement and nothing happens to the engagement.**
   The deletion service I shipped today revokes representations only. A live engagement with a
   scheduled meeting survives its Suchak leaving.
4. **A customer cannot change Suchak mid-engagement.** There is no story for it.

The single gate that exists across all four is `SuchakFeatureSuspension::FEATURE_COLLABORATION`,
which blocks *creating* a new collaboration. Nothing governs one already running.

---

# GROUP A — Opening a customer to the market

## A1. Suchak publishes a challenge

**Objective** Reach candidates a Suchak's own book does not contain, for a declared share.
**Trigger** Suchak taps publish. **Actors** Suchak.
**Preconditions** Verified + public-active account; a represented candidate with a live agreement.

**Flow** `POST /marketplace/challenges` → `SuchakMarketplaceChallengeService` → row `open` +
`published_to_marketplace` on the ladder → `marketplace_challenge_published` logged.

| Dimension | State |
|---|---|
| APIs / services / state / permissions / logs | **COMPLETE** |
| Payments | none at publish |
| **Notifications** | **MISSING** — nobody hears |
| **Background** | none needed |
| **Failure** | validation refuses; no partial write |
| **Edge — unthrottled** | `POST /marketplace/challenges` has no rate limit. One account can flood the board |

**Classification: READY** — notification + throttle. ~55 min.

---

## A2. Challenge expires

**Objective** A stale challenge stops wasting other Suchaks' time.
**Trigger** `expires_at` passes. **Actors** System.

`suchak_marketplace_challenges.expires_at` exists, `STATUS_EXPIRED` exists,
`marketplace_challenge_expired` exists as a log action. **But
`SuchakScheduledJobsConsolidationService` expires payments, consents and QR tokens — not
challenges.**

So either something else expires them, or **a challenge whose date has passed stays `open`
forever**, keeps appearing in browse, and can still receive proposals.

**Classification: RUNTIME AUDIT** — highest-value read in this document. If nothing expires them,
the fix is one method in a service that already runs daily and is already lock-guarded. Do not
build it before confirming.

---

## A3. Challenge withdrawn — **merged into A1**

Same service, same guards, same missing notification. `withdrawn_by_user_id`, `withdrawn_at`,
`withdrawn_reason` all recorded. **COMPLETE except the notification already counted in A1.**

---

# GROUP B — Answering a challenge

## B1. Suchak proposes a candidate

**Objective** Offer a match and claim a share.
**Trigger** Proposal submitted. **Actors** proposing Suchak → publishing Suchak.

**Flow** masked browse → `POST /marketplace/challenges/{c}/proposals` →
`SuchakCollaborationService::createRequest()` with the responder as requester → `pending` →
`marketplace_proposal_received` logged.

| Dimension | State |
|---|---|
| APIs / services / state / permissions / masking / logs / tests | **COMPLETE** |
| **Notifications** | **MISSING** — the publisher is not told a proposal arrived. **The single most damaging silence in the marketplace**: a proposal nobody sees is a challenge that fails for no reason |
| **Edge — unthrottled** | proposal spam is possible |

**Classification: READY** — notification + throttle. ~55 min.

## B2. Proposal accepted / rejected — **merged**

`POST /collaborations/{c}/accept` and `/reject`, guarded, logged, tested. Accept creates the
engagement and freezes the share from the challenge, not from negotiation.

**COMPLETE except notification** (the proposing Suchak is not told either way). **READY**, ~45 min.

---

# GROUP C — The meeting

## C1. Meeting created

`POST /meetings` → `SuchakVisitConfirmationService` → `scheduled` → `visit_scheduled` logged →
`SuchakWorkflowReminder::TYPE_MEETING` written, so the Suchak sees an in-app reminder.

Schema is **COMPLETE**: `meeting_sequence`, `helper_suchak_account_id`, `fee_amount`,
`meeting_mode`, unique `(pipeline_id, meeting_sequence)`.

**Gap:** the **customer is never told a meeting was booked for them.**

**Classification: READY** — notification. ~45 min.

## C2. Meeting rescheduled

**Objective** Move a meeting without losing the thread.

**There is no reschedule anywhere.** No route, no service method, no state, no event type. Grepped
the whole app: nothing.

The only path is cancel + create, which produces a cancelled meeting and a new `meeting_sequence`
— so a rescheduled meeting reads in the trail as a Suchak who cancelled on the family, and any
future reputation number built on cancellations will punish them for it.

**Classification: PRODUCT DECISION** — is reschedule a first-class action, or is cancel-and-recreate
acceptable if cancellations record a reason (B2.1a)? Cheapest honest answer is probably the
reason, not a new verb. **Do not build a reschedule engine before deciding.**

## C3. Meeting cancelled by the Suchak / by the customer — **merged**

`POST /meetings/{visit}/cancel`, `EVENT_CANCELLED` exists.

- Suchak cancels: **COMPLETE** except who/why/attendance are not recorded.
- **Customer cancels: not possible.** No customer-side cancel route exists.

**Classification** — the recording half is **READY** (~40 min, into the existing `metadata_json`,
no new columns). Whether a customer may cancel at all is **PRODUCT DECISION**.

## C4. Customer confirms the meeting

**The keystone scenario.** `payout_qualified` sits downstream of it.

`POST /api/v1/suchak-meetings/{visit}/confirm` exists, is guarded by
`assertCustomerSideUserCanConfirm()`, and is **tested**. It is called by **no app**. The member app
has one Suchak screen and it is about requests, not meetings.

So: the customer cannot see the meeting, is not told it happened, and cannot confirm it. The money
model's central act is unreachable.

**Classification: READY** — 5 leaves, ~3 h 25, no decisions. The largest single hole in the
product.

## C5. Customer disputes the meeting

Same shape as C4. `POST /suchak-meetings/{visit}/dispute` exists, guarded, tested, uncalled.
Opens a `SuchakDispute` and can attach a `SuchakPayoutHold`.

**Classification: READY** — merged into C4's leaves.

---

# GROUP D — When it goes wrong

## D1. Admin resolves a dispute

`open` → `under_review` → `resolved` / `rejected` / `closed`. Admin routes exist,
`SuchakDisputeSafetyCenterTest` and `SuchakDisputeLifecycleTest` cover it, payout hold releases on
resolution, `visit_dispute_settled` logged.

**Notifications MISSING** — neither party is told their dispute moved.

**Classification: COMPLETE** except the notification. **READY**, ~45 min.

## D2. Suchak suspended mid-engagement

**Objective** Stop a misbehaving Suchak without stranding the family.

**Runtime truth:** `SuchakAccountLifecycleService::suspend()` touches the account only. It does
not touch collaborations, meetings, obligations or tranches. The one related gate is
`FEATURE_COLLABORATION`, which blocks **creating** a new collaboration — nothing about one already
running.

So a suspended Suchak keeps a scheduled meeting, a live engagement and an unsettled obligation.
Whether that is right is a business question: freezing an engagement mid-way may hurt the family
more than the Suchak.

**Classification: PRODUCT DECISION** — what should suspension do to work in flight? Nothing is
broken; nothing was decided.

## D3. Suchak deletes their account mid-engagement

**Runtime truth:** shipped today. `SuchakAccountDeletionService` archives the account and revokes
representations — which correctly stops contact routing. **It does not touch engagements,
meetings, obligations or tranches.**

A live engagement with a scheduled meeting and an unsettled cross-Suchak debt survives its Suchak
leaving. The `restrictOnDelete` foreign key on `suchak_customer_agreements` blocks the day-31
purge, so the account gets stuck rather than silently erased — a safety net, and the reason this
is not urgent.

**Classification: PRODUCT DECISION** — same question as D2, and they should be answered together.

## D4. Customer changes Suchak mid-engagement

There is no story. `SuchakRequestPipelineService::createRequest()` lets a candidate invite a
different Suchak, but nothing reconciles that with a running engagement, an unsettled obligation
or a scheduled meeting.

**Classification: PRODUCT DECISION** — merged with D2 and D3. All three are the same unanswered
question: *what happens to work in flight when the Suchak changes?*

---

# GROUP E — Money

## E1. Cross-Suchak obligation raised and settled — **merged**

`POST /collaborations/{c}/cross-suchak-obligations` turns a declared share into a debt;
`/settle` clears it; both directions readable; realized-vs-declared ratio feeds reputation;
`SuchakCrossSuchakObligationTest` covers it.

The platform **records** the debt and does not move the money — deliberate, and matches the
published Terms.

| Dimension | State |
|---|---|
| APIs / services / permissions / logs / tests / UI | **COMPLETE** |
| **Notifications** | **MISSING** — the debtor is not told a debt was raised |
| **Edge — replay** | **no idempotency key found on any money mutation.** A repeated `settle` may double-apply |

**Classification: READY** for the notification (~45 min). **RUNTIME AUDIT** for replay — the
highest-risk unknown in the system, because everything else on the security list is a rate limit
and this one is money.

## E2. Success-fee settlement

`suchak_success_fee_tranches` with `trigger_stage_key`, `share_percent`, `is_final_tranche`,
`released_at`, `settled_at`. Release and settle routes exist; `SuchakSuccessFeeTrancheTest` covers
them.

State is expressed as nullable timestamps rather than an enum. Legitimate — but whether an illegal
ordering (settle before release) is refused is unverified.

**Classification: COMPLETE** in behaviour · **RUNTIME AUDIT** on ordering + replay.

## E3. Payment received / failed

PayU, `payments:repair-missing` every 5 minutes, `payments:reconcile` daily.

**Live fact: production holds `PAYU_ENV=test`.** Every money path currently runs against the
sandbox.

**Classification: COMPLETE** in code · **owner switch** outstanding.

---

# GROUP F — The outcome

## F1. Marriage recorded

`POST /collaborations/{c}/marriage` → `suchak_marriage_outcomes` with `married_on`, both profiles,
the stage event, attribution through `collaboration_request_id`. Feeds reputation and payouts.
`SuchakMarriageOutcomeTest` and `SuchakMarriageAgePolicyTest` cover it.

**Classification: COMPLETE** except notification (nobody is congratulated or informed).
**READY**, ~45 min — and the lowest priority of the notification set.

## F2. Marriage voided

`void_seq`, `voided_at`, `voided_by_user_id`, `void_reason` — append-only by design, so a void
never erases the original claim. `marriage_outcome_voided` logged.

**Classification: COMPLETE.** No work. Nothing missing.

---

# Summary

| # | Scenario | Class |
|---|---|---|
| A1 | Publish challenge (+A3 withdraw) | READY |
| A2 | **Challenge expires** | **RUNTIME AUDIT** |
| B1 | Propose a candidate | READY |
| B2 | Proposal accepted / rejected | READY |
| C1 | Meeting created | READY |
| C2 | **Meeting rescheduled** | **PRODUCT DECISION** |
| C3 | Meeting cancelled | READY (record) + DECISION (customer cancel) |
| C4 | **Customer confirms** | **READY — largest hole** |
| C5 | Customer disputes | READY (merged into C4) |
| D1 | Admin resolves dispute | READY |
| D2 | **Suchak suspended mid-engagement** | **PRODUCT DECISION** |
| D3 | **Suchak deletes mid-engagement** | **PRODUCT DECISION** |
| D4 | **Customer changes Suchak** | **PRODUCT DECISION** |
| E1 | Cross-Suchak obligation | READY + **RUNTIME AUDIT (replay)** |
| E2 | Success-fee settlement | COMPLETE + RUNTIME AUDIT |
| E3 | Payment received / failed | COMPLETE (sandbox) |
| F1 | Marriage recorded | READY |
| F2 | Marriage voided | **COMPLETE — no work** |

**18 scenarios. 9 READY, 5 PRODUCT DECISION, 3 RUNTIME AUDIT, 1 COMPLETE with nothing to do.**

## The four decisions, which are really one

D2, D3, D4 and C2 all ask the same thing: **what happens to work already in flight when the
arrangement changes?** A suspended Suchak, a departing Suchak, a customer switching, a meeting
moving. Today the answer in every case is *nothing happens* — the engagement simply continues.

That may be correct. Freezing a family's matchmaking because their Suchak was suspended could hurt
the family more than the Suchak. But it has never been decided, and four scenarios inherit the
non-decision.

**Answer it once and four scenarios close.**

## The three reads, in order of risk

1. **Replay on money mutations** (E1) — a double `settle` is money.
2. **Challenge expiry** (A2) — if nothing expires them, the board fills with dead listings.
3. **Tranche ordering** (E2) — timestamps without an enum.

None is implementation. Each is a read.

## The one scenario worth building first

**C4 — the customer confirms.** Everything exists except the hands. It is the act the money model
is built on, and today the person who paid cannot perform it.
