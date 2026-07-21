# laravel-matrimony — Backend

Laravel backend for the Navri Matrimony product. This is the **source of truth** for API contracts and business logic consumed by two sibling Flutter apps: `../flutter-apk` (members) and `../Suchak-apk` (Suchaks/operators). See `../CLAUDE.md` for the full workspace picture.

## Source of truth & operating model

This repo already has a governance hierarchy defined in [`docs/DEVELOPER-OPERATING-CONTRACT.md`](docs/DEVELOPER-OPERATING-CONTRACT.md) (LOCKED, explicitly applies to Claude Code):

```
SSOT (docs/*-SSOT*.md, docs/governance/, PHASE-5 docs)   → business/data truth
Blueprint (e.g. docs/OCR-ENSEMBLE-PIPELINE-BLUEPRINT.md) → what to build, in what order
Developer Operating Contract (docs/DEVELOPER-OPERATING-CONTRACT.md) → how the agent executes
This CLAUDE.md                                            → thin, repo-specific notes on top
```

Read the DOC before starting any non-trivial goal in this repo — it defines the Approved Goal flow, Definition of Done, escalation matrix, autonomous commit policy, and the required Marathi five-step format for any user-facing ask. If anything below conflicts with the DOC, **the DOC wins**. The workspace-wide "Working mode" in `../CLAUDE.md` is the fallback for repos that don't have their own DOC (`flutter-apk`, `Suchak-apk`) — here, the real DOC takes precedence over that generic summary too.

This repo has no separate `AGENTS.md` — the DOC fills that role (it explicitly names Cursor/Codex/Claude Code as covered).

## Impact awareness

Both Flutter apps depend on this backend's API shape. Before renaming, removing, or changing the type/meaning of a route, request field, or response field:

- Read `../flutter-apk/AGENTS.md` and `../Suchak-apk/AGENTS.md` — each documents the API facts that app currently relies on. Don't copy those facts here; just check them.
- If a change would break either app's documented assumptions, flag it rather than changing silently.

## Code conventions

- Business logic goes in `app/Services/**`, not in controllers — keep controllers thin.
- Validate all incoming requests (Form Requests or inline validation), don't trust client payloads.
- Reuse existing services instead of duplicating logic — this codebase already has deep service layers (see `app/Services/Intake/`, `app/Modules/Suchak/Services/`) for the OCR/intake and Suchak domains; check for an existing service before writing a new one.
- Never delete or rewrite existing migrations that may have already run — add new incremental migrations instead.

## Production

- Host `31.97.228.15`, path `/home/navri/htdocs/navrimilenavryala.com`.
- Never run `php artisan migrate`, restart queues/services, or deploy to this host without explicit confirmation.
- Always ask before running a destructive artisan command (`migrate:fresh`, `db:wipe`, etc.) anywhere.
