# Full Field Inventory SSOT

This document is the authoritative source for governance field coverage.

## Scope

- Wizard schema fields (dynamic discovery from Blade inputs/components)
- Database snapshot fields
- Repeater and nested fields
- API payload field paths
- Public profile rendered field paths

## Generation Contract

- Generator: `python-data-engine/scripts/governance/generate_full_field_inventory.py`
- Output: `python-data-engine/output/governance/full_field_inventory.json`
- Command: `php artisan governance:generate-field-inventory`

## Per-Field Contract

Each field path maps to:

- Source of truth (`wizard`, `db`, `api`, `rendered`)
- Storage table / relation (when known)
- API path (`api.profile.*` path where available)
- Public profile path (`rendered.fields.*`)
- Repeater status (`scalar` / `repeater` / `nested`)
- Normalization status (`supported` / `partial` / `unsupported`)
- Comparison support status (`full` / `partial` / `unsupported`)

## Runtime Truth Rules

- No hard-coded field inventory as source of truth.
- All field inventory is dynamically generated.
- Runtime report values supersede implementation assumptions.
- Unsupported/newly detected fields reduce coverage score until governed.

