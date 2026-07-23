# Field & engine ownership map

**Purpose: stop duplicates before they are written, cheaply.**

The frozen no-duplicate rule (one fact = one input = one destination = one
engine) is enforced by *knowing what already exists*, not by running tests. This
file is the lookup that makes that check take seconds instead of minutes.

**Use it like this — before adding ANY new field, column, service, engine,
endpoint, or UI input:**

1. Search this file for the fact you are about to store.
2. If it is listed → bind to the existing owner. Do not add a parallel field.
3. If it is genuinely absent → grep to confirm (`grep -rn "your_field" app/ database/migrations/`),
   add it, then **add a row here in the same commit**.

If this file and the code disagree, the code wins — and this file is stale and
must be fixed.

---

## 1. Canonical owners — one fact, one home

| Fact | Canonical home | Written by | API key(s) | Traps / aliases |
|---|---|---|---|---|
| Candidate gender | `matrimony_profiles.gender_id` | manual-create + `basic_info` snapshot | `candidate_gender` (create, key), `gender_id` (snapshot, id) | **Bit us 2026-07-22:** the Suchak wizard rendered two unlinked gender inputs. One visible input only; the second consumer must receive the value, never re-ask. |
| Mother tongue | `matrimony_profiles.mother_tongue_id` | `basic_info` snapshot | `mother_tongue_id` | **Bit us:** removing the community-step *gate* also removed *collection*. Removing a gate ≠ removing the field. |
| Marital status | `matrimony_profiles.marital_status_id` | full PUT / `basic_info` | `marital_status_id` | Status **keys** are owned by `MaritalDependencyRules` — never hardcode `['divorced','annulled','separated','widowed']` again. |
| Previous marriage detail | `profile_marriages` rows | full PUT only | `marriages[]` | The step-snapshot service **rejects** `marriages` (422). Only the full PUT runs the marital engine. |
| Children | `profile_children` rows + `matrimony_profiles.has_children` | full PUT | `children[]`, `has_children` | `children_count` exists for **member mobile onboarding only**. **Bit us:** the Suchak wizard invented a "number of children" field and wrote blank rows. Web/Suchak edit = repeatable rows (gender, age, `child_living_with_id`). |
| Candidate's own mobile | `profile_contacts` (primary) | manual-create (`candidate_mobile`, required since 2026-07-22) | `candidate_mobile` | Not a column on `matrimony_profiles`. |
| Parent contacts | `matrimony_profiles.father_contact_1/2`, `mother_contact_1/2` | full PUT | same names | `_3` variants only exist on some deployments — guarded. |
| Sibling contacts | `profile_siblings.contact_number`, `_2`, `_3` | full PUT | `siblings.*.contact_number*` | Accepted since 2026-07-21. Owner-only on read; stripped for other viewers. |
| Relative contact | `profile_relatives.contact_number` | full PUT | `relatives.*.contact_number` | `_2`/`_3` are **prohibited** — no columns exist. |
| Contacts that do NOT exist | — | — | — | `marriages.*`, `children.*`, `*_addresses.*`, `alliance_networks.*` contact fields are **deliberately prohibited** (no columns; would silently drop). Do not "add support". |
| Personal income | `matrimony_profiles.income_amount` (+ `income_period`, `income_value_type`, `income_min/max_amount`) | career step / full PUT | `income_amount`, `income_period`, … | `annual_income` is the **derived flat** column; `income_normalized_annual_amount` is what **search** uses. Derived-from is allowed by the frozen rule — these are NOT duplicates. Verified 2026-07-23. |
| Candidate photo | `profile_photos` rows (+ legacy `matrimony_profiles.profile_photo`) | photo upload | `primary_photo_url`, `approved_photo_url`, `photo_approved` | API exposes **approved** URLs only, so a freshly uploaded photo returns no URL — treat "photo exists but no URL" as *pending*, not *none*. |
| Suchak identity | `suchak_accounts.mobile_number` | Suchak registration (OTP) | — | **Bit us 2026-07-23:** the admin list showed `user.email`, which is empty for OTP signups, so every row was just a name. Mobile is the real identity. |
| Suchak org name | `suchak_accounts.office_name` / `office_name_mr` | registration | — | Organization rows are meaningless without it. |
| Suchak signup progress | `suchak_accounts.onboarding_step` + `registration_completed_at` | `SuchakRegistrationService` | — | Steps: `otp → identity → location → complete`. Distinguishes "abandoned signup" from "waiting on admin". |
| Suchak account state | `suchak_accounts.verification_status` (+ `public_status`) | `SuchakAccountLifecycleService` | — | The two co-vary in the normal case — show as **one** signal; call out `public_status` only when it diverges. |
| Bilingual labels | `*_mr` sibling columns | — | — | Use `BilingualMasterLabel`; do not invent a second translation path. |

---

## 2. Engine registry — one capability, one engine

Before writing a service that does any of these, **use the existing one**.

| Capability | Engine | Notes |
|---|---|---|
| Marital status keys, dependent fields, year sanity | `App\Support\MaritalDependencyRules` | Canonical status vocabulary. Consumed by web wizard, mobile API and Suchak flow. |
| Minimum marriage age (F18 / M21) | `App\Support\MarriageAgePolicy` | Shared by web wizard, mobile API, Suchak manual create. |
| Fuzzy person-name matching | `App\Support\NameMatcher` | **Three name-comparison engines exist** — this one (fuzzy, scored), `DuplicateDetectionService` (exact snapshot compare), `IntakeDuplicateFieldMatchEvaluator` (intake-to-intake). Extend one; do not add a fourth. Known P2: strips Devanagari vowel marks. |
| Mobile normalisation | `App\Support\MobileNumber` | |
| Contact visibility / masking | `ContactVisibilityDecision`, `ContactVisibilityStrictness`, `SuchakCandidateMaskingService` | |
| Consent contact roles | `App\Support\ConsentContactRole` + `SuchakConsentContactSuggestionService` | |
| Suchak pre-create duplicate check | `SuchakCandidateDuplicateCheckService` | mobile + name + DOB + gender scoring. Advisory, never blocks. |
| Suchak account approve/reject/suspend/archive | `SuchakAccountLifecycleService` | **The only approval path.** Single and bulk admin actions both route through it so activity logging and guard rails stay identical. |
| Suchak permissions / can-operate | `SuchakAccessService` | |
| Profile writes (all of them) | `App\Services\MutationService` | Owns the income key→column mapping. |
| Mobile onboarding step snapshots | `MobileProfileStepSnapshotService` | Rejects unknown keys and `*_option`; rejects `marriages`. |
| Photo URL resolution | `ProfilePhotoUrlService` | |
| Profile display payload | `MobileProfileDisplayPresenter` | |

Full lists: `app/Support/*.php`, `app/Modules/Suchak/Services/*.php`.

---

## 3. Duplicate traps that already bit us

Each of these was a real defect, not a hypothetical:

1. **Two gender inputs** on one wizard step, unlinked, writing the same column by
   two paths (2026-07-22). → One visible input; pass the value on.
2. **Invented "number of children"** where the reference flow uses repeatable
   rows (2026-07-22). → Mirror the reference flow exactly; do not design a
   "simpler" alternative.
3. **Gate removal deleted the field** — mother tongue stopped being collected
   when its gating behaviour was removed (2026-07-22).
4. **Contact fields accepted where no column exists** — silently dropped, later
   re-prohibited so clients get an honest 422 (2026-07-21 → 22).
5. **`user.email` used as the identity line** for OTP-registered Suchaks, so the
   admin list showed bare names (2026-07-23).
6. **Suspected-but-innocent:** `income_amount` vs `annual_income` looked like a
   duplicate and is not — one is derived from the other. *Check before
   "fixing".*

---

## 4. The pre-flight check

Cheap (seconds). Never skip, even in fast mode:

```bash
grep -rn "the_field_name" app/ database/migrations/ resources/views/
```

Then state the result in one line before writing:
*"`X` already exists in `Y` — binding to it"* **or** *"`X` does not exist anywhere — adding it, map updated."*

Test suites are expensive and are skipped by default (see the Speed contract in
the workspace `CLAUDE.md`). **This grep is the cheap check and is not optional** —
it is what actually prevents duplicates.
