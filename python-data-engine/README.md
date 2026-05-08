# Python Data Engine

Independent **analyze vs fix** data cleaning toolkit for Laravel (or any PHP) projects. It talks to MySQL using the same env vars as Laravel and stays free of framework code.

## Folder layout

| Path | Purpose |
|------|---------|
| `scripts/` | CLI entrypoint, DB layer, config, logging |
| `scripts/modules/` | Pluggable checks (duplicates, validation, schema, logs, pincode) |
| `scripts/modules/explanations/` | Human-readable impact/recommendation layer |
| `scripts/modules/auto_fix/` | Generic recipe-driven safe fix orchestration |
| `scripts/modules/rollback/` | Row-level rollback manifests + execution |
| `scripts/modules/scoring/` | Module and platform health scoring |
| `scripts/modules/workflows/` | Admin workflow state transitions |
| `scripts/modules/recovery/` | Scheduler heartbeat / stale-run recovery checks |
| `config/recipes/` | Reusable project recipes (detect/fix/validate/rollback) |
| `data/` | Offline datasets (e.g. `india_pincode.csv`) |
| `output/reports/` | Timestamped JSON reports (every run) |
| `output/logs/` | Fix-session metadata + JSONL change logs |
| `output/admin_reports/` | Human diagnostics output (admin-readable) |
| `output/dashboard/` | API-ready dashboard JSON payloads |
| `output/workflows/` | Workflow state journal |
| `output/rollback/` | Rollback manifests |
| `output/health/` | Scheduler/self-healing heartbeat checks |

## Safety

- **Default mode is `analyze`** — read-only. Prints `DRY RUN — MODE=analyze (no database writes)`.
- **`fix` mode** — **UPDATE only**, wrapped in a transaction. **No DELETE** of rows.
- All outbound notifications from Laravel should stay separate; this engine only touches MySQL when `MODE=fix`.

## Requirements

- Python 3.10+
- MySQL / MariaDB reachable with Laravel credentials

Install dependencies:

```bash
cd python-data-engine
pip install -r requirements.txt
```

## Configuration

The engine loads **`../.env`** (Laravel project root) first, then **`python-data-engine/.env`** if present.

### Database (Laravel-compatible)

| Variable | Description |
|----------|-------------|
| `DB_HOST` | Default `127.0.0.1` |
| `DB_PORT` | Default `3306` |
| `DB_USERNAME` / `DB_USER` | MySQL user |
| `DB_PASSWORD` | Password |
| `DB_DATABASE` / `DB_NAME` | Schema name |

### Mode

| Variable | Values |
|----------|--------|
| `MODE` | `analyze` (default) or `fix` |

### Optional engine tuning (`ENGINE_*`)

Commonly used:

| Variable | Default | Meaning |
|----------|---------|---------|
| `ENGINE_USERS_TABLE` | `users` | User table for duplicate/validation |
| `ENGINE_USER_PHONE_COLUMN` | `mobile` | Phone column (10-digit check) |
| `ENGINE_SCHEMA_TABLES` | `users` | Comma-separated tables for null-ratio scan |
| `ENGINE_SCHEMA_NULL_THRESHOLD` | `0.9` | Flag columns with null ratio **above** this |
| `ENGINE_LARAVEL_LOG_PATH` | `../storage/logs/laravel.log` | Laravel log for pattern mining |
| `ENGINE_PINCODE_CSV` | `data/india_pincode.csv` | Offline pincode / lat-long source |
| `ENGINE_FIX_TABLE` | `profile_addresses` | Table for fix-mode pincode backfill |
| `ENGINE_FIX_PINCODE_COLUMN` | `pin_code` | Column to fill |
| `ENGINE_FIX_CITY_COLUMN` | `district` | City text to match against CSV |
| `ENGINE_FIX_LAT_COLUMN` / `ENGINE_FIX_LON_COLUMN` | *(empty)* | Set if your table has lat/lon columns |
| `ENGINE_MISMATCH_SQL` | *(empty)* | Custom SQL returning mismatch rows (recommended for non-trivial schemas) |

**Matrimony project example** (compare profile location vs address district via join):

```sql
SELECT mp.user_id AS user_id,
       mp.location AS left_city,
       pa.district AS right_city
FROM matrimony_profiles mp
INNER JOIN profile_addresses pa ON pa.profile_id = mp.id
WHERE mp.location IS NOT NULL AND TRIM(mp.location) <> ''
  AND pa.district IS NOT NULL AND TRIM(pa.district) <> ''
  AND LOWER(TRIM(mp.location)) <> LOWER(TRIM(pa.district))
```

Set in `.env`:

```env
ENGINE_MISMATCH_SQL=SELECT mp.user_id AS user_id, mp.location AS left_city, pa.district AS right_city FROM matrimony_profiles mp INNER JOIN profile_addresses pa ON pa.profile_id = mp.id WHERE mp.location IS NOT NULL AND TRIM(mp.location) <> '' AND pa.district IS NOT NULL AND TRIM(pa.district) <> '' AND LOWER(TRIM(mp.location)) <> LOWER(TRIM(pa.district))
```

(Use a single line or escape newlines as appropriate for your shell.)

## How to run

From **`python-data-engine/`** (recommended):

```bash
MODE=analyze python3 scripts/runner.py
MODE=fix python3 scripts/runner.py
```

From **repository root**:

```bash
MODE=analyze python3 python-data-engine/scripts/runner.py
```

### Windows (cmd)

```cmd
set MODE=analyze
python python-data-engine\scripts\runner.py
```

### Windows (PowerShell)

```powershell
$env:MODE="analyze"
python python-data-engine\scripts\runner.py
```

Each run writes `output/reports/engine_<mode>_YYYYMMDD_HHMMSS.json`.

Exit code `0` on success; non-zero if the runner crashes (report is still written with `runner_error` in `meta`).

## Ops hardening commands (new)

```bash
# Build human-readable admin diagnostics + dashboard payload
python scripts/runner.py ops-dashboard

# Quarantine invalid historical snapshots (safe: keeps eligible snapshots)
python scripts/runner.py snapshot-quarantine --dry-run --retention-days 30

# End-to-end parity and governance integrity checks
python scripts/runner.py parity-validate --profile 207
python scripts/runner.py relation-integrity
python scripts/runner.py api-drift-root-cause
python scripts/runner.py governance-regression
python scripts/runner.py governance-timeline
python scripts/runner.py bulk-governance --limit 100

# Dry-run preview using a reusable recipe
python scripts/runner.py auto-fix --recipe duplicate_profiles --preview-limit 25

# Execute safe pipeline (backup, validate, rollback on failure)
python scripts/runner.py auto-fix --recipe stale_indexes --execute

# Self-healing scheduler heartbeat / stale-run check
python scripts/runner.py self-heal-check
```

## Report JSON shape

```json
{
  "meta": {
    "mode": "analyze",
    "database": "...",
    "generated_at": "...",
    "batch_size": 1000,
    "engine_version": "1.0.0",
    "run_mode": "analyze",
    "timestamp": "...",
    "execution_time": "0.843102",
    "hash": "..."
  },
  "duplicates": [],
  "validation_errors": [],
  "mismatch": [],
  "schema_issues": [],
  "log_errors": [],
  "fix_results": {
    "pincode_fixed": 0,
    "latlong_fixed": 0,
    "normalized": 0,
    "skipped": 0
  },
  "fixes_applied": [],
  "suggestions": [],
  "anomalies": [],
  "backup_path": null
}
```

- **`log_errors`**: structured entries (pattern counts + sampled Laravel error lines).
- **`fix_results`**: strict counters for safe observability.
- **`fixes_applied`**: simplified action summary for dashboards.
- **`anomalies`**: safety warnings (for example duplicate spike).

## Laravel integration

Call the engine from PHP only after **auth / policy checks** (admin-only).

### Minimal sample (Unix-style env)

```php
public function runEngine(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
{
    $mode = $request->mode ?? 'analyze';
    $command = "MODE={$mode} python3 python-data-engine/scripts/runner.py";
    $output = shell_exec($command);

    return response()->json([
        'status' => 'success',
        'output' => $output,
    ]);
}
```

Validate `$mode`, use absolute paths, and avoid interpolating raw request input into the shell string without an allow-list.

### Safer variant (cwd + escaped paths)

```php
public function runEngine(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
{
    $mode = $request->input('mode', 'analyze');
    $mode = in_array($mode, ['analyze', 'fix'], true) ? $mode : 'analyze';

    $base = base_path('python-data-engine');
    $script = $base . '/scripts/runner.py';
    $python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';

    $cmd = sprintf(
        'cd %s && MODE=%s %s %s 2>&1',
        escapeshellarg($base),
        escapeshellarg($mode),
        escapeshellarg($python),
        escapeshellarg('scripts/runner.py')
    );

    $output = shell_exec($cmd);

    return response()->json([
        'status' => 'success',
        'mode' => $mode,
        'output' => $output,
    ]);
}
```

### Windows note

`MODE=...` is not valid in `cmd`. Prefer `putenv('MODE=' . $mode)` **before** `shell_exec`, or use `set MODE=analyze && python ...`. [`Symfony Process`](https://symfony.com/doc/current/components/process.html) with env vars is more reliable cross-platform.

Always validate `$mode` server-side; never pass raw user input into the shell unescaped.

## Admin panel settings checklist

`/admin/data-engine` page gives two actions:

1. **Run analyze** (safe default)
   - No DB mutations
   - Generates full report + metrics
2. **Run fix** (transactional UPDATE mode)
   - Requires confirm dialog in UI
   - Uses backup + capped updates

Recommended `.env` settings in Laravel root:

```env
DATA_ENGINE_DRIVER=cli
DATA_ENGINE_PYTHON=python
DATA_ENGINE_TIMEOUT=240

ENGINE_BATCH_SIZE=1000
ENGINE_FIX_ENABLED=true
ENGINE_FIX_MAX_UPDATES_PER_RUN=5000
ENGINE_PROFILE_ANALYSIS_LIMIT=100
```

Recommended Python engine settings (`ENGINE_*`) by schema:

```env
ENGINE_USERS_TABLE=users
ENGINE_USER_ID_COLUMN=id
ENGINE_USER_PHONE_COLUMN=mobile
ENGINE_USER_EMAIL_COLUMN=email
ENGINE_USER_NAME_COLUMN=name

ENGINE_FIX_TABLE=profile_addresses
ENGINE_FIX_ID_COLUMN=id
ENGINE_FIX_PINCODE_COLUMN=pin_code
ENGINE_FIX_CITY_COLUMN=district
ENGINE_FIX_LAT_COLUMN=latitude
ENGINE_FIX_LON_COLUMN=longitude
```

## Admin panel UX

Recommended controls:

1. **Analyze only** — POST `mode=analyze` — safe read-only scan; show latest JSON report path / summary.
2. **Fix data** — POST `mode=fix` — runs transactional UPDATE rules; show change log + require confirmation modal warning about writes.

## Offline dataset

`data/india_pincode.csv` ships with a **small** illustrative extract (`pincode`, `city`, `state`, `latitude`, `longitude`). Replace or extend with your full dataset for production matching coverage.

## License / reuse

Copy the entire `python-data-engine/` directory into other Laravel apps; point `DB_*` at the target database and adjust `ENGINE_*` columns to match that schema.
