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

The agent owns the goal, not the task.

The agent shall autonomously execute all dependent
sprints/workstreams in sequence.

The agent shall determine and execute all required
implementation steps within the approved scope.

Do not stop between sprints.

Escalate ONLY for:

• SSOT conflict
• Approved business-rule decision
• Destructive migration
• Production release authorization
• External operational blocker
  (e.g. required dataset unavailable)

The agent shall not declare completion until the
Definition of Done defined in the Developer Operating
Contract is fully satisfied.

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

## 11. Dual-repo scope

Obey workspace dual-repo rules: Laravel vs Flutter scopes; no silent cross-repo edits. Prefer Laravel-only changes for OCR Ensemble goals unless the Approved Goal explicitly authorizes Flutter.

---

## 12. Local-first Contract (LOCKED)

```text
Development SHALL be Local-first.

The agent SHALL use the local
Laravel environment,
local database,
local logs,
local queue
as the primary source of truth for development,
debugging, forensics, and benchmarks.

Remote / production server SHALL NOT be used
for routine debugging or development.

Remote server MAY be used only for:
• production smoke / verification after pull
• real WhatsApp / Meta / payment / cron paths
• explicitly requested live validation
```

If local env is incomplete (no DB, no Tesseract, queue not runnable), report **Escalation: ops-blocker** with Marathi steps to enable local — do not default to SSH-on-live.

---

## 13. User Interaction Contract (LOCKED)

### 13.1 User responsibilities

The product owner (user) **only** supplies:

1. Biodata files / batches (and ground truth when the agent requests)  
2. Business decisions when the agent escalates (§5.2)  
3. Production release approval at the end  

The user is **not** required to dictate OCR strategy, parsing strategy, benchmark strategy, sprint order, or how many intakes to run — the agent decides those from Blueprint + DOC and **asks** for the matching inputs when needed.

### 13.2 Agent responsibilities

The agent **shall** itself:

- Read project, Blueprint, SSOT, and this DOC  
- Inspect local Laravel, database, logs, queue  
- Run tests and benchmarks as required by the goal  
- Deliver toward Definition of Done  
- Tell the user clearly what to do next when human action is required  

### 13.3 Minimal User Interaction Rule

```text
The agent SHALL minimize user interaction.

The agent SHALL ask the user
only for information or resources
that cannot be obtained locally.

Everything else shall be determined
from the local project.
```

```text
Never ask the user
what you can determine
from the local project.
```

**Forbidden questions** (examples — agent must self-serve):

- How many records are in the local DB?  
- Did migrations run?  
- Show me the logs / paste Laravel log  
- Is the queue running?  
- What is the current git branch / commit?  

**Allowed asks:** biodata folders, ground-truth labels, secrets the agent must not invent, business GO/NO-GO, production enable.

### 13.4 Biodata / input Contract (OCR and similar)

```text
The user provides biodata (and GT when asked).

The user does NOT specify OCR / parse / benchmark strategy.

The user does NOT decide intake counts casually.

The agent decides timing and volume from the Blueprint
and instructs the user in plain Marathi when files are needed.

Examples of agent requests:
• "आता Batch-01 मध्ये ५० biodata ठेवा."
• "आता Ground Truth शीट भरा."
```

Agent **must not invent** fake production biodata as ground truth. Synthetic fixtures are allowed only for automated unit/feature tests inside the repo.

### 13.5 Human Instruction Contract (LOCKED)

Whenever user action is required, instructions **MUST** be written in **simple Marathi (Devanagari)**, assuming a **non-programmer**.

Each user-facing instruction block **MUST** include:

१. कुठे जायचे / कोणती फोल्डर किंवा स्क्रीन  
२. काय करायचे  
३. किती करायचे (संख्या / बॅच)  
४. कधी थांबायचे  
५. पुढे काय होईल (agent काय करेल)

Avoid technical jargon (queue, tinker, grep, artisan) unless unavoidable — then explain in one plain sentence.

**Good pattern:**

```text
आता तुम्ही फक्त हे करा.

पायरी १
"D:\OCR\Batch-01" फोल्डरमध्ये ५० biodata PDF/JPG ठेवा.

पायरी २
मला फक्त लिहा: "Batch-01 तयार आहे."

पुढे मी स्वतः Local वर OCR, DB, tests, आणि दुरुस्ती करेन.
तुम्हाला command चालवायचा नाही.
पुढची सूचना येईपर्यंत थांबा.
```

**Bad pattern:** “Run `php artisan …` with folder X and paste logs.”

Commit messages and code comments may stay in English; **user-facing** progress/asks stay Marathi when action is required.

---

## 14. Single Active Goal (LOCKED)

- Only **one** Approved Goal active at a time unless the user explicitly stacks goals.  
- New chat / resume: continue the Active Goal until Complete, Escalation, or user cancels.  
- Do not reopen closed Phase 4 HTTP forensics for DOB-empty / merge_noop class issues (§19.2 OCR Blueprint) unless a **new** transport regression is proven.

---

## 15. Relationship to OCR Ensemble Blueprint §19

OCR product roadmap and sprint **order** live in `docs/OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md` §19.

**How** those sprints are executed (autonomy, DoD, escalation, evidence, user interaction) follows **this DOC**.

OCR-specific quality gates that remain in the Blueprint (unchanged by DOC):

- Benchmark before production second OCR  
- Learning after stable OCR path  
- Sprint 2 requires real biodata + ground truth (ops blocker if missing — do not skip benchmark)

---

## 16. Operating model (summary)

| Authority | Role |
|-----------|------|
| **SSOT** | What is correct (business + data) |
| **Blueprint** | What to build and in what order |
| **DOC (this file)** | How the agent executes |
| **Local project** | Operational truth (DB, logs, tests, queue) |
| **User** | Biodata / GT inputs, escalated decisions, production approval |

No fourth foundational “process” document is required for maturity. Prefer implementation under an Approved Goal.

---

## Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-15 | Initial DOC — separated agent execution from SSOT/Blueprint; goal ownership; DoD; escalation; evidence; reporting |
| 1.1 | 2026-07-15 | Mandate: own all implementation steps within scope; no Complete until DOC DoD fully satisfied |
| 1.2 | 2026-07-15 | User Interaction / Local-first / Marathi instructions / Minimal ask / Single Active Goal |
