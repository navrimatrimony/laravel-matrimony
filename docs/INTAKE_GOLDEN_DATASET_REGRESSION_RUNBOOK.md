# Intake Golden Dataset Regression Runbook

## Purpose

Phase 6A starts an offline regression foundation for biodata OCR parsing. The command compares stored OCR text fixtures in a manually curated golden dataset against deterministic parser output.

This is regression reporting only. It does not create learning rules, promote learning, enable smart routing, or affect live intake behavior.

## Safety Boundaries

- No OCR is performed.
- No Sarvam call is made.
- No paid vision or external provider is called.
- No `biodata_intakes` rows are read or written.
- No profiles, OCR attempts, routing JSON, quality JSON, or review snapshots are mutated.
- Sarvam remains a teacher/evidence signal only.
- Final truth remains an authorized human-reviewed/approved snapshot.
- Learning promotion remains disabled regardless of regression result.

## Command

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl
```

Useful options:

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --json
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --field=primary_contact_number
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --limit=500 --fail-under=85
```

## Dataset Location

Place the private golden dataset on the server or local machine under:

```text
storage/app/intake-golden-datasets/golden.jsonl
```

The command also accepts an absolute local path for operator-only use.

Do not commit real biodata text, real images, full phone numbers, addresses, names, image hashes, or personal data to git. The repository test fixture uses fake synthetic biodata text only.

## Dataset Format

JSONL is preferred. Each line is one case:

```json
{"case_id":"synthetic_case_001","layout_type":"single_column","language":"en","ocr_text":"Name: Synthetic Alpha\nDate of Birth: 12/04/1996","expected_fields":{"full_name":"Synthetic Alpha","date_of_birth":"1996-04-12"}}
```

Required case keys:

- `case_id`
- `layout_type`
- `language`
- `ocr_text`
- `expected_fields`

Supported `expected_fields` keys:

- `full_name`
- `date_of_birth`
- `height`
- `education`
- `occupation`
- `primary_contact_number`
- `address`
- `religion`
- `caste`

Optional metadata keys:

- `notes`
- `source_label`
- `image_hash`
- `expected_snapshot`

## Output

The report includes:

- total, valid, and invalid case counts
- total expected fields
- exact matches, mismatches, and missing parsed fields
- overall accuracy percentage
- per-field accuracy
- per-layout accuracy
- safe row summaries
- schema errors

Rows show only safe identifiers and field names. They do not print raw OCR text, phone numbers, full addresses, candidate names, provider payloads, or full parser payloads.

## Status Meanings

- `no_dataset`: dataset option/path is missing or not found.
- `invalid_dataset`: one or more rows failed schema or JSON validation, or no valid cases were available.
- `pass`: dataset was valid and the command completed. Accuracy still needs human review.
- `fail_under_threshold`: dataset was valid but `overall_accuracy_percent` was below `--fail-under`.

Mismatches do not mutate anything. They identify parser regression candidates for future offline work.

## Local Run

```powershell
cd E:\LaravelProjects\laravel-matrimony
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --json
```

## Server Run

```bash
cd /home/navri/htdocs/navrimilenavryala.com
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --fail-under=85
```

## Interpretation

High accuracy means the deterministic parser currently matches the curated expected fields for that dataset. It does not authorize learning promotion or live routing changes.

Low accuracy, missing fields, or layout-specific failures should be reviewed offline. If a parser improvement is later proposed, it should be tested against this command before any runtime parser behavior changes are considered.

## Privacy Rules

- Keep real golden datasets private in `storage/app`.
- Do not commit real OCR text or personal data.
- Do not commit real biodata images.
- Do not use real phone numbers or full addresses in repository fixtures.
- Use synthetic test fixtures only under `tests/Fixtures`.

## Learning Boundary

This command is not a learning promotion gate. Phase 5 learning readiness remains blocked until authorized human provenance and safe learning candidate requirements are met.
