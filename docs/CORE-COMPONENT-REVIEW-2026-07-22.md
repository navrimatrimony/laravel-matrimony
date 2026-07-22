# Core-component review — 2026-07-22

Focused review of the four new Laravel core components introduced for the
Suchak onboarding/duplicate-check goal, requested as a pre-merge check. Every
finding below was verified against the actual code on
`feature/suchak-onboarding-redesign` (not inferred). None of them block the
merge — they are correctness-adjacent robustness and hygiene items — but they
should be tracked so they are not silently lost.

Severity legend: **P2** = real defect, degrades a feature but does not corrupt
data or block the flow; **P3** = latent/robustness/hygiene, no current
user-visible failure.

---

## 1. `app/Support/NameMatcher.php`

### P2 — `normalize()` strips every Devanagari vowel sign, so different names collapse to one

`matchLevel('श्रीराम कदम', 'श्रिराम कदम')` returns **`exact`** even though the two
strings differ (ी U+0940 vs ि U+093F). Verified by running the class directly.

Root cause: `normalize()` (line 43) does
`preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value)`. Devanagari vowel signs and
the virama (ी ि ा ्) are Unicode **Mark** (`\p{M}`) characters, not **Letter**
(`\p{L}`). The class of "everything that is not a letter/number/space" therefore
*includes* the vowel marks, and they are replaced with spaces. Two names that
share the same consonant skeleton but differ only in vowel signs reduce to the
same letters-only string, so `normA === normB` and the method reports `exact`.

The class docstring says stored names are Latin and cross-script matching is out
of scope, which is why this was not caught — but the Suchak app is Marathi and a
Suchak can type a Devanagari name into the duplicate probe, so the input does
occur in practice. Because the duplicate check is advisory and never blocks
(PO decision, 2026-07-22), the impact is a **misleading "exact duplicate" hint**,
not data loss or a wrong write.

Fix direction: keep marks during normalization for Devanagari (e.g. allow
`\p{M}` through, or normalize NFC and fold on graphemes), or explicitly bail to
`none` for non-Latin input rather than silently over-matching. Do not add a
second matcher — extend this one, per its own docstring.

### P3 — byte-based `strlen` / `levenshtein` / `substr` in `foldToken`/`tokensMatch`

`foldToken` uses `strlen`, `substr`, `str_ends_with($t, 'a')`, and a non-`/u`
`preg_replace('/(.)\1+/', ...)`; `tokensMatch` gates on `strlen(...) >= 4` and
calls `levenshtein`. All are byte-oriented. On the intended Latin input this is
correct and cheap. On multibyte input the length gates and the repeat-squeeze
operate on bytes, which is one more reason the Devanagari path above misbehaves.
If P1 is fixed by letting non-Latin through, these need `mb_`/grapheme-aware
equivalents too.

---

## 2. `app/Modules/Suchak/Services/SuchakCandidateDuplicateCheckService.php`

### P3 — `genderKeyMap()` caches in a `static $map` that outlives the request

Line 323: `static $map = null;` memoises the gender id→key lookup for the life of
the PHP process/worker, not the request. In a long-lived worker (Octane, queue)
a change to `master_genders` would not be seen until restart, and in tests the
map leaks across cases within a process. `master_genders` is effectively static
reference data, so this is low-risk, but a request-scoped cache (or none — it is
a tiny table) would be safer and less surprising.

### P3 — `IDENTITY_SCAN_LIMIT = 300` is a silent cap

Line 258: the identity-candidate query ends `->limit(self::IDENTITY_SCAN_LIMIT)`
with no signal to the caller when the cap is hit. For a same-month DOB window
this is generous today, but if it ever truncates, the probe silently under-
reports possible duplicates and nothing tells the Suchak the list was cut.
Consider surfacing a "showing first N" flag, or logging when the cap is reached.

### P3 — `mobileHits()` scans parent-contact columns with no duplicate-oriented index

Line 190 scans `father_contact_1/2`, `mother_contact_1/2` (plus sibling columns
elsewhere) for a typed mobile. These columns are not part of any index aimed at
this lookup, so the scan cost grows with the table. It is bounded in practice by
the same-month `IDENTITY_SCAN_LIMIT` pre-filter, so this is a scale/performance
follow-up, not a current problem.

### Resolved / verified good

The earlier MySQL-only `DATE_FORMAT` same-month filter is **already gone** — the
service now uses a portable `whereBetween('date_of_birth', [...])` date range
(line 249), which is why the SQLite test connection passes. No action.

---

## 3. `app/Support/MaritalDependencyRules.php`

### P3 — `allowsYearField()` is referenced only by tests

`grep` across `app/` and `tests/` shows `allowsYearField()` (line 59) is called
solely by `tests/Feature/Suchak/SuchakMaritalYearSanityTest.php`. Production code
paths use `yearFieldRules()` / `yearSanityErrors()` instead. It is a public API
with test-only callers — either wire it into the real validation path (if it is
meant to be the canonical gate) or drop it so the surface does not drift. Not
urgent; it is correct, just unused in production.

The canonical status vocabulary (`DETAIL_STATUS_KEYS`, `ALL_STATUS_KEYS`) and the
year-sanity rules are single-sourced here and consumed by both the mobile API
and the Suchak flow — the "one engine" intent holds.

---

## 4. `app/Support/MarriageAgePolicy.php`

### Verified correct (regression that was caught and fixed)

`genderKeyForId()` uses an explicit `DB::table('master_genders')->where('id', ...)`
(line 41). This is deliberately **not** `whereKey()`: `master_genders` has a
literal column named `key`, so `whereKey()` on the base query builder resolved to
a dynamic `where key = <genderId>`, matched nothing, and silently downgraded the
male minimum from 21 to 18. The explicit `where('id', ...)` fixes it and the
comment records why. Female 18 / male 21 now enforced correctly. No action.

---

## Summary

| # | Component | Finding | Severity | Blocks merge? |
|---|---|---|---|---|
| 1 | NameMatcher | Devanagari vowel signs stripped → false `exact` | P2 | No (advisory hint only) |
| 1 | NameMatcher | byte-based string ops on multibyte input | P3 | No |
| 2 | DuplicateCheckService | process-lifetime `static $map` | P3 | No |
| 2 | DuplicateCheckService | silent `IDENTITY_SCAN_LIMIT` cap | P3 | No |
| 2 | DuplicateCheckService | unindexed parent-contact scan | P3 | No |
| 3 | MaritalDependencyRules | `allowsYearField()` test-only | P3 | No |
| 4 | MarriageAgePolicy | verified correct after `whereKey` fix | — | No |

Recommended: track finding 1 (NameMatcher Devanagari) as the one worth a
follow-up before the duplicate probe is relied on for Marathi-script input; the
rest are hygiene/robustness items that can ride a later cleanup.
