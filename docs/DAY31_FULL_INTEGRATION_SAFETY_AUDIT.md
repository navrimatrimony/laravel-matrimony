# DAY-31 — FULL INTEGRATION & SAFETY AUDIT

**PROJECT:** Laravel Matrimony  
**MODE:** PHASE-5 SSOT STRICT  
**SCOPE:** Upload → Parse → Preview → Accept Suggestion → Approve → Learning → Pattern Governance

---

## STEP 1 — Map Full Approve Flow

### Execution path: POST /intake/approve/{id}

```
[Client] POST /intake/approve/{id}
    │
    ▼
[Route] web.php: Route::post('/intake/approve/{intake}', [IntakeController::class, 'approve'])
    │
    ▼
[1] IntakeController::approve(Request $request, BiodataIntake $intake)
    │
    ├─ Gate: session('preview_seen_' . $intake->id) → else abort(403)
    ├─ $snapshot = $request->input('snapshot')
    ├─ If snapshot array: $snapshot = normalizeApprovalSnapshot(array_merge($intake->parsed_json, $snapshot))
    ├─ Else: $snapshot = null
    │
    ▼
[2] IntakeApprovalService::approve($intake, auth()->id(), $snapshot)
    │
    ├─ If intake_locked === true → return [already_applied]; NO DB writes, NO apply
    ├─ If parse_status !== 'parsed' → throw RuntimeException
    ├─ If approved_by_user === true:
    │     ├─ If intake_locked OR intake_status === 'applied' → throw RuntimeException
    │     └─ Else → return MutationService::applyApprovedIntake($intake->id)  [NO logs/patterns written]
    │
    ├─ $approvalSnapshot = $snapshot ?? $intake->parsed_json
    │
    ▼
[3] DB::transaction(function):
    │
    ├─ For each key in $approvalSnapshot['core']:
    │     ├─ $oldValue = $parsedCore[$field] ?? null
    │     ├─ $normalizedOld = normalizeForComparison($oldValue)
    │     ├─ $normalizedNew = normalizeForComparison($newValue)
    │     │
    │     └─ If normalizedOld !== normalizedNew:
    │           ├─ INSERT ocr_correction_logs (intake_id, field_key, original_value, corrected_value)
    │           ├─ INSERT ocr_correction_logs_actor_archive (ocr_correction_log_id, corrected_by, created_at)
    │           └─ strengthenPatternIfThreshold($field, $normalizedOld, $normalizedNew)
    │
    ├─ UPDATE biodata_intakes SET
    │       approved_by_user = true, approved_at = now(),
    │       approval_snapshot_json = $approvalSnapshot, snapshot_schema_version = 1, intake_status = 'approved'
    │   WHERE id = $intake->id
    │
    └─ [transaction commit]
    │
    ▼
[4] MutationService::applyApprovedIntake($intake->id)
    └─ (Profile/contacts/children/education/career/addresses/property/horoscope/legal/preferences mutation.
        Does NOT write ocr_correction_logs, actor_archive, patterns, conflicts. Does NOT modify raw_ocr_text or parsed_json.)
```

### Flow diagram (tables)

```
POST /intake/approve/{id}
         │
         ▼
   ┌─────────────────────────────────────────────────────────────────┐
   │  IntakeController::approve                                       │
   │  → IntakeApprovalService::approve                                │
   └─────────────────────────────────────────────────────────────────┘
         │
         ▼
   ┌─────────────────────────────────────────────────────────────────┐
   │  DB TRANSACTION                                                  │
   │  For each core field where approved ≠ parsed:                    │
   │    → WRITE ocr_correction_logs (1 row per field)                 │
   │    → WRITE ocr_correction_logs_actor_archive (1 row per log)      │
   │    → strengthenPatternIfThreshold → maybe WRITE/UPDATE below     │
   │  Then:                                                           │
   │    → WRITE biodata_intakes (update 5 columns only)               │
   └─────────────────────────────────────────────────────────────────┘
         │
         ▼
   strengthenPatternIfThreshold(fieldKey, originalValue, correctedValue)
         │
         ├─ originalValue === null OR correctedValue === null → SKIP (no DB)
         ├─ count = COUNT(logs WHERE field_key, original_value, corrected_value) < 5 → SKIP
         │
         ├─ count >= 5, existing pattern (same field_key, wrong_pattern, source=frequency_rule):
         │     ├─ same corrected_value → BUMP: UPDATE ocr_correction_patterns (usage_count, updated_at)
         │     └─ different corrected_value → CONFLICT: INSERT/UPDATE ocr_pattern_conflicts only
         │
         └─ count >= 5, no existing pattern → CREATE: INSERT ocr_correction_patterns (is_active = sanityCheck)
         │
         ▼
   MutationService::applyApprovedIntake (no correction tables, no raw_ocr_text/parsed_json change)
```

### Exact DB tables affected by approve (first-time)

| Table | Operation | Condition |
|-------|-----------|-----------|
| ocr_correction_logs | INSERT | For each core field where normalized approved ≠ normalized parsed |
| ocr_correction_logs_actor_archive | INSERT | One per inserted correction log |
| ocr_correction_patterns | UPDATE | Bump: count ≥ 5 and existing pattern with same corrected_value |
| ocr_correction_patterns | INSERT | Create: count ≥ 5 and no pattern for (field_key, wrong_pattern, frequency_rule) |
| ocr_pattern_conflicts | INSERT/UPDATE | Conflict: count ≥ 5 and existing pattern with different corrected_value |
| biodata_intakes | UPDATE | Always (approved_by_user, approved_at, approval_snapshot_json, snapshot_schema_version, intake_status) |

**Where strengthenPatternIfThreshold is called:**  
Inside the same DB transaction, immediately after each `OcrCorrectionLog::create()` and actor_archive insert, for that field’s (field_key, normalizedOld, normalizedNew).

**Conditions summary:**

| Outcome | Condition |
|---------|-----------|
| **Skip** | originalValue === null \|\| correctedValue === null |
| **Skip** | count < 5 |
| **Bump** | count ≥ 5 and existing pattern with same corrected_value → update usage_count, updated_at |
| **Conflict** | count ≥ 5 and existing pattern with different corrected_value → ocr_pattern_conflicts only |
| **Create** | count ≥ 5 and no existing pattern → insert ocr_correction_patterns, is_active = sanityCheckLearnedValue() |

---

## STEP 2 — Safety Checks (Verified in Code)

| # | Check | Verification |
|---|--------|----------------|
| 1 | original_value null → learning skipped | strengthenPatternIfThreshold line 117–119: `if ($originalValue === null \|\| $correctedValue === null) { return; }` ✓ |
| 2 | corrected_value null → skipped safely | Same early return ✓ |
| 3 | No duplicate pattern rows | Create only when `$existing = ...->first()` is null. Bump and Conflict never insert a new pattern. One row per (field_key, wrong_pattern, source=frequency_rule). ✓ |
| 4 | No overwrite of pattern with different corrected_value | When existing and `$existing->corrected_value !== $correctedValue`, only ocr_pattern_conflicts is written; pattern is not updated. ✓ |
| 5 | raw_ocr_text and parsed_json immutable after approve | IntakeApprovalService only sets: approved_by_user, approved_at, approval_snapshot_json, snapshot_schema_version, intake_status. BiodataIntake::updating() throws if raw_ocr_text is dirty. MutationService does not touch intake raw_ocr_text/parsed_json. ✓ |
| 6 | Approve not executed twice (idempotency) | When approved_by_user === true, the approval block (logs + pattern logic + intake update) is skipped; only applyApprovedIntake runs. Second POST does not insert duplicate logs. ✓ |
| 7 | No mass assignment vulnerability | OcrCorrectionLog::create() and OcrCorrectionPattern::create() use fixed arrays; no $request->all() or user input passed into create. Fillable on both models restricts attributes. ✓ |

---

## STEP 3 — Three Mandatory Test Scenarios

### CASE A — Logs only (count < 5)

**Setup:** Fewer than 5 rows in ocr_correction_logs for (field_key, original_value, corrected_value). e.g. first approval correcting caste मटाठा → मराठा.

**Expected:**
- New row(s) in ocr_correction_logs and ocr_correction_logs_actor_archive.
- No change in number of rows in ocr_correction_patterns for that (field_key, wrong_pattern).

**Verification queries (replace :intake_id):**

```sql
-- A1: Correction log created
SELECT id, intake_id, field_key, original_value, corrected_value
FROM ocr_correction_logs
WHERE intake_id = :intake_id AND field_key = 'caste';

-- A2: Archive row for that log
SELECT a.id, a.ocr_correction_log_id, a.corrected_by
FROM ocr_correction_logs ocl
JOIN ocr_correction_logs_actor_archive a ON a.ocr_correction_log_id = ocl.id
WHERE ocl.intake_id = :intake_id AND ocl.field_key = 'caste';

-- A3: Count for this correction (must be < 5 for no pattern)
SELECT COUNT(*) AS cnt FROM ocr_correction_logs
WHERE field_key = 'caste' AND original_value = 'मटाठा' AND corrected_value = 'मराठा';

-- A4: No new pattern (count of patterns for this wrong_pattern unchanged, e.g. 0)
SELECT COUNT(*) FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
```

**Expected result structure:** A1 one row; A2 one row; A3 value 1–4; A4 zero (or same as before approval).

---

### CASE B — Bump existing pattern

**Setup:** One row in ocr_correction_patterns with (field_key='caste', wrong_pattern='मटाठा', corrected_value='मराठा', source='frequency_rule', usage_count=5). One more approval with same correction so total logs for (caste, मटाठा, मराठा) = 6.

**Expected:**
- One new ocr_correction_logs + one ocr_correction_logs_actor_archive.
- Same single ocr_correction_patterns row; usage_count updated to 6; updated_at changed.
- No new pattern row.

**Verification queries (before approval):**

```sql
SELECT id, usage_count, updated_at FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
-- Note id, usage_count (e.g. 5), updated_at.
```

**After approval:**

```sql
SELECT id, field_key, wrong_pattern, corrected_value, usage_count, pattern_confidence, is_active, updated_at
FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
-- Expect: same id, usage_count = 6, updated_at > before.

SELECT COUNT(*) FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा';
-- Expect: 1.
```

---

### CASE C — Create new pattern

**Setup:** No row in ocr_correction_patterns for (field_key='caste', wrong_pattern='मटाठा', source='frequency_rule'). Exactly 4 logs for (caste, मटाठा, मराठा). One more approval so count = 5.

**Expected:**
- One new ocr_correction_logs + one ocr_correction_logs_actor_archive.
- One new row in ocr_correction_patterns: source = 'frequency_rule', usage_count = 5, is_active = sanityCheckLearnedValue('caste','मराठा').
- No ocr_pattern_conflicts row for this (field_key, wrong_pattern) from this path.

**Verification queries (after approval):**

```sql
SELECT COUNT(*) FROM ocr_correction_logs
WHERE field_key = 'caste' AND original_value = 'मटाठा' AND corrected_value = 'मराठा';
-- Expect: 5.

SELECT id, field_key, wrong_pattern, corrected_value, source, usage_count, pattern_confidence, is_active
FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
-- Expect: 1 row, source = 'frequency_rule', usage_count = 5, pattern_confidence = 0.80.
```

---

## STEP 4 — Conflict scenario test

**Setup:** Existing pattern: (field_key='caste', wrong_pattern='मटाठा', corrected_value='मराठा'). New correction with same (field_key, wrong_pattern) but corrected_value='मराठा (96 कुळी)' (different). Log count for (caste, मटाठा, मराठा (96 कुळी)) reaches 5+.

**Expected:**
- ocr_correction_logs and actor_archive rows for the new correction.
- ocr_pattern_conflicts: one row (or updated) with field_key, wrong_pattern, proposed_corrected_value = new value, existing_corrected_value = 'मराठा'.
- Original ocr_correction_patterns row unchanged (no update to corrected_value or overwrite).

**Verification queries:**

```sql
-- Original pattern unchanged
SELECT id, wrong_pattern, corrected_value, usage_count FROM ocr_correction_patterns
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा' AND source = 'frequency_rule';
-- Expect: corrected_value still 'मराठा'.

-- Conflict row present
SELECT field_key, wrong_pattern, proposed_corrected_value, existing_corrected_value, observation_count
FROM ocr_pattern_conflicts
WHERE field_key = 'caste' AND wrong_pattern = 'मटाठा';
-- Expect: proposed_corrected_value = new value, existing_corrected_value = 'मराठा'.
```

---

## STEP 5 — Audit integrity

| Requirement | Verification |
|-------------|--------------|
| Admin toggle writes admin_audit_logs | OcrPatternController::toggleActive() calls AuditLogService::log(..., 'ocr_pattern_toggle_active', ...) inside the same transaction as the pattern update. ✓ |
| Intake approve does NOT write admin_audit_logs | IntakeApprovalService::approve() has no call to AuditLogService or AdminAuditLog. Only logs/archive/patterns/conflicts/biodata_intakes. ✓ |
| No silent failures | Exceptions (e.g. parse_status !== 'parsed', already applied) throw. Transaction ensures all-or-nothing for approval block. ✓ |

---

## STOP CONDITION — Result

**Safety:** All seven checks in Step 2 pass; raw_ocr_text and parsed_json remain immutable; no overwrite of patterns; no duplicate pattern rows; idempotency and mass-assignment safe.

**SSOT alignment:** Approve flow matches PHASE-5C Day-26/27/28: correction logs and archive on diff; pattern learning only at count ≥ 5; bump/conflict/create rules as specified; admin audit only on toggle.

**No code changes made.** No safety bug found requiring a patch.

---

## Intake → Profile flow (clarification)

**Q: Intake मधली माहिती wizard (profile) मध्ये दिसते का?**  
**A:** Partially. `MutationService::applyApprovedIntake` (PHASE-5 unchanged) applies only **six core fields** to the profile:

- `full_name`, `gender_id`, `date_of_birth`, `religion_id`, `caste_id`, `sub_caste_id`

Father name, mother name, height, contacts, education, career, addresses, property, horoscope, etc. from the intake are **not** written to the profile by apply. So the wizard will show those six from intake (once applied); the rest must be filled manually or stay empty. This is current design; MutationService is not to be altered per PHASE-5.

**Q: Intake edit + Approve केल्यावर कुठे जाते?**  
**A:** Redirect is to **intake status** (`/intake/status/{id}`). From there the user can go to Dashboard or (with the new link) directly to **Complete your profile** (wizard full).

---

## Day-31 Integration & Safety Audit PASSED.
