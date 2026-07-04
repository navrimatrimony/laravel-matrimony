# Intake Golden Dataset Private Curation Runbook

## Purpose

Phase 6B prepares a safe operator workflow for manually curating a private OCR regression dataset. This does not enable learning, live routing, Sarvam, OCR, paid vision, or any profile mutation.

The committed synthetic fixture is only for system verification:

```powershell
php artisan intake:ocr-regression --dataset=tests/Fixtures/Intake/golden_dataset_minimal.jsonl
```

It is fake data and should not be treated as a real accuracy baseline.

## Private Dataset Location

The real golden dataset must stay private under:

```text
storage/app/intake-golden-datasets/golden.jsonl
```

Do not commit real OCR text, images, names, phone numbers, full addresses, image hashes, provider payloads, or personal data to git. The repository `.gitignore` blocks this private dataset directory, but operators should still check `git status --short` before committing any Phase 6 work.

## Start With Manual Curation

Start with 20-50 manually reviewed private cases. Use only cases where the final expected fields are taken from an authorized human-reviewed/approved snapshot. Sarvam, OCR, and parser output can be evidence, but they are not final truth.

One case goes on each JSONL line. JSONL does not allow comments, so each line must be valid JSON.

Required keys:

```text
case_id
layout_type
language
ocr_text
expected_fields
```

Example shape:

```json
{"case_id":"private_case_001","layout_type":"single_column","language":"mr-en","ocr_text":"PRIVATE OCR TEXT HERE","expected_fields":{"full_name":"PRIVATE EXPECTED VALUE","date_of_birth":"1996-04-12"}}
```

## Scaffold

Create a synthetic private scaffold file under `storage/app/intake-golden-datasets`:

```powershell
php artisan intake:golden-dataset-scaffold
```

Overwrite the scaffold intentionally:

```powershell
php artisan intake:golden-dataset-scaffold --force
```

Write to a specific private file:

```powershell
php artisan intake:golden-dataset-scaffold --output=storage/app/intake-golden-datasets/golden.example.jsonl
```

The scaffold command only writes fake synthetic examples. It does not read `biodata_intakes`, update the database, call OCR, call Sarvam, or create learning rules.

The scaffold is a clean synthetic sanity check for the current deterministic parser. Its expected fields intentionally include only values that the parser extracts reliably from the fake examples, so the generated `golden.example.jsonl` should pass regression at 100% accuracy.

## Private Regression

Run the real private dataset:

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl
```

Run JSON output:

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --json
```

Run one field:

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --field=primary_contact_number
```

Fail CI/operator check below a threshold:

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --fail-under=85
```

## Interpretation

Regression output is an offline safety signal only. A pass means the current parser matched the expected fields in the private dataset at the configured threshold.

A fail means the parser behavior should be reviewed before future learning or routing work, but this command does not change the parser or production data.

Real private datasets will contain harder layouts, noisier OCR, mixed languages, and fields that are not covered by the scaffold. Lower accuracy on a real private dataset is a parser-improvement signal, not approval to promote learning, create learning rules, backfill data, or change live routing.

Learning promotion remains disabled after validation. Python data engine analysis remains offline/later only and is not integrated into live routing or learning in this phase.
