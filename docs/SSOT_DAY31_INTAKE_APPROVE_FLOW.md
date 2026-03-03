# SSOT Day-31: POST /intake/approve/{id} — Exact Flow & Test Cases

## 1) Flow when POST /intake/approve/{id} runs

### Entry
- **Route:** `POST /intake/approve/{intake}` → `IntakeController::approve()`
- **Gates:** `session('preview_seen_' . $intake->id)` must be set; else 403.
- **Snapshot:** From `$request->input('snapshot')` merged over `$intake->parsed_json`, then normalized via `normalizeApprovalSnapshot()`.
- **Call:** `IntakeApprovalService::approve($intake, auth()->id(), $snapshot)`.

### Inside IntakeApprovalService::approve()

| Condition | Effect |
|-----------|--------|
| `intake->intake_locked === true` | Early return; no DB writes; `applyApprovedIntake` not called. |
| `parse_status !== 'parsed'` | Throws `RuntimeException`. |
| `approved_by_user === true` and not yet applied | Skips approval block; calls `MutationService::applyApprovedIntake($intake->id)` only. No correction logs/patterns written. |

**When approval block runs (first-time approve):**

1. **Comparison:** For each key in `$approvalSnapshot['core']`, compare with `$intake->parsed_json['core']` using `normalizeForComparison()` (trim, null/array→string).

2. **Per-field diff:**
   - If `normalizedOld !== normalizedNew`:
     - **ocr_correction_logs:** One row inserted: `intake_id`, `field_key`, `original_value`, `corrected_value` (no `corrected_by`; that lives in archive).
     - **ocr_correction_logs_actor_archive:** One row inserted: `ocr_correction_log_id` = the new log id, `corrected_by` = `$userId`, `created_at`.
     - **strengthenPatternIfThreshold($field, $normalizedOld, $normalizedNew)** is called (see section 2).
   - If equal: no log, no archive, no pattern logic.

3. **biodata_intakes:** Always updated in the same transaction:
   - `approved_by_user = true`
   - `approved_at = now()`
   - `approval_snapshot_json = $approvalSnapshot`
   - `snapshot_schema_version = 1`
   - `intake_status = 'approved'`

4. **After commit:** `MutationService::applyApprovedIntake($intake->id)` is called (profile/contacts/children/education/career/addresses/property/horoscope/legal/preferences/narrative mutation). That path does **not** write to `ocr_correction_logs`, `ocr_correction_logs_actor_archive`, or `ocr_correction_patterns`.

### Tables written (approval path only)

| Table | When |
|-------|------|
| **ocr_correction_logs** | For each core field where approved value ≠ parsed value; one row per such field. |
| **ocr_correction_logs_actor_archive** | Once per new correction log row; stores `corrected_by` (user id). |
| **ocr_correction_patterns** | Only inside `strengthenPatternIfThreshold`, when count ≥ 5 and (bump or create path). |
| **ocr_pattern_conflicts** | Only inside `strengthenPatternIfThreshold`, when count ≥ 5, existing pattern exists for same `field_key` + `wrong_pattern` but with **different** `corrected_value` (conflict path). |
| **biodata_intakes** | Always (approved_by_user, approved_at, approval_snapshot_json, snapshot_schema_version, intake_status). |

**Not written by approve:** `admin_audit_logs` (that is for Admin OCR pattern toggle only).

---

## 2) strengthenPatternIfThreshold — threshold and rules

**Location:** `IntakeApprovalService::strengthenPatternIfThreshold(string $fieldKey, ?string $originalValue, ?string $correctedValue)`.

**Threshold:** **5**. The code counts how many rows in `ocr_correction_logs` have:
- `field_key` = `$fieldKey`
- `original_value` = `$originalValue`
- `corrected_value` = `$correctedValue`

This count **includes the log row just inserted** in the same request. If `count < 5`, the method returns and **no pattern or conflict row** is written.

**Rules when count >= 5:**

| Case | Condition | Action |
|------|-----------|--------|
| **Skip** | `$originalValue === null` or `$correctedValue === null` | Return; no write. |
| **Skip** | Count < 5 | Return; no write. |
| **Bump** | Count ≥ 5 and there exists a row in `ocr_correction_patterns` with `field_key` = `$fieldKey`, `wrong_pattern` = `$originalValue`, `source` = `'frequency_rule'` and that row’s `corrected_value` **equals** `$correctedValue` | **Update** that pattern: `usage_count` = `$count`, `pattern_confidence` = 0.80, `is_active` = true, `updated_at` = now(). No new row. |
| **Conflict** | Count ≥ 5 and there exists such a pattern but its `corrected_value` **is not equal** to `$correctedValue` | **Do not** update the pattern. Insert or update **ocr_pattern_conflicts**: `field_key`, `wrong_pattern`, `proposed_corrected_value` = `$correctedValue`, `existing_corrected_value` = existing pattern’s corrected_value, `observation_count` = max(current, `$count`). No new pattern row. |
| **Create** | Count ≥ 5 and there is **no** pattern with same `field_key` + `wrong_pattern` + `source = 'frequency_rule'` | Run `OcrNormalize::sanityCheckLearnedValue($fieldKey, $correctedValue)`. **Insert** one row in `ocr_correction_patterns`: `field_key`, `wrong_pattern`, `corrected_value`, `source` = `'frequency_rule'`, `usage_count` = `$count`, `pattern_confidence` = 0.80, `is_active` = result of sanity check, `updated_at` = now(). |

---

## 3) Three concrete test cases and DB queries to verify

### Test case A: Should NOT create a new pattern row (only logs)

**Setup:** Same correction (field_key, original_value, corrected_value) has been seen **fewer than 5 times** in total (e.g. first approval that changes one field, or 2nd/3rd/4th time).

**Example:** Intake with `parsed_json.core.caste` = `"मटाठा"`. User approves with `caste` = `"मराठा"`. Assume this is the **first** time this exact correction (मटाठा → मराठा) appears in logs.

**Expected:**
- One new row in `ocr_correction_logs`.
- One new row in `ocr_correction_logs_actor_archive`.
- **No** new row in `ocr_correction_patterns`.
- **No** new row in `ocr_pattern_conflicts`.

**Queries to verify (after one such approval):**

```sql
-- Should be 1 new log for this intake + field
SELECT id, intake_id, field_key, original_value, corrected_value
FROM ocr_correction_logs
WHERE intake_id = :intake_id AND field_key = 'caste';

-- Should be 1 archive row for that log
SELECT ocl.id AS log_id, a.ocr_correction_log_id, a.corrected_by
FROM ocr_correction_logs ocl
JOIN ocr_correction_logs_actor_archive a ON a.ocr_correction_log_id = ocl.id
WHERE ocl.intake_id = :intake_id AND ocl.field_key = 'caste';

-- Count for (caste, मटाठा, मराठा) should be 1 (or 2, 3, 4) — strictly < 5
SELECT COUNT(*) AS cnt FROM ocr_correction_logs
WHERE field_key = 'caste' AND original_value = 'मटाठा' AND corrected_value = 'मराठा';

-- No pattern for this wrong_pattern yet (or zero rows)
SELECT * FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
-- Expect: 0 rows (so no pattern created).
```

---

### Test case B: Should bump usage_count on existing pattern

**Setup:**
- There is already a pattern: `field_key` = `'caste'`, `wrong_pattern` = `'मटाठा'`, `corrected_value` = `'मराठा'`, `source` = `'frequency_rule'`, `usage_count` = 5 (or any value).
- One more approval is done that corrects caste मटाठा → मराठा, so that the **total** count of logs with (caste, मटाठा, मराठा) becomes **6** (or more).

**Expected:**
- One new row in `ocr_correction_logs`.
- One new row in `ocr_correction_logs_actor_archive`.
- **No** new row in `ocr_correction_patterns`.
- **One** existing pattern row **updated**: `usage_count` set to the new count (e.g. 6), `pattern_confidence` = 0.80, `is_active` = true.

**Queries to verify (before approval):**

```sql
-- Existing pattern
SELECT id, field_key, wrong_pattern, corrected_value, usage_count, pattern_confidence, is_active, updated_at
FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
-- Expect: 1 row, e.g. usage_count = 5, corrected_value = 'मराठा'.

-- Current log count for this correction
SELECT COUNT(*) FROM ocr_correction_logs
WHERE field_key = 'caste' AND original_value = 'मटाठा' AND corrected_value = 'मराठा';
-- Expect: 5.
```

**Queries to verify (after one more approval with caste मटाठा → मराठा):**

```sql
-- New log and archive (same as in A)
SELECT id, intake_id, field_key, original_value, corrected_value FROM ocr_correction_logs
WHERE intake_id = :intake_id AND field_key = 'caste';

-- Count now 6
SELECT COUNT(*) AS cnt FROM ocr_correction_logs
WHERE field_key = 'caste' AND original_value = 'मटाठा' AND corrected_value = 'मराठा';
-- Expect: 6.

-- Same single pattern row, usage_count updated to 6, updated_at changed
SELECT id, field_key, wrong_pattern, corrected_value, usage_count, pattern_confidence, is_active, updated_at
FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
-- Expect: 1 row, usage_count = 6, pattern_confidence = 0.80, is_active = 1.

-- No new pattern rows (total count unchanged)
SELECT COUNT(*) FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा';
-- Expect: 1.
```

---

### Test case C: Should create a new pattern row

**Setup:**
- There is **no** row in `ocr_correction_patterns` with `field_key` = `'caste'`, `wrong_pattern` = `'मटाठा'`, `source` = `'frequency_rule'`.
- There are **at least 5** rows in `ocr_correction_logs` with `field_key` = `'caste'`, `original_value` = `'मटाठा'`, `corrected_value` = `'मराठा'` (e.g. four from past intakes + one from the current approval).

**Expected:**
- One new row in `ocr_correction_logs` (current approval).
- One new row in `ocr_correction_logs_actor_archive`.
- **One new** row in `ocr_correction_patterns`: `field_key` = `'caste'`, `wrong_pattern` = `'मटाठा'`, `corrected_value` = `'मराठा'`, `source` = `'frequency_rule'`, `usage_count` = 5, `pattern_confidence` = 0.80, `is_active` = result of `OcrNormalize::sanityCheckLearnedValue('caste', 'मराठा')` (true for मराठा).

**Queries to verify (before approval that will bring count to 5):**

```sql
-- No pattern yet
SELECT * FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
-- Expect: 0 rows.

-- Exactly 4 logs so far for this correction (so next approval will make 5)
SELECT COUNT(*) FROM ocr_correction_logs
WHERE field_key = 'caste' AND original_value = 'मटाठा' AND corrected_value = 'मराठा';
-- Expect: 4.
```

**Queries to verify (after the 5th such approval):**

```sql
-- Now 5 logs
SELECT COUNT(*) AS cnt FROM ocr_correction_logs
WHERE field_key = 'caste' AND original_value = 'मटाठा' AND corrected_value = 'मराठा';
-- Expect: 5.

-- One new pattern row created
SELECT id, field_key, wrong_pattern, corrected_value, source, usage_count, pattern_confidence, is_active, created_at, updated_at
FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
-- Expect: 1 row, usage_count = 5, pattern_confidence = 0.80, source = 'frequency_rule'.
-- is_active: 1 if sanityCheckLearnedValue('caste','मराठा') is true, else 0.

-- No conflict row for this (we created new pattern, not conflict)
SELECT * FROM ocr_pattern_conflicts
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा';
-- Expect: 0 rows (unless a conflict was created in a different scenario).
```

---

## Summary

| What | Condition | Tables touched |
|------|-----------|----------------|
| Correction log + archive | approved core value ≠ parsed core value | `ocr_correction_logs`, `ocr_correction_logs_actor_archive` |
| Bump pattern | count ≥ 5, pattern exists with same corrected_value | `ocr_correction_patterns` (update one row) |
| Conflict | count ≥ 5, pattern exists with different corrected_value | `ocr_pattern_conflicts` (insert/update) |
| Create pattern | count ≥ 5, no pattern for (field_key, wrong_pattern, frequency_rule) | `ocr_correction_patterns` (insert one row) |
| Intake update | every successful approval | `biodata_intakes` |

Threshold for any pattern/conflict action: **5** (same field_key + original_value + corrected_value in `ocr_correction_logs`).
