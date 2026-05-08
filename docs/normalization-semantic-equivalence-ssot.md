# Normalization & Semantic Equivalence SSOT

## Scope

This document defines deterministic semantic normalization for snapshot comparison in the Data Governance Platform.

Constraints:
- No AI inference
- No snapshot schema changes
- No comparison contract redesign
- Deterministic, explainable, low-noise behavior only

## Normalization Architecture

Comparison remains DB vs API vs rendered, with an additional deterministic semantic layer:

1. Raw comparison (`exact_match`, `normalized_match`, existing mismatch types)
2. Semantic equivalence pass (only when cross-layer mismatch is detected)
3. Reclassification to `semantic_equivalent` when deterministic resolver confirms equivalence

Implementation modules:
- `DateNormalizer`
- `RelationLabelResolver`
- `LocationNormalizer`
- `SemanticNormalizationEngine` (orchestration)

## Equivalence Categories

Supported deterministic categories:

1. **Date format equivalence**
   - Example: `1982-02-09` == `9 Feb 1982` == `09/02/1982`
2. **Relation ID-to-label equivalence**
   - Example: `caste_id: 412` == `Maratha`
   - Example: `religion_id: 4` == `Hindu`
3. **Location ID-to-rendered-location equivalence**
   - Example: `location_id: 37648` == `Kadegaon, Sangli 415304`

## Deterministic Rules

### Date Rules
- Strict accepted input formats only:
  - `YYYY-MM-DD`
  - `DD/MM/YYYY`
  - `D Mon YYYY` or `D Month YYYY` (English month names only)
- Normalize to ISO `YYYY-MM-DD`.
- Invalid dates fail normalization (no fuzzy parsing).

### Relation Label Rules
- DB lookup tables are explicit by field:
  - `caste -> castes.label`
  - `religion -> religions.label`
  - `marital_status -> master_marital_statuses.label`
  - `gender -> master_genders.label`
  - `mother_tongue -> master_mother_tongues.label`
- IDs must be numeric and exact.
- Labels are normalized by lowercase + whitespace compression.

### Location Rules
- Treat DB value as `addresses.id`.
- Build deterministic hierarchy chain through `addresses.parent_id`.
- Generate allowed rendered variants (normalized):
  - `leaf`
  - `leaf district`
  - `leaf district pincode` (if available)
- Match rendered text against generated variants after punctuation/whitespace normalization.

## Resolver Hierarchy

Execution order in semantic pass:

1. Date resolver (for `date_of_birth`)
2. Relation label resolver (for relation-backed fields)
3. Location resolver (for `city` / `location_id` derived display)

First positive deterministic match marks the row as `semantic_equivalent`.

## Failure Handling

- Resolver failures must never crash comparison.
- DB connectivity issues gracefully fall back to existing non-semantic comparison behavior.
- Unknown field/table/ID values return `not equivalent` without side effects.
- Contract remains stable; unresolved rows remain `cross_layer_inconsistency`.

## Future AI Boundaries

Out of scope (must not be added in this phase):
- Fuzzy string matching
- Confidence/probability scoring
- LLM-based interpretation
- Auto-correction suggestions
- Heuristic guessing for unknown mappings

Future AI phase may only build on top of deterministic outputs, never replace this baseline.
