# Developer Operating Contract (DOC)

> **Type:** Agent execution policy — **not** SSOT, **not** product Blueprint  
> **Repository:** `laravel-matrimony` (applies to all Cursor / Codex / Claude Code work in this repo)  
> **Status:** LOCKED — 2026-07-15  
> **Authority split:** see §1  

---

## 1. Document roles (do not collapse)

| Document | Answers |
|----------|---------|
| **SSOT / PHASE-5 / project rules** | Business truth, data governance, MutationService, approval, DB safety |
| **Product Blueprints** (e.g. OCR Ensemble Blueprint) | Roadmap, sprints, milestones, feature contracts |
| **Developer Operating Contract (this file)** | How an AI agent (or human operator of agents) **executes** work |

Change execution policy → edit **this DOC**.  
Change product roadmap → edit the relevant **Blueprint**.  
Change business/data law → edit **SSOT / phase rules**.

---

## 2. Core principle

**The agent owns the goal, not the task.**

It is responsible for planning, implementation, debugging, validation, regression testing, and evidence collection until the Approved Goal reaches Definition of Done.

Not acceptable: stopping after a single file edit and waiting for the next micro-prompt when the Approved Goal is still In Progress.

---

## 3. Approved Goal execution

### 3.1 Law

```
One Approved Goal
  may internally execute
  multiple dependent blueprint sprints / workstreams
  without intermediate human approval,
  when each workstream’s Definition of Done is met.
```

### 3.2 Canonical mandate template

```text
Approved Goal:

<one sentence goal referencing Blueprint section(s)>

The agent shall autonomously execute all dependent
sprints/workstreams in sequence.

Do not stop between sprints.

Escalate ONLY for:

• SSOT conflict
• Approved business-rule decision
• Destructive migration
• Production release authorization
• External operational blocker
  (e.g. required dataset unavailable)

Goal is complete only after the
Definition of Done is fully satisfied.
```

### 3.3 Default flow

```
Approved Goal
        ↓
Read Blueprint + SSOT + this DOC
        ↓
Internal Plan
        ↓
Workstream / Sprint N
        ↓
Self-debug + Tests
        ↓
Next workstream (auto)
        ↓
…
        ↓
Regression
        ↓
Manual UI (if UI changed)
        ↓
Evidence
        ↓
Done | In Progress | Escalation
```

ChatGPT (or other review chat) is **not** required between steps unless escalation fires.

---

## 4. Definition of Done (LOCKED)

**Missing any applicable item ⇒ status = In Progress.** Never report “90% done” as Complete.

```text
Goal SHALL NOT be reported as Complete unless:

✓ Scope implemented per Approved Goal / Blueprint DoD
✓ All automated tests for touched areas pass
✓ No known regression introduced
✓ Logs reviewed (no new critical errors attributable to this goal)
✓ Manual UI verification completed where UI changed; otherwise N/A noted
✓ Blueprint / SSOT compliance verified
✓ Required documentation updated
✓ Evidence attached (test results, benchmark, screenshots where applicable)

Otherwise status = In Progress.
```

**Sprint/workstream complete ≠ Goal/Program complete.**

---

## 5. Escalation Matrix (LOCKED)

### 5.1 Automatic (continue — no human stop)

- Implementation decisions inside Approved Goal scope  
- Refactors required to deliver the goal  
- Bug fixes / test fixes  
- Running benchmarks and recording scores  
- Docs required by DoD  

### 5.2 Human approval required (STOP / escalate)

| Class | Examples |
|-------|----------|
| **SSOT conflict** | Mutation bypass, mutate immutable fields, dual SoT; **any proposed SSOT change** |
| **Approved business-rule decision** | Pricing, entitlements, product policy; **borderline** GO/NO-GO; scope outside the Approved Goal |
| **Destructive migration** | `DROP` / `TRUNCATE` / wipe — forbidden unless user explicitly orders that exact action |
| **Production release** | Enabling production flags / live customer rollout |
| **External operational blocker** | Required dataset, secrets, or infra the agent cannot create (e.g. OCR golden set missing) |

### 5.3 Not escalations

- “Please approve starting the next sprint”  
- Re-asking ChatGPT for the next micro-prompt  
- Clear GO when metrics meet pre-agreed thresholds in a benchmark report  

---

## 6. Autonomous debugging

When tests, logs, or forensics show a defect **inside** the Approved Goal:

1. Diagnose with code + local evidence (prefer local DB/logs).  
2. Fix within SSOT.  
3. Re-test.  
4. Continue the goal.

Do **not** open a ChatGPT↔Cursor loop for routine defects. Escalate only per §5.2.

---

## 7. Mandatory testing

- Run the **smallest meaningful** automated suite for changed behavior (not the entire monorepo unless warranted).  
- Prefer existing project filters / feature tests already used for that domain.  
- No `--no-verify` / skip hooks unless the user explicitly orders it.  
- New behavior that can regress → add or extend a focused test when appropriate.

---

## 8. Regression policy

- Do not leave known regressions open when claiming Complete.  
- Prefer targeted regression for the domain (e.g. OCR ensemble phase filters) over full blind suite.  
- If a change risks unrelated areas, expand the suite and note it in evidence.

---

## 9. Evidence requirements

Before Complete, attach or leave findable:

| Evidence | When |
|----------|------|
| Test command + pass summary | Always |
| Benchmark report / metrics | When goal includes engine/eval work |
| Log / DB excerpt (redacted) | When diagnosing production-class bugs |
| UI note or screenshot path | When UI changed |
| Doc / Blueprint updates | When contracts or DoD require them |

Prefer repo docs / commit bodies over paste-only chat transcripts.

---

## 10. Completion reporting format

When finishing (or blocking), report **exactly** one of:

```text
STATUS: Complete
GOAL: <Approved Goal one-liner>
EVIDENCE: <tests / docs / commits>
REMAINING HUMAN: <production flag / none>
```

```text
STATUS: In Progress
GOAL: <Approved Goal one-liner>
DONE_SO_FAR: <bullets>
BLOCKED_ON: <DoD item still open OR none>
NEXT: <what agent will do without waiting>
```

```text
STATUS: Escalation
GOAL: <Approved Goal one-liner>
CLASS: <SSOT | business-rule | destructive | production | ops-blocker>
QUESTION: <single decision needed>
OPTIONS: <A / B if clear>
```

---

## 11. Dual-repo and local preference

- Obey workspace dual-repo rules: Laravel vs Flutter scopes; no silent cross-repo edits.  
- Prefer **local** app + DB + logs for development/forensics; use live only for smoke / production validation / external API (WhatsApp, Meta, payments).

---

## 12. Relationship to OCR Ensemble Blueprint §19

OCR product roadmap and sprint **order** live in `docs/OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` §19.

**How** those sprints are executed (autonomy, DoD, escalation, evidence) follows **this DOC**.

OCR-specific quality gates that remain in the Blueprint (unchanged by DOC):

- Benchmark before production second OCR  
- Learning after stable OCR path  
- Sprint 2 requires real biodata + ground truth (ops blocker if missing — do not skip benchmark)

---

## Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-15 | Initial DOC — separated agent execution from SSOT/Blueprint; goal ownership; DoD; escalation; evidence; reporting |
