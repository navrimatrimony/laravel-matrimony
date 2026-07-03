# Intake Smart Routing Phase 4A Runbook

## Phase 4A Goal

Phase 4A is a dry-run safety and cost-control layer for biodata intake routing. It records smart-routing recommendations, quality signals, duplicate-reuse signals, parser-proposal signals, and policy visibility without changing live intake behavior.

Phase 4A does not enable live routing. Stored routing recommendations are inspection data only.

Core principles:

- Sarvam is a teacher/evidence signal, not final truth.
- Final truth is the authorized human-approved snapshot.
- Authorized actors are `admin`, `profile_user`, and `suchak`.
- No live paid-vision skip is enabled.
- No duplicate-reuse live skip is enabled.
- Stored routing is dry-run only.
- Admin review screen shows stored Smart Routing Dry Run signals for human visibility.
- Python data engine may be used later for offline analysis/regression, not live routing yet.

## Verified Phase 4A Baseline

Last verified server acceptance baseline:

| Metric | Value |
| --- | ---: |
| Acceptance status | pass |
| Total scanned | 102 |
| would_skip_paid_vision | 0 |
| reuse_previous | 0 |
| would_call_paid_vision | 12 |
| call_sarvam | 12 |
| manual_review | 79 |
| unknown | 11 |
| parser_proposal_avoidable | 20 |
| parser_proposal_ambiguous | 9 |
| raw_evidence_absent | 44 |
| low_quality_cheap_ocr | 3 |
| policy_enabled | 0 |
| policy dry_run_only | yes=102 |
| allowed_live_actions | 0 |
| provider_failures | 0 |

## Refresh Dry-Run Routing

Run this only when you intentionally want to refresh stored dry-run recommendations from current stored intake data:

```bash
php artisan intake:routing-dry-run-refresh --limit=100 --all
```

Do not run this as part of a read-only report unless stale stored routing JSON is the problem being investigated.

## Reports

Generate the main dry-run report:

```bash
php artisan intake:routing-dry-run-report --limit=500 --details
```

Inspect Sarvam dry-run candidates without calling Sarvam:

```bash
php artisan intake:routing-sarvam-candidates --limit=100
```

Audit low field-confidence recommendations:

```bash
php artisan intake:field-confidence-audit --limit=100
```

Audit whether stored raw OCR text likely contains evidence for missing critical fields:

```bash
php artisan intake:critical-field-evidence-audit --action=call_sarvam --limit=100
```

Generate parser proposals for missing critical fields from stored raw OCR text only:

```bash
php artisan intake:critical-field-parser-proposals --action=call_sarvam --limit=100
```

Run the Phase 4A acceptance gate:

```bash
php artisan intake:routing-acceptance-report --limit=500 --fail-on-risk --max-paid-calls=12 --max-skip-calls=0 --max-reuse-previous=0 --max-unknown=20
```

## Acceptance Criteria

Phase 4A passes only when all of these are true for the configured thresholds:

- `would_skip_paid_vision <= max-skip-calls`
- `reuse_previous <= max-reuse-previous`
- `would_call_paid_vision <= max-paid-calls`
- `unknown <= max-unknown`
- `policy_enabled` count is zero
- no row has `allowed_live_action` other than `none`
- provider failure count is zero

The current baseline threshold bundle is:

```bash
--max-paid-calls=12 --max-skip-calls=0 --max-reuse-previous=0 --max-unknown=20
```

## What PASS Means

PASS means the stored dry-run routing state is inside the Phase 4A safety envelope:

- no live skip is permitted
- no duplicate reuse is permitted
- paid-vision recommendations are within the expected cap
- unknown rows are within the expected cap
- policy remains disabled/dry-run only
- provider failures are not present in the baseline

PASS does not mean live routing can be enabled. It only means Phase 4A dry-run evidence is acceptable for review and planning.

## What FAIL Means

FAIL means at least one safety guard or budget threshold was exceeded. Treat this as a stop signal before any future live-routing work.

Typical causes:

- `would_call_paid_vision` grew beyond the configured cap
- `would_skip_paid_vision` became non-zero
- `reuse_previous` became non-zero
- `unknown` count exceeded the cap
- stored policy JSON shows live policy enabled
- stored policy JSON contains an allowed live action
- provider failure signals are present

Do not enable live routing to "test" a failing baseline. Diagnose with the read-only reports first.

## What Not To Enable Yet

Do not enable any of the following in Phase 4A:

- live smart routing
- live Sarvam calls from routing
- live OCR calls from routing
- live paid-vision skip
- live duplicate reuse skip
- forced Sarvam
- Sarvam skip
- parser behavior changes
- duplicate reuse runtime behavior changes
- learning promotion
- Python-driven live routing

## Admin Review Panel Usage

The admin biodata intake detail page includes a compact Smart Routing Dry Run panel. Use it as a human-review aid only.

The panel is expected to show stored dry-run fields such as:

- `recommended_action`
- `would_skip_paid_vision`
- `would_call_paid_vision`
- main `reason_codes`
- `policy_enabled`
- `dry_run_only`
- `allowed_live_action`
- `blocked_reason`
- field-confidence severity
- critical and important low fields
- parser proposal outcome
- estimated paid vision avoidable status
- resolved-by-parser-proposal status
- ambiguous proposal status
- raw-evidence-absent fields
- safe admin next action

Safe admin next action labels are guidance for review workflow, not automatic actions:

- Review parser proposal
- Manual verify ambiguous value
- Provider/Sarvam candidate
- Duplicate/manual review candidate

The panel must not be used as final truth. Final truth remains the authorized human-approved snapshot.

## Redaction And Safety Rules

Reports and the admin panel must not print or expose:

- raw OCR text
- full phone numbers
- candidate names from parser proposals
- full addresses
- provider payloads
- API keys or secrets
- hash values

Allowed outputs are safe summaries such as IDs, action names, reason codes, yes/no flags, quality scores, field names, counts, buckets, and masked values where a command explicitly supports masking.

## Troubleshooting

### Missing Class After Deploy

Symptoms:

- `php artisan` reports a command class or service class is missing
- route or command discovery behaves differently on server than local

Steps:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan list | grep intake:
```

If the command is still missing, confirm the latest branch was pulled and the deployed files include the expected `app/Console/Commands` and `app/Services/Intake` files.

### Stale Routing JSON

Symptoms:

- reports disagree with recent code
- admin panel shows old reason codes
- acceptance report still shows old counts after a deploy

Steps:

```bash
php artisan intake:routing-dry-run-report --limit=500 --details
php artisan intake:routing-dry-run-refresh --limit=100 --all
php artisan intake:routing-acceptance-report --limit=500 --fail-on-risk --max-paid-calls=12 --max-skip-calls=0 --max-reuse-previous=0 --max-unknown=20
```

Only refresh when you intentionally want stored dry-run recommendations updated from existing stored data.

### Command Run On Local Vs Server

Local results can differ from server results because the intake rows, OCR attempts, stored quality fields, and routing JSON are database-specific.

When validating production safety, trust the server acceptance command run against the server database. Local runs are useful for code verification and focused tests.

### Missing Generated Health File Stash

If deploy scripts or server checks mention missing generated health files, first determine whether the file is source-controlled or generated at runtime.

- Source-controlled docs/config files should be restored from Git.
- Runtime-generated health artifacts should be regenerated by the owning command or deployment step.
- Do not stash or commit generated health files unless the project explicitly treats them as source artifacts.

## Phase 4B Prerequisites

Before any Phase 4B/live-routing work:

- Phase 4A acceptance report must pass on the server.
- Product owner must explicitly approve live-routing scope.
- Policy defaults must remain fail-closed.
- A rollback plan must exist before enabling any live action.
- Live Sarvam/OCR call paths must have explicit budget controls.
- Duplicate reuse must require trusted, verifiable evidence and identity overlap.
- Admin review visibility must remain available.
- Redaction rules must remain enforced in reports and UI.
- Any governed profile write must still pass through `MutationService` or the approved governed mutation layer.
- Final truth must remain the authorized human-approved snapshot.

## Python Data Engine Position

Python is intentionally postponed for this area. It may be useful later for offline analysis, regression datasets, quality calibration, report generation, and Phase 6 learning review.

Python must not drive live routing in Phase 4A because:

- current routing must remain dry-run only
- Laravel owns the stored routing and admin visibility surfaces
- provider calls must remain disabled unless explicitly approved
- governed profile truth is controlled by Laravel approval/mutation paths
- offline experiments should not change production intake decisions

