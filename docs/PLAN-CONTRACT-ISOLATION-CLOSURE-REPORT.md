# Plan Contract Isolation — Final Closure Report

> **Project status:** COMPLETED  
> **Type:** Permanent project closure record (documentation only)  
> **Repository:** `laravel-matrimony`  
> **Permanent architecture reference:** [`ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md`](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md)  
> **Field detail SSOT:** [`FIELD-OWNERSHIP-MAP.md`](FIELD-OWNERSHIP-MAP.md)

---

# FINAL PROJECT STATUS: COMPLETED

Phases **1 → 4B** (plus Architecture Freeze) are done. Production code for Phases **2–3 / 3.1** is **deployed and frozen**. Phase **4A** was inventory-only; Phase **4B** and the freeze doc are documentation/labels on `main`. **Phase 4C is not started** and is out of scope for this closure.

Do not reopen catalog↔contract isolation as a redesign. For runtime rules, extension points, and “never do this again,” use the [Architecture Freeze](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md).

---

## 1. Original problem statement

Admin could not effectively edit member plans after purchases existed.

**Root cause:** `PlanTerm::syncAdminTermRows()` used a **delete-all + insert** pattern for billing terms. When `plan_terms` rows were still referenced by subscriptions, deletes were blocked. That pushed operators toward workarounds: **inactive plans**, duplicate catalog rows with slug suffixes such as `-2`, and an edit workflow the product owner **rejected**.

**Product decision:**

| Layer | Role | Mutability |
|---|---|---|
| **Plan / PlanTerm** (and related catalog tables) | Editable **catalog** sold in admin | Live; admin may change any time |
| **Subscription** | Immutable **contract** for what the member already bought | Frozen at purchase / renew / PayU finalize |

Contract values freeze via `subscriptions.meta.checkout_snapshot` (quotas, features, timing multipliers, ranking) and, later, dedicated **grace / leftover-carry columns** on `subscriptions`.

**Why grace/carry could not stay live on `plans` alone:** SQL JOINs and access/carry readers that still consulted live `plans.*` meant catalog edits would rewrite windows for members who had already paid. Grace and carry therefore moved onto **subscription columns** (Phase 2), not only JSON.

Detail and runtime rules: [Architecture Freeze §1–§2](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md).

---

## 2. Timeline of Phase 1–4B

Production during Phase 2–3 deploys held **testing data only** (not live paying traffic in the sense of frozen business contracts under load). Code phases below are **deployed/frozen** where noted.

| Phase | What shipped | Commit anchor | Notes |
|---|---|---|---|
| **Phase 1** | PlanTerm **upsert** (`updateOrCreate` by plan + billing key); keep used-term delete guards | `68117a7a` | Unlock admin billing edits without delete-all |
| **Phase 2** | Grace / leftover-carry **contract columns** on `subscriptions` + paid readers (`*ForSubscription`) | `38463485` | **Deployed / frozen on production** |
| **Phase 3A–3C + 3.1** | Snapshot timing, features, ranking; leak closure (fail-closed paid quota UI, carry from previous snap, etc.) | `58f8be01` | **Deployed / frozen on production** |
| **Phase 4A** | Cleanup **inventory only** | *(no code commit)* | Classifications only; no deletes |
| **Phase 4B** | Docs / labels / wording (plan vs contract) | `e95b7db9` | No runtime behavior change |
| **Architecture Freeze** | Permanent LOCKED reference | `c507900e` | Not an implementation plan |

Phase purpose / outcome table (why each phase existed): [Architecture Freeze §15](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md).

---

## 3. Major architectural decisions

| Decision | Meaning |
|---|---|
| **Catalog vs contract** | Admin may edit catalog freely; existing paid members keep what they bought |
| **Snapshot JSON** | Quotas (`quota_policies`), non-quota features (`features`), term timing multipliers, and `priority_listing` (inside `quota_policies`) freeze on `checkout_snapshot` |
| **Subscription columns for grace/carry** | `grace_period_days` and `leftover_quota_carry_window_days` are SQL columns (JOIN-safe); not a second SSOT inside snapshot JSON |
| **Fail-closed paid PlanQuotaUiSource** | Paid quota UI reads snapshot only; missing/incomplete → fail closed (Phase 3.1) |
| **No live PlanFeature / PlanTerm / plan_quota_policies for existing paid runtime** | Catalog remains for free/display/admin and for **purchase-time** freeze writers |
| **Writers freeze at purchase** | Subscribe / renew / PayU finalize copy catalog → contract; later catalog edits do not rewrite paid runtime |

Full rules and “never again” list: [Architecture Freeze §11](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md).

---

## 4. Final architecture

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

| Concern (paid member) | Contract home |
|---|---|
| Quotas | `checkout_snapshot.quota_policies` |
| Features | `checkout_snapshot.features` |
| Timing multipliers | `checkout_snapshot.quota_bonus_percent` / `quota_duration_multiplier` |
| Ranking | `checkout_snapshot.quota_policies.priority_listing` |
| Grace / carry window | `subscriptions.grace_period_days` / `leftover_quota_carry_window_days` |
| Carry bag on renew | `subscriptions.meta.carry_quota` (limits from **previous** snap) |

**Permanent reference (do not duplicate detail here):** [`docs/ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md`](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md).

---

## 5. Production rollout summary

| Wave | What | Ops pattern | Migrate on prod? |
|---|---|---|---|
| **Phase 2** | Grace/carry columns + readers (`38463485`) | Backup → migrate → smoke | Yes (additive) |
| **Phase 3 / 3.1** | Snapshot freeze + leak closure (`58f8be01`) | Backup → migrate (backfills) → smoke | Yes (additive backfills) |
| **Phase 4B + Architecture Freeze** | Docs/labels (`e95b7db9`, `c507900e`) on `main` | Docs only | **No** prod migrate required |

Context: production data during Phase 2–3 deploys was **testing data only**. Runtime behavior after Phase 3.1 is frozen; do not treat Phase 4 leftovers as urgent prod defects ([Freeze §14](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md)).

---

## 6. Test summary

Focused **contract** suites (project speed policy: targeted files, not the full suite):

| Suite | Path |
|---|---|
| Quota timing on snapshot | `tests/Feature/CheckoutSnapshotQuotaTimingContractTest.php` |
| Features on snapshot | `tests/Feature/CheckoutSnapshotFeaturesContractTest.php` |
| Priority listing / ranking | `tests/Feature/CheckoutSnapshotPriorityListingRankingContractTest.php` |
| Phase 3.1 leak closure | `tests/Feature/CheckoutSnapshotPhase31LeakClosureContractTest.php` |

Related Phase 1 / 2 guards:

| Suite | Path |
|---|---|
| PlanTerm reference / upsert guard | `tests/Feature/PlanTermSubscriptionReferenceGuardTest.php` |
| Contract timing readers | `tests/Feature/SubscriptionContractTimingReadersTest.php` |
| Contract timing writers | `tests/Feature/SubscriptionContractTimingWritersTest.php` |

---

## 7. Risks intentionally deferred

Source: Phase 4A inventory + [Architecture Freeze §14](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md). Labels are **Future / Needs verification** — not a punch list to implement now. **Do not start Phase 4C from this report.**

| Item | Classification (summary) |
|---|---|
| `ChatController` chat-image gate: entitlement OR vs `canUseChatImages` | Needs verification (admin-grant story) |
| Who-viewed preview refresh soft catch → soft monthly | Future / probably removable later |
| Unused `SubscriptionService` assert*/count* helpers | Future cleanup |
| `FeatureUsageService::assertProfileViewLimit` / `debugFeatureState` | Future cleanup |
| Soft timing defaults 0 / 1.0 | Keep until harden (after backfill completeness proven) |
| `QuotaEngineService::getUserQuotaSummary` outer catch | Needs verification (UX vs honesty) |
| Grace SQL expression duplication across services | Future dedupe only |
| `Plan`/`PlanTerm` deprecated `final_price` % fallback | Needs verification (selling-price SSOT) |
| `UserEntitlementService::userHasEntitlement` existence-only | Needs verification |
| Catalog writers, free readers, backfills, `grace_percent_of_plan` sync | Keep intentionally (dual-layer design) |

---

## 8. Future maintenance guidance

1. **Never** reintroduce live catalog reads for **existing paid** member runtime (quotas, features, term multipliers, grace, carry window, ranking). See [Freeze §11](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md).
2. **Extend** only via approved extension points in [Freeze §13](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md) (new quota keys, new PlanFeature freeze, free/admin catalog display, entitlement overrides, additive backfills).
3. When adding fields/columns/engines: update [`FIELD-OWNERSHIP-MAP.md`](FIELD-OWNERSHIP-MAP.md) in the **same** change set; if map and code disagree, code wins and the map must be fixed.
4. **PHASE-5:** additive migrations only — no delete/rename/repurpose/type-change of existing columns/tables; no destructive schema “cleanup.”
5. Phase 4C+ (if ever scheduled): one hypothesis, one change, Tier A first — and must not reopen architecture.
6. Catalog delete-all term sync must not return; keep Phase 1 upsert + used-term delete guards.

---

## 9. Links

### Architecture and ownership

- Architecture Freeze: [`docs/ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md`](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md)
- Field ownership map: [`docs/FIELD-OWNERSHIP-MAP.md`](FIELD-OWNERSHIP-MAP.md)

### Major migrations

| Phase | Path |
|---|---|
| Phase 2 — grace / carry columns | `database/migrations/2026_08_06_170000_add_grace_and_carry_to_subscriptions_table.php` |
| Phase 3A — quota timing backfill | `database/migrations/2026_08_06_180000_backfill_checkout_snapshot_quota_timing.php` |
| Phase 3B — features backfill | `database/migrations/2026_08_06_190000_backfill_checkout_snapshot_features.php` |
| Phase 3C — priority_listing backfill | `database/migrations/2026_08_06_200000_backfill_checkout_snapshot_priority_listing.php` |

### Contract tests

- `tests/Feature/CheckoutSnapshotQuotaTimingContractTest.php`
- `tests/Feature/CheckoutSnapshotFeaturesContractTest.php`
- `tests/Feature/CheckoutSnapshotPriorityListingRankingContractTest.php`
- `tests/Feature/CheckoutSnapshotPhase31LeakClosureContractTest.php`
- `tests/Feature/PlanTermSubscriptionReferenceGuardTest.php`
- `tests/Feature/SubscriptionContractTimingReadersTest.php`
- `tests/Feature/SubscriptionContractTimingWritersTest.php`

### Commit anchors (known)

| Commit | Role |
|---|---|
| `68117a7a` | Phase 1 — PlanTerm upsert |
| `38463485` | Phase 2 — grace / carry contract columns |
| `58f8be01` | Phase 3 / 3.1 — snapshot freeze + leak closure |
| `e95b7db9` | Phase 4B — docs / labels |
| `c507900e` | Architecture Freeze document |

---

## 10. Final project status: COMPLETED

```
╔══════════════════════════════════════════════════════════════╗
║  PLAN CONTRACT ISOLATION — FINAL STATUS: COMPLETED           ║
║  Phases 1–4B + Architecture Freeze closed                    ║
║  Phase 4C NOT started                                        ║
╚══════════════════════════════════════════════════════════════╝
```

| Checkpoint | Status |
|---|---|
| Phase 1 — term upsert | Done (`68117a7a`) |
| Phase 2 — grace/carry columns | Done; **prod deployed/frozen** (`38463485`) |
| Phase 3A–3C + 3.1 | Done; **prod deployed/frozen** (`58f8be01`) |
| Phase 4A — inventory | Done (docs/inventory only; no code) |
| Phase 4B — docs/labels | Done (`e95b7db9`) |
| Architecture Freeze | Done (`c507900e`) |
| Phase 4C — code cleanup | **Not started** (explicitly deferred) |

Ongoing maintenance uses the [Architecture Freeze](ARCHITECTURE-FREEZE-PLAN-CONTRACT-ISOLATION.md) as the permanent architectural SSOT for this domain.

---

*End of closure report. Documentation only — no application code change implied by this file.*
