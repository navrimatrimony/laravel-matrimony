# Intake Golden Dataset Private Curation Runbook

## Purpose

Phase 6C.2 prepares a safe operator workflow for manually curating a private OCR regression dataset through a CSV form and converting it to JSONL. It keeps parser scoring separate from the final profile target snapshot. This does not enable learning, live routing, Sarvam, OCR, paid vision, backfill, or any profile mutation.

The committed synthetic fixture is only for system verification:

```powershell
php artisan intake:ocr-regression --dataset=tests/Fixtures/Intake/golden_dataset_minimal.jsonl
```

It is fake data and should not be treated as a real accuracy baseline.

## Private Dataset Location

The real golden dataset must stay private under:

```text
storage/app/intake-golden-datasets/golden-curation-template.csv
storage/app/intake-golden-datasets/golden.jsonl
```

Do not commit real OCR text, images, names, phone numbers, full addresses, image hashes, provider payloads, profile snapshots, source context, CSV files, JSONL files, or personal data to git. The repository `.gitignore` blocks this private dataset directory, but operators should still check `git status --short` before committing any Phase 6 work.

Do not send real biodata data to Codex, chat tools, tickets, public docs, or commits. Keep real curation files private on the server/local machine under `storage/app/intake-golden-datasets`.

## CSV Workflow for Operators

Use CSV when manual JSONL writing is difficult.

Step 1: create a private CSV template:

```powershell
php artisan intake:golden-dataset-csv-template
```

Create a smaller template for trial curation:

```powershell
php artisan intake:golden-dataset-csv-template --rows=5 --output=storage/app/intake-golden-datasets/golden-curation-template.csv
```

Step 2: fill the CSV privately in Excel or a spreadsheet editor.

- Fill `case_id`, `layout_type`, `language`, and `ocr_text`.
- Fill `parser_*` columns only with values visible in the OCR text.
- Fill `profile_*`, address, family, relatives, property, and expectations columns as the final app/profile answer key.
- Fill `source_*` columns to record consent/source/contact-rule notes.
- Keep the CSV under `storage/app/intake-golden-datasets`.
- Do not commit the CSV.
- Do not paste real biodata data into Codex.

Step 3: convert the private CSV to JSONL:

```powershell
php artisan intake:golden-dataset-csv-to-jsonl --csv=storage/app/intake-golden-datasets/golden-curation-template.csv --output=storage/app/intake-golden-datasets/golden.jsonl
```

Overwrite intentionally only after checking the file:

```powershell
php artisan intake:golden-dataset-csv-to-jsonl --csv=storage/app/intake-golden-datasets/golden-curation-template.csv --output=storage/app/intake-golden-datasets/golden.jsonl --force
```

Step 4: run regression:

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl
```

The template and converter commands only read/write private files under `storage/app/intake-golden-datasets`. They do not read `biodata_intakes`, update the database, call OCR, call Sarvam, call paid vision, create learning rules, promote learning, backfill data, or change routing.

## Start With Manual Curation

Start with 20-50 manually reviewed private cases. Use only cases where the parser expected fields are verified against visible OCR text, and the final profile target is taken from an authorized human-reviewed/approved source. Sarvam, OCR, and parser output can be evidence, but they are not final truth.

For non-technical curation, prefer the CSV workflow above. JSONL can still be edited manually by technical operators when needed.

One case goes on each JSONL line. JSONL does not allow comments, so each line must be valid JSON.

Required case keys:

```text
case_id
layout_type
language
ocr_text
```

The case must include one parser scoring field box:

```text
parser_expected_fields
```

Legacy datasets may still use:

```text
expected_fields
```

If `parser_expected_fields` exists, `intake:ocr-regression` uses it for scoring. Otherwise it falls back to `expected_fields`.

Optional private target/context boxes:

```text
expected_profile_snapshot
source_context
```

Example shape with fake placeholder data:

```json
{"case_id":"private_case_001","layout_type":"single_column","language":"mr-en","ocr_text":"PRIVATE OCR TEXT HERE","parser_expected_fields":{"full_name":"PRIVATE OCR-VISIBLE EXPECTED VALUE","date_of_birth":"1996-04-12"},"expected_profile_snapshot":{"core":{"primary_contact_number":"PRIVATE SOURCE PRIMARY CONTACT"},"contacts":[{"type":"document_contact","number":"PRIVATE DOCUMENT CONTACT","is_primary":false}],"addresses":[{"type":"residence","address_line":"PRIVATE FULL ADDRESS"}],"family":{}},"source_context":{"primary_contact_source":"communication_or_consent","consent_source":"PRIVATE SOURCE LABEL"}}
```

## Two-box Model

Use two separate answer keys:

- `parser_expected_fields` is the OCR/parser exam. Only include fields visible in the document OCR text. Regression scoring compares only this box.
- `expected_profile_snapshot` is the final app/profile answer key. It may include source-derived fields that OCR cannot extract, such as source primary contact, addresses, family, relatives, property, and other full profile target sections.
- `source_context` records who sent, consented, or created the profile and where the primary contact authority came from.

In the CSV:

- `parser_*` columns become `parser_expected_fields`.
- `profile_*`, address, family, relatives, property, and expectations columns become `expected_profile_snapshot`.
- `source_*` columns become `source_context`.
- `parser_document_contact_number` is the OCR-visible document contact. It is not the live profile primary contact rule.

The regression command accepts `expected_profile_snapshot` and `source_context` but does not score them yet. It does not print raw values from either box. JSON and console output expose only safe metadata:

- `profile_snapshot_present`
- `profile_snapshot_sections`
- `address_count`
- `contact_count`
- `family_section_present`
- `source_context_present`
- `source_context_keys`

## Primary Contact Rule

The live profile `primary_contact_number` is the number from which communication, consent, or profile creation happened, unless the user explicitly marks another number as primary.

A phone number printed inside a biodata document is document evidence. Store it as `document_contact` or source evidence in the final target snapshot unless the user explicitly marks it primary. Do not force a printed biodata number into live profile primary contact during parser regression.

OCR/parser regression should only score fields visible in OCR text. A final profile snapshot may contain source-derived fields that the parser cannot and should not extract from OCR.

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

The scaffold is a clean synthetic sanity check for the current deterministic parser. Its `parser_expected_fields` intentionally include only values that the parser extracts reliably from the fake examples, so the generated `golden.example.jsonl` should pass regression at 100% accuracy. Its `expected_profile_snapshot` and `source_context` values are fake and exist only to show the private dataset format.

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

For targeted deterministic parser improvements, run the relevant private field slice, for example:

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --field=height
```

Private regression signals can guide narrow parser fixes such as height extraction, but private biodata data must remain outside git, and learning promotion, learning rules, backfills, live OCR, Sarvam, and paid vision remain disabled.

Fail CI/operator check below a threshold:

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --fail-under=85
```

## Regression threshold guard

Use the threshold guard after each deterministic parser change to prevent silent accuracy regression against the private golden dataset:

```powershell
php artisan intake:ocr-regression --dataset=storage/app/intake-golden-datasets/golden.jsonl --fail-under=91 --fail-under-field=full_name:85 --fail-under-field=date_of_birth:95 --fail-under-field=height:94 --fail-under-field=education:95 --fail-under-field=occupation:66 --fail-under-field=document_contact_number:100 --fail-under-field=address:88 --fail-under-field=religion:83 --fail-under-field=caste:100 --fail-under-field=sub_caste:100
```

This guard is read-only. It only compares offline parser output with `parser_expected_fields`; it does not enable learning, create learning rules, apply profiles, mutate `biodata_intakes`, backfill data, call OCR, call Sarvam, call paid vision, or change routing.

The guard can fail on either the overall `--fail-under` threshold or a repeatable `--fail-under-field=field:percent` threshold. A failure means the parser change should be reviewed before proceeding because a private baseline dropped.

The real golden dataset remains private under `storage/app/intake-golden-datasets` and must never be committed.

## Interpretation

Regression output is an offline safety signal only. A pass means the current parser matched `parser_expected_fields` in the private dataset at the configured threshold.

A fail means the parser behavior should be reviewed before future learning or routing work, but this command does not change the parser or production data.

Real private datasets will contain harder layouts, noisier OCR, mixed languages, and fields that are not covered by the scaffold. Lower accuracy on a real private dataset is a parser-improvement signal, not approval to promote learning, create learning rules, backfill data, or change live routing.

Learning promotion remains disabled after validation. Python data engine analysis remains offline/later only and is not integrated into live routing or learning in this phase.
