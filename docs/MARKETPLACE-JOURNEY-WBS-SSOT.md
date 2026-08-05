# Matchmaker Marketplace — Journey WBS

**Supersedes** `MARKETPLACE-WBS-SSOT.md` (feature-shaped) and
`MARKETPLACE-ATOMIC-UNITS-SSOT.md` (code-shaped). `MATCHMAKER-MARKETPLACE-BLUEPRINT.md` stays as
the design record only.

**Shape:** Marketplace → Actor → Journey → Feature → Leaf.

**Audit date:** 2026-08-05, against running schema, registered routes, shipped screens,
notification classes, push registry and the test suite. Nothing is taken from documentation.

**Definition of done for this document:** when every leaf reads COMPLETE, there is no user journey
a real person can start and fail to finish.

Each leaf carries eight dimensions. A dimension already built is marked **COMPLETE** and is not
work. Only the gaps are work.

---

## The two findings that shape the tree

**1. The customer has no hands.** Three customer-side endpoints are built, guarded and tested, and
**never called by any app**: confirm a meeting, dispute a meeting, confirm a delivery stage.
`payout_qualified` sits downstream of a confirmation the customer cannot give.

**2. The marketplace is silent.** There is **not one** marketplace notification class and **not
one** marketplace push type. Twenty-six notification classes exist; none is about a challenge, a
proposal, a meeting, a dispute, an obligation or a marriage. `SuchakWorkflowReminder::TYPE_MEETING`
is written by `SuchakWorkflowAutomationService`, so a Suchak sees an in-app reminder — but nothing
ever reaches a phone, and the **customer is never told anything at all**.

Silence is not a missing nicety here. A challenge nobody hears about gets no proposals; a meeting
the customer is not told about cannot be confirmed; the money model assumes both.

---

# ACTOR A — The customer (member)

The actor with a complete backend and almost no product.

## Journey A1 — "Did that meeting happen?"

**Goal:** the family confirms or disputes a meeting their Suchak marked complete.
**Today:** they cannot. Nothing in the member app mentions meetings.

### Feature A1.1 — See my meetings

| Dimension | State |
|---|---|
| Entry point | **MISSING** — no route into meetings anywhere in the member app |
| Backend | **COMPLETE** — `SuchakVisitConfirmationService`, visit list readable |
| UI | **MISSING** |
| Validation | **COMPLETE** — server owns state rules |
| Permissions | **COMPLETE** — `assertCustomerSideUserCanConfirm()` |
| Notifications | **MISSING** — the customer is never told a meeting was scheduled or completed |
| Error handling | **COMPLETE** server-side; client has none |
| Tests | **COMPLETE** server (`SuchakMeetingEngineTest`); client none |
| **Completion** | **~45%** |

**Leaves**

| # | Leaf | Time |
|---|---|---|
| A1.1a | Member API client method + "My meetings" list screen, read-only, reachable from the existing Suchak-requests screen | 55 min |
| A1.1b | mr + en strings for the list | 25 min |

### Feature A1.2 — Confirm a meeting

| Dimension | State |
|---|---|
| Entry point | **MISSING** |
| Backend | **COMPLETE** — `POST /api/v1/suchak-meetings/{visit}/confirm` |
| UI | **MISSING** |
| Validation | **COMPLETE** |
| Permissions | **COMPLETE** |
| Notifications | **MISSING** — the Suchak is not told their meeting was confirmed |
| Error handling | **COMPLETE** server; client none |
| Tests | **COMPLETE** server; client none |
| **Completion** | **~50%** |

**Leaves**

| # | Leaf | Time |
|---|---|---|
| A1.2a | Confirm action on a completed meeting, offered only in the state the server accepts | 45 min |

### Feature A1.3 — Dispute a meeting

Same eight dimensions as A1.2. Backend `POST /suchak-meetings/{visit}/dispute` is **COMPLETE**;
`SuchakDisputeLifecycleTest` covers it. UI and notification **MISSING**. **Completion ~50%.**

**Leaves**

| # | Leaf | Time |
|---|---|---|
| A1.3a | Dispute action with a required reason | 45 min |

### Feature A1.4 — Be told a meeting needs me

| Dimension | State |
|---|---|
| Entry point | **MISSING** |
| Backend | **MISSING** — no notification class, no push type |
| UI | n/a — reuses the existing notification list |
| Validation | n/a |
| Permissions | **COMPLETE** — `AdminActivityNotificationGate` and per-type prefs already exist |
| Notifications | **MISSING** |
| Error handling | **COMPLETE** — `SafeNotifier` |
| Tests | **MISSING** |
| **Completion** | **~15%** |

**Leaves**

| # | Leaf | Time |
|---|---|---|
| A1.4a | `SuchakMeetingAwaitingConfirmationNotification` + push type, fired when a Suchak marks a meeting complete | 50 min |

*Reuse: the notification/push path is one switchboard —
`SendPushForDatabaseNotification` already turns any database notification into a push. This is a
notification class and a registry row, not a delivery mechanism.*

---

## Journey A2 — "What did I agree to pay?"

**Goal:** re-read the frozen agreement without asking the Suchak.
**Today:** only over a tokenised web link sent on WhatsApp.

### Feature A2.1 — Read my frozen agreement in the app

| Dimension | State |
|---|---|
| Entry point | **COMPLETE (web)** `/customer-portal/{token}` · **MISSING (app)** |
| Backend | **PARTIAL** — the freeze is COMPLETE and `agreementSnapshotHash()` covers all four money fields; no member-app read endpoint |
| UI | **MISSING (app)** |
| Validation | **COMPLETE** |
| Permissions | **MISSING** for an app endpoint — must scope to the caller's own agreement |
| Notifications | n/a |
| Error handling | **COMPLETE (web)** |
| Tests | **COMPLETE (web)**; none for an app endpoint |
| **Completion** | **~55%** |

**Leaves**

| # | Leaf | Time |
|---|---|---|
| A2.1a | Member API read endpoint for the caller's own frozen agreement | 45 min |
| A2.1b | Agreement screen in the member app | 55 min |
| A2.1c | mr + en strings | 25 min |

---

## Journey A3 — "Did my Suchak deliver that stage?"

### Feature A3.1 — Confirm a delivery stage

| Dimension | State |
|---|---|
| Entry point | **COMPLETE (web)** `/customer-portal/{token}/stages` · **MISSING (app)** |
| Backend | **COMPLETE** — `POST /suchak-engagements/{collaboration}/stages/confirm`, never called |
| UI | **COMPLETE (web)** · **MISSING (app)** |
| Validation | **COMPLETE** |
| Permissions | **COMPLETE** |
| Notifications | **MISSING** |
| Error handling | **COMPLETE (web)** |
| Tests | **COMPLETE** server |
| **Completion** | **~65%** |

**Leaves**

| # | Leaf | Time |
|---|---|---|
| A3.1a | Stage list + confirm action in the member app | 55 min |
| A3.1b | mr + en strings | 25 min |

> **PO DECISION.** Is the web door enough? A family that already has the app should not be sent a
> link to do what the app could do — but the web door works today and the app door does not.
> Answering "web is enough" closes A3 at 65% and frees 80 minutes.

---

# ACTOR B — The Suchak

Fifteen screens, ~35 routes, twenty feature tests. Nearly complete.

## Journey B1 — "Open my customer to the market"

### Feature B1.1 — Publish, browse, propose, pick

Every dimension **COMPLETE**: entry (`publish_challenge_screen`, `marketplace_screen`,
`marketplace_listing_screen`, `propose_candidate_screen`, `challenge_proposals_screen`,
`candidate_proposal_inbox_screen`), backend (8 challenge routes), UI, validation, permissions
(verified-only browse, masked candidates), error handling, tests
(`SuchakMarketplaceChallengeTest`, `SuchakPublicMarketplaceTest`,
`SuchakCollaborationMarketplaceAdvancedTest`).

**Notifications: MISSING.** A challenge is published and nobody hears; a proposal arrives and
nobody is told.

**Completion ~90%.**

**Leaves**

| # | Leaf | Time |
|---|---|---|
| B1.1a | `SuchakProposalReceivedNotification` + push type, to the challenge publisher | 50 min |
| B1.1b | Chargeable ceiling on a challenge | 40 min |

> **PO DECISION (B1.1b).** Is a ceiling still wanted, amount or percent? The blueprint names it;
> the built table has `declared_share_type / _percent / _amount` and no ceiling. Do not add a
> money column to satisfy a stale document.

---

## Journey B2 — "Record what happened at the meeting"

### Feature B2.1 — Schedule, complete, cancel, dispute

Entry, backend (all four routed), UI (`meetings_screen`), validation, permissions, error handling
and tests (`SuchakMeetingEngineTest`, `SuchakMeetingFeeQuotingTest`, `SuchakOnlineMeetingFeeTest`,
`SuchakFirstMeetingDoorTest`) are **COMPLETE**. Schema is **COMPLETE**: 43 columns,
`meeting_sequence`, `helper_suchak_account_id`, `fee_amount`, `meeting_mode`, unique on
`(pipeline_id, meeting_sequence)` — **the blueprint's B1 and B3 are closed.**

**Gap:** cancellation records no actor, no reason, no attendance. `EVENT_CANCELLED` exists.

**Completion ~90%.**

**Leaves**

| # | Leaf | Time |
|---|---|---|
| B2.1a | Write cancelling actor, reason and attendance into the existing `metadata_json` | 40 min |

*No new columns — the append-only events table is the home the blueprint itself names.*

---

## Journey B3 — "Get paid what I'm owed"

### Feature B3.1 — Obligations and success-fee tranches

Every dimension **COMPLETE**: `cross_suchak_obligations_screen`, `success_fee_ledger_screen`,
raise/settle/ratio routes, tranche index/release/settle, `SuchakCrossSuchakObligationTest`,
`SuchakSuccessFeeTrancheTest`.

**Notifications: MISSING** — a debt is raised and the debtor is not told.

**Completion ~90%.**

**Leaves**

| # | Leaf | Time |
|---|---|---|
| B3.1a | `SuchakObligationRaisedNotification` + push type | 45 min |

---

## Journey B4 — "Judge who I'm dealing with"

### Feature B4.1 — Reputation, economics, customer history

Entry (`reputation_screen`, `market_economics_screen`, `customer_history_screen`), backend,
UI, permissions, tests (`SuchakReputationReadTest`, `SuchakMarketEconomicsTest`) all **COMPLETE**.

**Gap:** Viewed is not distinguishable from a decision — `suchak_match_suggestions` records
`decision` and `decided_at`, nothing about having been seen.

**Completion ~90%.**

**Leaves**

| # | Leaf | Time |
|---|---|---|
| B4.1a | Suggestion → Viewed as a signal distinct from a decision | 45 min |

> **PO DECISION.** Who counts as having viewed — the Suchak opening the card, or the customer
> being shown it? Two different facts; the wrong one silently corrupts every reputation number
> built on it.

---

# ACTOR C — The admin

## Journey C1 — "Settle a dispute"

### Feature C1.1 — Adjudicate, confirm, qualify payout

Sixty-one admin routes; `confirmByAdmin`, `qualifyPayout` and the dispute queue all routed and
covered (`SuchakDisputeSafetyCenterTest`). Every dimension **COMPLETE**.

**Completion ~95%. No leaves. Do not invent work here.**

---

# ACTOR D — Everyone (cross-cutting)

## Journey D1 — "Prove who acted"

### Feature D1.1 — Sign-in is evidence, not a record of an attempt

| Dimension | State |
|---|---|
| Entry point | **COMPLETE** |
| Backend | **PARTIAL** — `MobileOtpService::resolveDeliveryMode()` is environment-guarded; `SuchakRegistrationService::issueOtp()` reads `AdminSetting` defaulting to `dev_show` with **no guard**. Production holds `dev_show` |
| UI | **COMPLETE** |
| Validation | **PARTIAL** — `POST /auth/mobile-otp/send` has **no throttle**; the Suchak side has `throttle:10,1` and its comment names this exact one-sided gap |
| Permissions | **COMPLETE** — the routes reaching the unguarded issuer sit behind `EnsureSuchakLegacyOtpEnabled`, off in production |
| Notifications | n/a |
| Error handling | **COMPLETE** |
| Tests | **PARTIAL** |
| **Completion** | **~60%** |

**Leaves**

| # | Leaf | Time |
|---|---|---|
| D1.1a | Throttle `POST /auth/mobile-otp/send` | 10 min |
| D1.1b | Make `SuchakRegistrationService::issueOtp()` fail closed in production | 20 min |

*Blocked beyond these: turning §8's tier→money gates from recorded to enforced waits on Meta
credentials, which is an owner track and not in this WBS.*

---

# Summary

| Actor | Journeys | Leaves | Time | Blocked |
|---|---|---|---|---|
| **A — Customer** | 3 | 9 | 6 h 25 | A3 on PO |
| **B — Suchak** | 4 | 5 | 3 h 40 | 2 on PO |
| **C — Admin** | 1 | 0 | — | — |
| **D — Everyone** | 1 | 2 | 30 min | — |
| | **9** | **16** | **~10 h 35** | 3 leaves |

Thirteen leaves buildable now. Every leaf under 60 minutes.

## Build order

1. **D1.1a, D1.1b** — 30 minutes, security, nothing depends on them.
2. **A1** entire journey — the customer gets hands. Largest hole; the money model assumes it.
3. **A1.4a** — the notification that makes A1 discoverable rather than merely possible.
4. **B2.1a** — closes the honest-trail gap.
5. **A2** — the customer can read what they agreed to.
6. **B1.1a, B3.1a** — the market stops being silent for Suchaks.
7. **A3, B1.1b, B4.1a** once the three decisions land.

## The three decisions

1. **A3** — is the tokenised web link enough for stage confirmation?
2. **B1.1b** — chargeable ceiling: wanted, and in what unit?
3. **B4.1a** — who counts as having viewed a suggestion?

## What "no missing journey" will mean

When every leaf above reads COMPLETE:

- A customer can see, confirm and dispute their meetings, and is told when one needs them.
- A customer can read the price they agreed to, in the app.
- A Suchak is told when a proposal arrives and when a debt is raised.
- A cancelled meeting records who cancelled it and why.
- No sign-in path can hand out a plaintext code in production.

Nothing else in the marketplace has a journey a real person can start and fail to finish.
