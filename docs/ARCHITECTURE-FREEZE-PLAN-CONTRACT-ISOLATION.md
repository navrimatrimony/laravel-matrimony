# Architecture Freeze — Plan Catalog vs Subscription Contract Isolation

> **Status:** LOCKED / FREEZE (production)  
> **Type:** Permanent architectural reference — not an implementation plan  
> **Scope:** Member paid-plan catalog, checkout snapshot, subscription contract columns, entitlements  
> **Detail SSOT for field rows:** [`FIELD-OWNERSHIP-MAP.md`](FIELD-OWNERSHIP-MAP.md) (rows for grace/carry, `checkout_snapshot`, features, ranking)  
> **PHASE-5:** Additive migrations only; do not delete, rename, repurpose, or type-change existing columns/tables.

---

## Banner

**Phases 1–3 (including 3.1) are deployed and frozen on production.**  
**Phase 4B documentation/comment polish is committed.**  
**Do not reopen catalog↔contract isolation as a redesign.**  
**Do not start Phase 4C (code cleanup) from this document** — leftovers in §14 are labelled **future / Needs verification**, not open bugs to fix now.

| Anchor commit | Role |
|---|---|
| `38463485` | Phase 2 — contract-isolate grace / carry timing onto `subscriptions.*` |
| `58f8be01` | Phase 3 / 3.1 — freeze quota / features / timing / ranking on `checkout_snapshot`; close live-catalog runtime leaks |
| `e95b7db9` | Phase 4B — docs / labels / wording after Phase 3 freeze |

---

## 1. High-level architecture

Member monetization has **two layers** that must never be collapsed:

| Layer | What it is | Mutability | Who it affects |
|---|---|---|---|
| **Catalog** | Editable plan definition sold in admin and shown on free/display surfaces | Live; admin may change any time | Future purchases, free-tier display, admin plan cards |
| **Contract** | What an **already-paying** member actually bought | Frozen at purchase / renew / PayU finalize | Existing paid runtime only |

```
Admin edits Plan / PlanTerm / PlanFeature / plan_quota_policies
        │
        ▼
   [ CATALOG ]  ──purchase / renew / PayU──►  PlanQuotaCheckoutSnapshot
                                              + contractTimingAttributesFromPlan
        │                                              │
        │ free / no-sub / admin read                   ▼
        ▼                                    [ CONTRACT ]
   live catalog tables                       checkout_snapshot (meta JSON)
                                             + subscriptions.grace_period_days
                                             + subscriptions.leftover_quota_carry_window_days
                                             + intentional user_entitlements rows
                                                       │
                                                       ▼
                                             paid runtime readers (fail-closed where noted)
```

**Rule of thumb:** changing the catalog must not rewrite what an existing paid member already has, unless an explicit repair/backfill or admin override path is used.

---

## 2. Catalog vs Contract model

### Catalog (editable)

| Artifact | Role |
|---|---|
| `plans` | Plan header; includes catalog grace / leftover-carry window columns |
| `plan_terms` (`PlanTerm`) | Billing rows (price / selling_price / duration / quota timing multipliers) |
| `plan_features` (`PlanFeature`) | Feature key → value for catalog / free / purchase freeze source |
| `plan_quota_policies` | Quota engine policy rows per feature key |

Catalog is the source for **future** purchases and for surfaces that intentionally show “what the plan offers now.”

### Contract (frozen for paid members)

| Artifact | Role |
|---|---|
| `subscriptions.meta.checkout_snapshot` | Frozen purchase payload |
| `subscriptions.grace_period_days` | Frozen grace days (column, not JSON duplicate) |
| `subscriptions.leftover_quota_carry_window_days` | Frozen leftover carry window (nullable = carry off) |
| `subscriptions.meta.carry_quota` | Applied leftover bag on renew/repurchase (units remaining) |
| `user_entitlements` | Access tokens / existence; optional `value_override` — **not** a second home for PlanFeature catalog values |

### Key `checkout_snapshot` keys

| Key | Meaning |
|---|---|
| `quota_policies` | Complete map of every `PlanQuotaPolicyKeys::ordered()` key (includes `priority_listing`) |
| `features` | Non-quota PlanFeature string map at purchase |
| `quota_bonus_percent` | Frozen term bonus % (Phase 3A) |
| `quota_duration_multiplier` | Frozen term duration multiplier (Phase 3A) |

`priority_listing` lives **inside** `quota_policies` — do not add a denormalized ranking column.

---

## 3. Runtime data flow

### Paid member (existing subscription)

1. Resolve active / effectively-active subscription (access window uses **subscription** grace, not live `plans.grace_period_days`).
2. Read quotas via `PlanQuotaUiSource::policyPayloadsForUser` / `policyPayloadsForSubscription` → **`checkout_snapshot.quota_policies` only** (fail-closed if missing/incomplete).
3. Read non-quota features via `PlanFeatureContractSource::valueForUser` / `valueForSubscription` → **`checkout_snapshot.features`**.
4. Read timing multipliers via `SubscriptionService::quotaBonusPercentForSubscription` / `quotaDurationMultiplierForSubscription` → snapshot keys (soft-default 0 / 1.0 with one-shot warning if missing — never live `plan_terms`).
5. Read grace / carry window via `PlanSubscriptionTerms::*ForSubscription` → subscription columns.
6. Ranking spotlight via `ProfileSearchRankingService` → JSON on subscription meta (`quota_policies.priority_listing`), plus existing entitlement / boost paths — **no JOIN to live `plan_quota_policies`**.

### Free / no-subscription / admin catalog / purchase-time write

Live catalog reads remain **intentional**:

- `PlanQuotaUiSource::policyPayloadsFromPlan`
- `PlanFeatureContractSource::featuresMapForPlan`
- `PlanSubscriptionTerms::gracePeriodDays(Plan)` / `leftoverQuotaCarryWindowDays(Plan)`
- Admin plan UI and purchase writers

### Carry on renew

`SubscriptionService::resolveCarryQuotaFromPreviousSubscription` — limits for carry math come only from the **previous** subscription’s `checkout_snapshot.quota_policies` (never live `plan_quota_policies`). Missing previous snap key → skip that feature (log once).

---

## 4. Checkout snapshot ownership

| Concern | Owner |
|---|---|
| **Writer (purchase freeze)** | `PlanQuotaCheckoutSnapshot::forPlan()` on subscribe / PayU finalize / create / renew |
| **Completeness gate at write** | `PlanQuotaUiSource::assertCompleteQuotaPayloads` |
| **Features fragment** | `PlanFeatureContractSource::featuresMapForPlan()` inside the snapshot writer |
| **Timing keys on snap** | Purchase path copies from `PlanTerm`; repair via `SubscriptionService::backfillCheckoutSnapshotQuotaTiming` |
| **Features repair** | `SubscriptionService::backfillCheckoutSnapshotFeatures` / `PlanFeatureContractSource::backfillCheckoutSnapshotFeatures` |
| **Priority listing repair** | `SubscriptionService::backfillCheckoutSnapshotPriorityListing` |
| **Paid runtime reader (quotas)** | `PlanQuotaUiSource` only — fail-closed |
| **Paid runtime reader (features)** | `PlanFeatureContractSource::valueFor*` only |

Backfill / repair helpers are **Keep intentionally** (migrate-time and recovery), not dead code.

---

## 5. Subscription ownership

| Fact | Home | Writer | Runtime reader |
|---|---|---|---|
| Subscription lifecycle / dates | `subscriptions` row | `SubscriptionService` (+ PayU finalize paths) | Existing subscription resolvers |
| Frozen grace days | `subscriptions.grace_period_days` | `PlanSubscriptionTerms::contractTimingAttributesFromPlan()` at purchase/renew | `PlanSubscriptionTerms::gracePeriodDaysForSubscription` (+ SQL that uses the **subscription** column) |
| Frozen leftover carry window | `subscriptions.leftover_quota_carry_window_days` | Same contract timing writer | `PlanSubscriptionTerms::leftoverQuotaCarryWindowDaysForSubscription` |
| Checkout contract blob | `subscriptions.meta.checkout_snapshot` | §4 | §3 paid readers |
| Carry bag | `subscriptions.meta.carry_quota` | Renew/repurchase carry resolution | `SubscriptionService::carriedQuotaBonus` / FeatureUsage referral-carry paths |

Catalog originals for grace/carry remain on `plans.*`. Member UI labels for contract timing belong in subscription-contract copy (`lang/*/user_plan.php`), not “live plan” wording.

---

## 6. Grace / carry ownership

| Axis | Catalog | Contract |
|---|---|---|
| Grace days | `plans.grace_period_days` | `subscriptions.grace_period_days` |
| Leftover carry window | `plans.leftover_quota_carry_window_days` | `subscriptions.leftover_quota_carry_window_days` |
| Applied leftover units | — | `subscriptions.meta.carry_quota` |
| Legacy derived % | `plan_quota_policies.grace_percent_of_plan` (derived from plan duration; admin sync) | Not the paid access clock |

**Never** re-read live `plans.grace_period_days` for:

- Runtime access / effectively-active windows  
- Entitlement expiry tied to plan grace  
- Carry eligibility window after grace  

**Keep intentionally:** `PlanSubscriptionTerms::gracePeriodDays(Plan)` for free-tier UI, admin catalog, and purchase-time copy; `derivedGracePercent*` / `syncDerivedGracePercentToAllQuotaPolicies` for DB column compatibility.

---

## 7. Quota ownership

| Concern | Owner |
|---|---|
| Catalog policy rows | `plan_quota_policies` + admin / mirror helpers (`PlanQuotaPolicyMirror`) |
| Frozen policy map | `checkout_snapshot.quota_policies` |
| Paid UI / engine payloads | `PlanQuotaUiSource::policyPayloadsForUser` / `policyPayloadsForSubscription` (**fail-closed**) |
| Usage consume / allow | `FeatureUsageService` + `QuotaEngineService` (read contract via PlanQuotaUiSource / feature contract source) |
| Carry limits | Previous snap `quota_policies` only (Phase 3.1) |

Paid path: missing or incomplete `quota_policies` map → **no live catalog fallback**.

---

## 8. Feature ownership

| Concern | Owner |
|---|---|
| Catalog feature values | `plan_features` |
| Frozen non-quota values | `checkout_snapshot.features` |
| Paid runtime value | `PlanFeatureContractSource::valueForUser` / `valueForSubscription` |
| Entitlement row | Existence / access token via `EntitlementService::assignFromSubscription` (keys from snapshot features map for paid) |
| Chat images gate | Frozen `checkout_snapshot.features.chat_image_messages` (+ entitlement as token only) — `SubscriptionService::canUseChatImages` / `ChatMessageService::sendImageMessage` |

**Do not** store the same PlanFeature catalog values in `user_entitlements.value_override` as a parallel SSOT.  
`value_override` remains for **intentional** admin / commerce overrides only.

Phase 3.1 rule: entitlement row existence alone must not reopen a feature after a catalog edit when the frozen snap says `0`.

---

## 9. Ranking ownership

| Concern | Owner |
|---|---|
| Frozen priority value | `checkout_snapshot.quota_policies.priority_listing` |
| Search spotlight apply | `ProfileSearchRankingService::applySpotlightFirst` (JSON on subscription meta) |
| Other boosts | Existing `user_entitlements` / `profile_boosts` paths |

**Do not:**

- JOIN live `plan_quota_policies` for member ranking  
- Add a denormalized ranking column beside the frozen quota payload  

Free / no-subscription members: no plan priority from live catalog at runtime (boosts/entitlements only). Catalog still defines purchase-time freeze and admin plan cards.

---

## 10. Purchase-time vs runtime behavior

| Moment | Catalog allowed? | Contract used? |
|---|---|---|
| Admin edits plan | Yes (writes catalog) | No change to existing subs |
| Member browses plans / free tier | Yes | N/A or free paths |
| Subscribe / renew / PayU finalize | **Read catalog → freeze** via `PlanQuotaCheckoutSnapshot` + `contractTimingAttributesFromPlan` | Writes contract |
| Paid member uses quotas / features / ranking / grace | **No** (except intentional free/admin/purchase writers) | Yes |
| Repair / backfill migrations | May copy from catalog into incomplete snaps | Yes (repair only) |
| Soft timing defaults (0 / 1.0) | Never live PlanTerm | Soft-default on missing snap keys + one-shot `Log::warning` |

Writers freeze at purchase/renew/PayU. Readers for paid members are listed in §3–§9.

---

## 11. Explicit “Never Do This Again” rules

1. **Never** make paid runtime re-read live `Plan` / `PlanTerm` / `PlanFeature` / `plan_quota_policies` for quotas, features, timing multipliers, grace, carry window, or ranking.
2. **Never** treat `user_entitlements` row existence as the PlanFeature **value** for paid members (row ≠ frozen value).
3. **Never** duplicate frozen grace/carry into `checkout_snapshot` as a second SSOT — columns own timing; snap owns quota/features/multipliers.
4. **Never** JOIN live `plan_quota_policies` for search spotlight / priority listing.
5. **Never** add a parallel denormalized ranking column when `priority_listing` already lives in frozen `quota_policies`.
6. **Never** use live `plan_quota_policies` for leftover carry math on renew — previous snap only.
7. **Never** delete-all / recreate plan billing terms in a way that orphans subscription references — Phase 1 upsert discipline stays.
8. **Never** store age; never bypass MutationService for profile writes; never destructive column/table changes (PHASE-5).
9. **Never** invent a second quota UI source beside `PlanQuotaUiSource` for paid members.
10. **Never** “fix” a paid leak by silently falling back to catalog after fail-closed was shipped (Phase 3.1).
11. **Never** rename/repurpose existing subscription or plan columns for “cleanup” — additive only.
12. **Never** treat Phase 4A inventory leftovers as a mandate to delete Keep-intentionally catalog writers, backfills, or free-tier readers.

---

## 12. FIELD OWNERSHIP summary

**Canonical detail:** [`docs/FIELD-OWNERSHIP-MAP.md`](FIELD-OWNERSHIP-MAP.md) — search before adding any field, column, or engine. If map and code disagree, **code wins** and the map must be updated.

| Fact (summary) | Canonical home |
|---|---|
| Catalog MRP / payable | `plan_terms.price` / `selling_price` (mirrored on plan default billing) |
| Frozen grace / carry window | `subscriptions.grace_period_days` / `leftover_quota_carry_window_days` |
| Leftover carry bag | `subscriptions.meta.carry_quota` |
| Frozen quota policies | `checkout_snapshot.quota_policies` |
| Frozen quota timing multipliers | `checkout_snapshot.quota_bonus_percent` / `quota_duration_multiplier` |
| Frozen non-quota features | `checkout_snapshot.features` |
| Chat image gate value | `checkout_snapshot.features.chat_image_messages` |
| Search priority | `checkout_snapshot.quota_policies.priority_listing` |

Writers / readers / traps for each row are in the map (approximately rows covering Phase 2 / 3A–3C / 3.1). This freeze doc owns **architecture**; the map owns **per-fact lookup**.

---

## 13. Approved extension points

Safe ways to extend without breaking isolation:

| Extension | How |
|---|---|
| New quota policy key | Add to `PlanQuotaPolicyKeys::ordered()` + catalog row + ensure `PlanQuotaCheckoutSnapshot` freezes it; paid readers already consume the complete map |
| New non-quota PlanFeature | Catalog `plan_features` + include in `featuresMapForPlan` so purchase freezes it; read via `PlanFeatureContractSource` |
| New catalog-only display | Admin / free / marketing surfaces reading live plan tables |
| Admin override of a value | Existing entitlement / commerce override paths (`value_override`) — do not invent a third store |
| Repair incomplete historical snaps | Existing `backfillCheckoutSnapshot*` helpers + additive migrations |
| Free-tier behavior | Live catalog readers (intentional) |
| New billing term fields for **future** purchases | PlanTerm upsert (Phase 1 pattern); freeze into snap/columns at purchase if runtime must honor them |

Any extension that would make **existing paid** members re-read live catalog requires an explicit product decision and an update to this freeze + FIELD-OWNERSHIP-MAP in the same change set.

---

## 14. Areas intentionally left for future work

> **Label:** Future / Needs verification — **not** a punch list to implement now.  
> **Do not start Phase 4C from this section.**  
> Source: Phase 4A discovery inventory (approved as inventory only; Phase 4B was docs/comments only).

| Item | Classification | Notes |
|---|---|---|
| `ChatController` chat-image gate: entitlement OR vs `canUseChatImages` | Needs verification | Web path may still honor entitlement **row existence**; `ChatMessageService` follows Phase 3.1 snap value. Product must decide admin-grant story before removing OR. |
| Who-viewed preview refresh soft catch (`FeatureUsageService::whoViewedMePreviewRefreshType` catch → soft monthly) | Future / Probably removable later | Softens fail-closed when quota snap throws; harden only with explicit product decision. |
| Dead / unused `SubscriptionService` assert*/count* helpers | Future cleanup | Inventory classified Probably removable; no callers found — still not Phase 4C until scheduled. |
| `FeatureUsageService::assertProfileViewLimit` / `debugFeatureState` | Future cleanup | Legacy / misleading if used; verify before delete. |
| Soft timing defaults 0 / 1.0 | Keep intentionally until harden | Documented Phase 3A compatibility; harden only after production backfill completeness is proven. |
| `QuotaEngineService::getUserQuotaSummary` outer catch | Needs verification | Fail-closed may become empty UI strip — UX vs honesty trade. |
| Grace SQL expression duplicated across services | Future dedupe only | Same subscription column — refactor risk; not an SSOT bug. |
| `Plan`/`PlanTerm` deprecated `final_price` % fallback | Needs verification | Selling-price SSOT; data completeness before removal. |
| `UserEntitlementService::userHasEntitlement` existence-only | Needs verification | Tied to ChatController / admin constant usage. |
| Catalog writers, free readers, backfills, `grace_percent_of_plan` sync | Keep intentionally | Not leftovers — permanent dual-layer design. |

Phase 4C+ (if ever scheduled) must stay: one hypothesis, one change, Tier A first — and must not reopen architecture.

---

## 15. Decision log — why each phase existed

| Phase | Why it existed | Outcome / freeze |
|---|---|---|
| **Phase 1 — PlanTerm upsert** | Admin billing edits used delete-all patterns that risked orphaning subscription references and blocking safe catalog edits. | Unlock plan billing via term **upsert** (`updateOrCreate` by plan + billing key); keep used-term delete guards. Catalog remains editable without destroying history. |
| **Phase 2 — Grace / carry columns** (`38463485`) | Grace and leftover-carry lived only on live `plans.*`, so catalog edits changed access/carry windows for members who already paid. | Copy onto `subscriptions.grace_period_days` and `leftover_quota_carry_window_days` at purchase/renew via `contractTimingAttributesFromPlan`; runtime uses `*ForSubscription` only. |
| **Phase 3A — Quota timing on snapshot** | `quota_bonus_percent` / `quota_duration_multiplier` still fell back to live `PlanTerm` for older paid snaps. | Freeze keys onto `checkout_snapshot`; paid readers use snap only; missing → soft 0 / 1.0 + warning (never live term). |
| **Phase 3B — Features on snapshot** | Non-quota PlanFeature values were still readable from live `plan_features` for paid members. | Freeze `checkout_snapshot.features`; `PlanFeatureContractSource` is the paid reader; entitlements stay tokens / overrides. |
| **Phase 3C — Ranking on snapshot** | Search spotlight could still depend on live `plan_quota_policies.priority_listing`. | Reuse frozen `quota_policies.priority_listing` via `ProfileSearchRankingService` JSON — no live JOIN, no new column. |
| **Phase 3.1 — Leak closure** (`58f8be01`) | Residual paths still soft-fell to catalog (quota UI, carry math, chat images value, etc.). | Fail-closed paid quota UI; carry from previous snap; chat image value from frozen features; close live-catalog runtime leaks for existing subscriptions. |
| **Phase 4A — Inventory only** | After architecture freeze, identify obsolete helpers/comments/paths **without** changing code. | Approved inventory; classifications: Safe / Probably removable / Needs verification / Keep intentionally. No deletes. |
| **Phase 4B — Docs / labels** (`e95b7db9`) | Comments and member-facing “plan” wording still implied live catalog for contract values. | Documentation and label polish only; **no runtime behavior change**. |

**Explicit non-goals of this freeze document:** Phase 4C code cleanup, destructive schema work, catalog redesign, or Flutter contract changes.

---

## Related

- [`FIELD-OWNERSHIP-MAP.md`](FIELD-OWNERSHIP-MAP.md) — per-fact ownership (detail SSOT)  
- [`DEVELOPER-OPERATING-CONTRACT.md`](DEVELOPER-OPERATING-CONTRACT.md) — how agents execute (not business SSOT)  
- PHASE-5 project rules — additive DB only; MutationService / no silent overwrite  

---

*End of Architecture Freeze. Treat violations as escalation, not drive-by refactors.*
