# Centralized Income Engine — Deliverables

## 1. Files changed

| File | Change |
|------|--------|
| `database/migrations/2026_03_14_100001_add_income_engine_fields_to_matrimony_profiles.php` | **New** – additive migration for income engine columns |
| `app/Services/IncomeEngineService.php` | **New** – normalize to annual + display formatter |
| `resources/views/components/income-engine.blade.php` | **New** – reusable Income Engine Blade component |
| `resources/views/components/education-occupation-income-engine.blade.php` | **Updated** – Card 3 replaced with two `<x-income-engine>` (Income + Family Income) |
| `app/Models/MatrimonyProfile.php` | **Updated** – fillable, casts, `familyIncomeCurrency()` relation |
| `app/Http/Controllers/ProfileWizardController.php` | **Updated** – income engine validation, `buildIncomeEngineCore`, `incomeEngineValidationRules` |
| `app/Services/ManualSnapshotBuilderService.php` | **Updated** – income engine keys in core, `buildIncomeEngineCoreForSnapshot` |
| `resources/views/matrimony/profile/show.blade.php` | **Updated** – Income / Family Income display via `IncomeEngineService::formatForDisplay` |
| `resources/views/intake/preview.blade.php` | **Updated** – `educationOccupationIncomeKeys` extended with new income engine keys |

---

## 2. New / updated DB fields

**Personal income engine (all nullable, additive):**

- `income_period` (string, 20) – `annual` \| `monthly` \| `weekly` \| `daily`
- `income_value_type` (string, 20) – `exact` \| `approximate` \| `range` \| `undisclosed`
- `income_amount` (decimal 14,2)
- `income_min_amount` (decimal 14,2)
- `income_max_amount` (decimal 14,2)
- `income_normalized_annual_amount` (decimal 14,2)

**Family income engine (all nullable, additive):**

- `family_income_period`, `family_income_value_type`, `family_income_amount`, `family_income_min_amount`, `family_income_max_amount`
- `family_income_currency_id` (unsignedBigInteger, nullable)
- `family_income_private` (boolean, nullable)
- `family_income_normalized_annual_amount` (decimal 14,2)

**Unchanged (Phase-5):** `annual_income`, `income_range_id`, `family_income`, `income_currency_id`, `income_private` are **not** removed or modified.

---

## 3. Component props and usage

**Component:** `<x-income-engine />`

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `label` | string | `'Income'` | Card heading (e.g. "Income", "Family Income") |
| `namePrefix` | string | `'income'` | Field prefix: `income` or `family_income` |
| `values` | array | `[]` | Override values (key = full name, e.g. `income_period`) |
| `profile` | object | `null` | Profile model for current values |
| `currencies` | collection | from DB | List of `MasterIncomeCurrency` |
| `privacyEnabled` | bool | `true` | Show privacy toggle |
| `periodOptions` | array | Annual, Monthly | `[['value'=>'annual','label'=>'Annual'], ...]` |
| `valueTypeOptions` | array | Exact, Approximate, Range, Prefer not to say | Same shape |
| `disabled` | bool | `false` | Disable all inputs |
| `helpText` | string | `null` | Optional helper text below |
| `readOnly` | bool | `false` | Display-only (no form fields) |
| `errors` | array \| ViewErrorBag | `[]` | Validation errors |

**Usage examples:**

```blade
{{-- Personal income --}}
<x-income-engine
    label="Income"
    namePrefix="income"
    :profile="$profile"
    :currencies="$currencies"
    :errors="$errorsArray"
/>

{{-- Family income --}}
<x-income-engine
    label="Family Income"
    namePrefix="family_income"
    :profile="$profile"
    :currencies="$currencies"
    :errors="$errorsArray"
/>
```

Form field names: `income_period`, `income_value_type`, `income_amount`, `income_min_amount`, `income_max_amount`, `income_currency_id`, `income_private` (and `family_income_*` when `namePrefix="family_income"`).

---

## 4. Validation rules summary

- **Global:** `income_period` / `family_income_period`: `nullable|in:annual,monthly,weekly,daily`.  
  `income_value_type` / `family_income_value_type`: `nullable|in:exact,approximate,range,undisclosed`.  
  `income_currency_id` / `family_income_currency_id`: `nullable|exists:master_income_currencies,id`.  
  `income_private` / `family_income_private`: `nullable|boolean`.

- **If value_type = exact or approximate:**  
  `income_amount` / `family_income_amount`: `required|numeric|min:0`.

- **If value_type = range:**  
  `income_min_amount` / `family_income_min_amount`: `required|numeric|min:0`.  
  `income_max_amount` / `family_income_max_amount`: `required|numeric|min:0|gte:income_min_amount` (or `family_income_min_amount`).

- **If value_type = undisclosed:** no amount required.

---

## 5. Normalized annual amount strategy

- **exact / approximate:** `normalized_annual = amount × period_multiplier`  
  (annual=1, monthly=12, weekly=52, daily=365).

- **range:** `normalized_annual = midpoint(min_amount, max_amount) × period_multiplier`.

- **undisclosed:** `normalized_annual = null`.

Implemented in `App\Services\IncomeEngineService::normalizeToAnnual()`.  
Stored in `income_normalized_annual_amount` and `family_income_normalized_annual_amount` for matching.

---

## 6. Backward compatibility notes

- **Existing columns** `annual_income`, `income_range_id`, `family_income`, `income_currency_id`, `income_private` are **kept**. No column removed or repurposed.
- **Display:** `IncomeEngineService::formatForDisplay()` uses legacy `annual_income` / `family_income` when `income_amount` / `family_income_amount` are null.
- **Form:** Legacy profiles without `income_value_type` get default `exact` when they have an amount (or legacy annual/family income); amount input is prefilled from `annual_income` / `family_income`.
- **Snapshot / apply:** Wizard and full-edit snapshot builders merge new income engine keys with existing core; legacy keys remain in core.

---

## 7. Artisan commands to run

```bash
php artisan migrate --force
```

No seed required for the income engine itself. Existing `master_income_currencies` and data are used as-is.

---

## 8. Verification steps

1. **Migration:** `php artisan migrate` – migration runs without errors.
2. **Wizard:** Open profile wizard → Education, Career & Family.  
   - See two cards: **Income** and **Family Income**.  
   - Each has Row 1: Period, Value type, Currency, Private.  
   - Row 2: single amount (Exact/Approximate), or min/max (Range), or “No amount required” (Prefer not to say).  
   - Change value type and confirm Row 2 updates (show/hide).
3. **Submit:** Choose Exact, Annual, enter amount, set currency, optionally Private. Submit step. Reload: values and privacy persist.
4. **Profile show:** View profile. Income and Family Income use formatted text (e.g. “₹12,00,000 annually”, “Approx. ₹50,000 monthly”, “Income hidden”, “Not disclosed”).
5. **Full edit:** Full profile edit uses same engine; snapshot includes new keys and normalized annual amount.

---

## 9. Screens / places where old fragmented UI was replaced

| Location | Before | After |
|----------|--------|--------|
| Profile wizard → Education, Career & Family | Single “Income” card: Annual Income (range dropdown), Keep private, Family income, Currency as separate rows | Two cards: **Income** and **Family Income**, each using `<x-income-engine>` (period, value type, currency, privacy, dynamic amount row) |
| Full edit (manual snapshot) | Same fragmented income fields in core | Same new engine UI; core built with new keys + normalized annual in `ManualSnapshotBuilderService` |
| Intake preview | Education/career/income keys listed | Same list extended with `income_*` and `family_income_*` engine keys |
| Profile show (view) | Plain “Annual Income” / “Family Income” + currency | `IncomeEngineService::formatForDisplay()` for both (formatted string, privacy, undisclosed) |

Education and career fields (education category/degree, Working With / Working As, etc.) are unchanged.
