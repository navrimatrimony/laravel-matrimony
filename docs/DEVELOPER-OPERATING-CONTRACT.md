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
- **Logical git commits** at stable checkpoints (see §5.4)  
- **Batch / dataset / ground-truth / benchmark sizes** for the active sprint (see §13.6)

### 5.2 Human approval required (STOP / escalate)

| Class | Examples |
|-------|----------|
| **SSOT conflict** | Mutation bypass, mutate immutable fields, dual SoT; **any proposed SSOT change** |
| **Approved business-rule decision** | Pricing, entitlements, product policy; **borderline** GO/NO-GO; scope outside the Approved Goal |
| **Destructive migration** | `DROP` / `TRUNCATE` / wipe — forbidden unless user explicitly orders that exact action |
| **Production release** | Enabling production flags / live customer rollout; **git push to production deploy path only when release authorized** |
| **External operational blocker** | Required dataset, secrets, or infra the agent cannot create (e.g. OCR golden set missing) |

### 5.3 Not escalations

- “Please approve starting the next sprint”  
- “Please approve this commit” / “Shall I commit?”  
- Re-asking ChatGPT for the next micro-prompt  
- Clear GO when metrics meet pre-agreed thresholds in a benchmark report  

### 5.4 Git commits during an Approved Goal (LOCKED)

```text
The agent SHALL create logical commits
during implementation whenever a stable
checkpoint is reached.

The agent SHALL NOT wait for user approval
before committing.

Only production release requires approval.
```

Rules:

- Commit when a sprint milestone, forensic+fix set, or doc contract is stable and tests for that slice pass.  
- Prefer small logical commits over one mega-commit.  
- Do **not** ask “commit हवा असेल तर सांगा” mid-goal.  
- Do **not** `git push` to trigger production deploy without production-release approval (§5.2). Pushing a feature branch for backup/PR is allowed when useful; production flag / live enable is not.  
- Never commit secrets, `.env`, or raw PII biodata batches (see §13.7).

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

## 12.1 Local Development Ownership (LOCKED)

```text
The agent owns the local development environment.

The agent SHALL install, configure,
upgrade and verify all development
dependencies whenever possible.

Examples include:

• Python
• Ghostscript
• Tesseract
• Poppler
• OCR language packs
• Composer packages
• NPM packages
• PHP extensions
• Local services
• OCR model downloads

The agent SHALL attempt automatic
installation first.

The user SHALL be asked only if:

• Operating system permission is required
  (e.g. UAC elevation the agent cannot complete)

• Administrator elevation is explicitly required
  and cannot be completed by the agent

• A software license or interactive agreement
  requires human acceptance

• Paid / company login is required

Otherwise installation,
configuration,
verification
and testing
remain the agent's responsibility.
```

```text
Whenever a dependency is required to achieve
the Approved Goal,

the agent SHALL attempt
automatic installation,
configuration,
verification
and testing,

then continue the goal immediately
without waiting for a micro-prompt.
```

**Forbidden:** Asking the Product Owner to open PowerShell and run install commands when the agent can install to a user-writable path (`%LOCALAPPDATA%`, scoop user, extracted portable binaries, Composer/NPM local, Python venv).

**Allowed ask (simple Marathi, one action):** only when Windows UAC / license / paid login truly blocks the agent.

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

User-facing instruction block **MUST** use this locked shape (and nothing more):

```text
तुम्ही आता फक्त हे करा.

१. कुठे जायचे.
२. काय ठेवायचे.   (किंवा: काय करायचे.)
३. किती ठेवायचे.
४. पूर्ण झाल्यावर मला नेमके काय लिहायचे.
५. पुढे मी काय करणार आहे.

यापेक्षा जास्त माहिती देऊ नका.
```

Avoid technical jargon (queue, tinker, grep, artisan) unless unavoidable — then explain in one plain sentence.

**Bad pattern:** “Run `php artisan …` with folder X and paste logs.”  
**Bad pattern:** Asking the user to invent folder names, batch sizes, or ground-truth counts.

Commit messages and code comments may stay in English; **user-facing** progress/asks stay Marathi when action is required.

### 13.6 Batch size / dataset ownership (LOCKED)

```text
The agent determines
batch size, dataset size, ground truth size,
and benchmark size according to the current sprint.

The user SHALL NOT decide dataset quantities.
```

The agent tells the user the numbers in the Marathi five-step block.  
Do not ask “50 किंवा 100?” — decide from Blueprint + sprint stage, then instruct.

### 13.7 Folder ownership + Sprint dataset contract (LOCKED)

**Canonical local inbox (OCR / biodata):**

```text
storage/app/ocr-dev-batches/Batch-001
storage/app/ocr-dev-batches/Batch-002
…
```

- The agent owns folder names (`Batch-001`, …). The user must not invent alternate folder names.  
- Raw biodata files are **local-only PII** — gitignore; never commit images/PDFs.  
- Tracker / README / empty dir markers may be committed.

**Sprint 2 — Batch-001 (agent-owned defaults):**

| Item | Agent decision |
|------|----------------|
| Size | **50** Marathi biodata files |
| Types | Mixed PDF / JPG / PNG |
| Quality | Real production mix: old scans, mobile photos, poor light, rotated pages, duplicate surnames, different layouts, handwritten corrections if available |
| Ground truth at drop | **Not required** for all 50 |
| Ground truth later | Agent will request a **subset** (e.g. 20) only when the sprint needs labels |

When files are missing → Escalation class **ops-blocker**, with the five-step Marathi block only.

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

## 17. Plateau Rule (LOCKED)

```text
A plateau SHALL NOT be declared
because one technique failed.

A plateau may be declared ONLY after
multiple reasonable,
production-feasible approaches
have been:

1. designed against the measured weakness,
2. implemented or evaluated offline,
3. benchmarked against ground truth,
4. accepted or rejected with evidence
   in the OCR Research Ledger,

and further attempts are variations
unlikely to change Raw OCR fidelity.

One failed install,
one failed crop,
one failed engine,
or one failed preprocessor
is NEVER by itself a plateau.
```

**Product Goal stays In Progress** while measurable fidelity work remains on ranked residual losses — even after a research loop closes.

---

## 18. Research Phase Completion (LOCKED)

```text
Research Phase Complete may be declared
ONLY when the agent reports:

I have exhausted all practical,
production-feasible improvements
for Raw OCR text fidelity
under the current architecture.

Further meaningful gains require
model training, a new OCR research
direction, or a business /
architecture decision.

Evidence: Ledger accept/reject table,
residual Mode-A cases,
and plateau criteria (§17) met.
```

Loop Complete ≠ Product Goal Complete ≠ Research Phase Complete.

| Status | Meaning |
|--------|---------|
| **Loop Complete** | One measured weakness cycle finished (accept/reject + commit) |
| **Product Goal In Progress** | Fidelity objective not yet at DoD / plateau |
| **Research Phase Complete** | §18 statement + evidence; only then Product OCR research may freeze |
| **Escalation** | §5.2 only |

---

## 19. Product Impact First (LOCKED)

```text
Priority =
  information loss on GT / production proxies
  ×
  production frequency / coverage
  (PDF vs image; Marathi vs English; field criticality)

Before starting a loop, the agent SHALL ask:

  “Will this improvement affect
   thousands of real biodata intakes
   in production — not only one GT row?”

If the honest answer is No,
reject the loop (document in Ledger)
and pick the next ranked weakness.

Prefer +12% PDF fidelity over +2% DOB
on a single hard watermark when
PDF volume / coverage is higher.

Do not optimize rare GT overfitting
at the expense of production impact.
```

### 19.1 Dashboard = compass, not success (LOCKED)

```text
The Product Goal
shall always take precedence
over any dashboard metric.

Dashboard metrics
exist only
to measure progress
and guide priority,
not
to redefine
the Product Vision
or declare success by themselves.

Dashboard shall guide priority,
not success.

GT-20 green ≠ Product Goal complete.
All critical fields 100% on GT ≠
Raw OCR fidelity complete on real biodata.
```

Product Vision remains: maximize practical **RAW OCR text fidelity** for Marathi + Devanagari + English biodata in production; structured accuracy, Judge %, and human correction rates measure downstream use of that fidelity.

After every accepted or rejected loop, the agent SHALL update:

1. **`docs/OCR-PRODUCT-METRICS-DASHBOARD.md`** (GT + production sections when data exists)  
2. **`docs/OCR-RESEARCH-PHASE-LEDGER.md`** (technique register + Knowledge findings)  
3. Commit + push, then continue automatically until §17–18.

---

## Document history

| Version | Date | Change |
|---------|------|--------|
| 1.0 | 2026-07-15 | Initial DOC — separated agent execution from SSOT/Blueprint; goal ownership; DoD; escalation; evidence; reporting |
| 1.1 | 2026-07-15 | Mandate: own all implementation steps within scope; no Complete until DOC DoD fully satisfied |
| 1.2 | 2026-07-15 | User Interaction / Local-first / Marathi instructions / Minimal ask / Single Active Goal |
| 1.3 | 2026-07-15 | §5.4 mid-goal commits without ask; §13.5–13.7 batch/folder/dataset ownership + Sprint 2 Batch-001 contract |
| 1.4 | 2026-07-15 | §12.1 Local Development Ownership — agent installs deps (Ghostscript, Python, OCR models, …); user only for UAC/license/paid login |
| 1.5 | 2026-07-15 | §17 Plateau Rule; §18 Research Phase Completion — loop ≠ goal; multi-approach evidence required |
| 1.6 | 2026-07-15 | §19 Product Impact First + mandatory Product Metrics Dashboard / Ledger updates each loop |
| 1.7 | 2026-07-15 | §19.1 Dashboard = compass not success; Product Goal precedence over metrics |
