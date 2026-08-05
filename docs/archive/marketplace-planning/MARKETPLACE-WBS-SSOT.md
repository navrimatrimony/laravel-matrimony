# Matchmaker Marketplace — Work Breakdown Structure

**Supersedes** `MARKETPLACE-ATOMIC-UNITS-SSOT.md` (which decomposed by code gaps, not by product)
and replaces `MATCHMAKER-MARKETPLACE-BLUEPRINT.md` as the implementation authority. The blueprint
stays as the design record.

**Audit date:** 2026-08-05, against running schema, registered routes, shipped screens and the
test suite. Nothing below is taken from documentation.

**Correction carried forward.** The previous decomposition claimed `confirmByUser` had no route.
It does — `POST /api/v1/suchak-meetings/{visit}/confirm` on the **member** API, because the actor
is the customer, not the Suchak. That unit is withdrawn. The real gap is one layer up and is what
this document is organised around.

---

## The finding that shapes everything

The marketplace has three actors. Two of them have a complete product. One has almost nothing.

| Actor | API | UI | Tests |
|---|---|---|---|
| **Suchak** | ~35 routes | 15 screens | 20 feature tests |
| **Admin** | 61 routes | Blade surfaces | covered |
| **Customer / member** | 7 routes | **1 screen** | covered server-side |

Every customer-side endpoint the marketplace depends on is built, guarded and tested. Three of
the seven are **never called by any app**:

```
/suchak-meetings/{visit}/confirm      never called
/suchak-meetings/{visit}/dispute      never called
/suchak-engagements/{collaboration}/stages/confirm   never called
```

The customer can confirm a meeting, dispute one, and confirm a delivery stage — on paper. In the
member app there is no way to reach any of it. The web customer-portal (`/customer-portal/{token}`)
covers agreement and stages over a tokenised link, but not meetings.

**So the marketplace is not half-built. It is fully built for the people who sell, and unbuilt for
the person who pays.** Every leaf in Feature 1 below is a customer surface over an endpoint that
already works.

---

# Marketplace Feature Tree

Completion % is: does a real user reach this and finish it? Not "does code exist".

---

## Feature 1 — The customer confirms what happened

**User goal:** a family that paid a Suchak can say "yes, that meeting happened" or "no, it did
not", from the app they already have.

**Why it is first:** `payout_qualified` is downstream of customer confirmation. Until this
exists, the money leverage the blueprint is built on cannot be exercised by the person it
protects.

| | |
|---|---|
| Entry point | Member app — **missing** |
| API | `POST /suchak-meetings/{visit}/confirm`, `/dispute` — **exist, guarded, tested** |
| UI | **none** |
| Admin | `confirmByAdmin` + dispute queue — exist |
| Services | `SuchakVisitConfirmationService::confirmByUser()`, `disputeVisit()` |
| DB | `suchak_visit_confirmations`, `suchak_visit_confirmation_events` |
| Tests | `SuchakMeetingEngineTest`, `SuchakDisputeLifecycleTest` — server side only |
| **Completion** | **API 100% · UI 0% · overall ~40%** |

### Leaves

| # | Leaf | Time | Independently deployable |
|---|---|---|---|
| 1.1 | Member API client: `fetchMyMeetings()` over the existing list endpoint | 30 min | yes — dead code until 1.2 |
| 1.2 | "My meetings" list screen, read-only, reachable from the existing Suchak-requests screen | 55 min | yes — a list that shows nothing to act on is still truthful |
| 1.3 | Confirm action on a completed meeting | 45 min | yes |
| 1.4 | Dispute action, reason required | 45 min | yes |
| 1.5 | Marathi + English strings for 1.2–1.4 | 30 min | yes |

**Blocking dependency:** none. Every endpoint exists.
**Tests per leaf:** widget test that the action is offered only in the state the server accepts.
**Risk:** 1.3 and 1.4 move money-relevant state. Neither invents a rule — both call a guarded
service — so the risk is UI offering an action the server will refuse, which the widget test pins.

---

## Feature 2 — The customer confirms a delivery stage

**User goal:** the family confirms the Suchak actually delivered a rung of the ladder they paid
for.

| | |
|---|---|
| Entry point | Web portal `/customer-portal/{token}/stages` — **exists**. Member app — **missing** |
| API | `POST /suchak-engagements/{collaboration}/stages/confirm` — exists, never called |
| UI | web only, over a tokenised link |
| Services | `CustomerStageDoorController`, engagement stage services |
| DB | `suchak_customer_agreement_stages` |
| Tests | covered server-side |
| **Completion** | **API 100% · web UI 100% · app UI 0% · overall ~65%** |

### Leaves

| # | Leaf | Time |
|---|---|---|
| 2.1 | Stage list on the engagement, read-only, in the member app | 50 min |
| 2.2 | Confirm-stage action | 40 min |
| 2.3 | Strings | 25 min |

**Blocking dependency:** **PO decision** — is the tokenised web link enough? A family that
already has the app should not be sent a WhatsApp link to do something the app could do, but the
web door works today and the app door does not. Answering "web is enough" closes this feature at
65% and frees two hours.

**Risk:** low. Duplicating a door that already works is safe; building it badly is not.

---

## Feature 3 — The customer sees the price they agreed to

**User goal:** the family can re-read the frozen agreement — registration fee, per-meeting fee,
success fee — without asking the Suchak.

| | |
|---|---|
| Entry point | Web `/customer-portal/{token}` — exists. App — **missing** |
| API | no member-app endpoint for the frozen snapshot |
| Services | `SuchakAgreementService`; the hash already covers all four money fields |
| DB | `suchak_customer_agreements` |
| **Completion** | **freeze 100% · web read 100% · app read 0% · overall ~55%** |

### Leaves

| # | Leaf | Time |
|---|---|---|
| 3.1 | Member API read endpoint for the frozen agreement of the caller's own profile | 45 min |
| 3.2 | Agreement screen in the member app | 55 min |
| 3.3 | Strings | 25 min |

**Blocking dependency:** none for 3.1. 3.2 depends on 3.1 — the only intra-feature dependency in
this document, and it is one step.
**Risk:** low, read-only. 3.1 must scope to the caller's own agreement; that is the whole test.

---

## Feature 4 — A Suchak opens a customer to the market

**User goal:** publish a challenge, receive proposals, pick one.

| | |
|---|---|
| Entry point | Suchak app — `publish_challenge_screen`, `marketplace_screen`, `marketplace_listing_screen`, `propose_candidate_screen`, `challenge_proposals_screen`, `candidate_proposal_inbox_screen` |
| API | 8 challenge routes + proposal routes |
| Services | `SuchakMarketplaceChallengeService`, `SuchakCandidateProposalInboxService`, `SuchakCandidateMaskingService` |
| DB | `suchak_marketplace_challenges` |
| Tests | `SuchakMarketplaceChallengeTest`, `SuchakPublicMarketplaceTest`, `SuchakCollaborationMarketplaceAdvancedTest` |
| **Completion** | **~95%** |

### Leaves

| # | Leaf | Time |
|---|---|---|
| 4.1 | Chargeable ceiling on a challenge | 40 min |

**Blocking dependency:** **PO decision** — is a ceiling still wanted, amount or percent? The
blueprint names it; the built table does not have it. Do not add a money column to satisfy a
stale document.
**Risk:** low to build. An unused money column is worse than no column.

---

## Feature 5 — Money moves between Suchaks

**User goal:** a declared share becomes a debt, is settled, and both sides can see the ledger.

| | |
|---|---|
| Entry point | Suchak app — `cross_suchak_obligations_screen`, `success_fee_ledger_screen` |
| API | obligations index / ratio / raise / settle; tranches index / release / settle |
| Services | `SuchakCrossSuchakObligationService`, tranche services |
| DB | `suchak_success_fee_tranches`, obligations |
| Tests | `SuchakCrossSuchakObligationTest`, `SuchakSuccessFeeTrancheTest` |
| **Completion** | **~95%** |

### Leaves

None. Nothing was found missing. **Do not invent work here.**

---

## Feature 6 — The market sorts itself

**User goal:** a Suchak can judge another before opening a customer to them.

| | |
|---|---|
| Entry point | `reputation_screen`, `market_economics_screen`, `customer_history_screen` |
| API | `/reputation`, `/reputation/{account}`, `/marketplace/economics`, ratio endpoints |
| Services | `SuchakReputationService`, `SuchakMarketEconomicsService`, `SuchakCustomerHistoryService` |
| Tests | `SuchakReputationReadTest`, `SuchakMarketEconomicsTest` |
| **Completion** | **~90%** |

### Leaves

| # | Leaf | Time |
|---|---|---|
| 6.1 | Suggestion → Viewed, as a signal distinct from a decision | 45 min |

**Blocking dependency:** **PO decision** — who counts as having viewed: the Suchak opening the
card, or the customer being shown it? Two different facts. The wrong one silently corrupts every
reputation number built on it.
**Risk:** medium, and entirely in the definition rather than the code.

---

## Feature 7 — A meeting is recorded honestly

**User goal:** the trail survives a dispute a year later.

| | |
|---|---|
| API | schedule / complete / cancel / dispute — all routed |
| Services | `SuchakVisitConfirmationService` |
| DB | 43 columns incl. `meeting_sequence`, `helper_suchak_account_id`, `fee_amount`, `meeting_mode`; unique is `(pipeline_id, meeting_sequence)` — **the blueprint's B1 and B3 are already closed** |
| Tests | `SuchakMeetingEngineTest`, `SuchakMeetingFeeQuotingTest`, `SuchakOnlineMeetingFeeTest`, `SuchakFirstMeetingDoorTest` |
| **Completion** | **~90%** |

### Leaves

| # | Leaf | Time |
|---|---|---|
| 7.1 | Record cancelling actor, reason and attendance into the existing `metadata_json` | 40 min |

**Blocking dependency:** none. **No new columns** — the events table is the home the blueprint
itself names.
**Risk:** low, additive.

---

## Feature 8 — Sign-in actually proves who acted

**User goal:** an acceptance is evidence, not a record of an attempt.

| | |
|---|---|
| API | member OTP send has **no throttle**; Suchak OTP issuer has **no environment guard** |
| Services | `MobileOtpService` (guarded), `SuchakRegistrationService::issueOtp()` (**not** guarded) |
| **Completion** | **~60%** |

### Leaves

| # | Leaf | Time |
|---|---|---|
| 8.1 | Throttle `POST /auth/mobile-otp/send` | 10 min |
| 8.2 | Make `SuchakRegistrationService::issueOtp()` fail closed in production | 20 min |

**Blocking dependency:** none for either. The tier→money gates the blueprint puts behind these
wait on Meta credentials, which is not engineering work and is not in this WBS.
**Risk:** 8.2 — verify `EnsureSuchakLegacyOtpEnabled` stays off, or the owner's own code sign-in
breaks. Production currently holds `mobile_verification_mode = dev_show`, so the setting is live
even though the routes behind it are gated.

---

# Summary

| Feature | Completion | Leaves | Time | Blocked |
|---|---|---|---|---|
| 1 Customer confirms a meeting | ~40% | 5 | 3 h 25 | no |
| 2 Customer confirms a stage | ~65% | 3 | 1 h 55 | **PO** |
| 3 Customer reads the agreement | ~55% | 3 | 2 h 05 | no |
| 4 Suchak opens a customer | ~95% | 1 | 40 min | **PO** |
| 5 Money between Suchaks | ~95% | 0 | — | — |
| 6 The market sorts itself | ~90% | 1 | 45 min | **PO** |
| 7 Meeting recorded honestly | ~90% | 1 | 40 min | no |
| 8 Sign-in proves who acted | ~60% | 2 | 30 min | no |

**16 leaves. Every one under 60 minutes.** 11 buildable now (~6 h 40), 3 features waiting on a
product answer.

## Build order

1. **Feature 8** — 30 minutes, security, nothing depends on it.
2. **Feature 1** — the largest hole in the product and the one the money model assumes.
3. **Feature 7** — 40 minutes, closes the last honest-trail gap.
4. **Feature 3** — the customer can read what they agreed to.
5. Features 2, 4, 6 once the three decisions land.

## The three decisions

1. **Feature 2** — is the tokenised web link enough for stage confirmation, or does the app need
   its own door?
2. **Feature 4** — is a chargeable ceiling still wanted, and in what unit?
3. **Feature 6** — who counts as having viewed a suggestion?

Nothing else is blocked.
