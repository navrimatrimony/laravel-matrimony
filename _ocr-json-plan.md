GOAL
Fix the biodata intake pipeline so that:
1) As much biodata information as possible from OCR text is captured into parsed_json in the correct structured section.
2) 100% of parsed_json that has a defined destination in the intake preview / profile wizard is auto-filled into the exact intended form field.
3) Existing parser rules MUST NOT be deleted or weakened. All new logic must be additive and backward-safe.
4) SSOT must remain intact:
   - raw_ocr_text immutable
   - no direct profile update() / save() bypass
   - all final mutations only via MutationService
   - no silent overwrite
   - no JSON blob storage for structured profile entities
   - no age storage
   - controlled-option fields must only accept canonical allowed values

IMPORTANT CONTEXT FROM CURRENT CODEBASE
- BiodataParserService already contains:
  - core extraction
  - family structures extraction
  - siblings / relatives extraction
  - other_relatives_text handling
  - horoscope sanitization
  - additive confidence_map generation
- Tests currently pass for parser accuracy and controlled option form engine.
- Therefore DO NOT rewrite parser from scratch.
- DO NOT remove old regex/rules.
- Extend carefully with precedence and additive fallback layers only.

PRIMARY BUGS TO FIX FOR THIS REAL CASE
Use this real biodata case as regression anchor:
- OCR text contains:
  - “रक्‍त गट :- B+ve” but parsed blood_group becomes null because OCR/raw noise variant is not normalized reliably.
  - “नाड २ आध्य गण :- राक्षस. चरण :- ४”
    Expected:
      nadi = adi
      gan = rakshasa
      charan = 4
    Current wrong behavior observed:
      nadi gets wrong value / gan leaks into nadi / form remains unfilled.
  - “रास :- वृश्चिक”
    must map to horoscope rashi field and then preview/wizard.
  - “नक्षत्र :- मृग/मूग OCR variant”
    should either resolve canonical nakshatra if safely matched or remain explicit raw suggestion in parsed_json without corrupting another field.
  - Sister row + spouse details:
    Sister count / sibling row / spouse block behavior is inconsistent.
    Sister should not be lost when biodata contains “बहीण … दाजी …”.
  - Candidate job, father job, brother job must stay separated.
  - Addresses should map to correct address buckets, not remain only generic raw blocks where a specific field exists.
  - `other_relatives_text` must remain in “Other Relatives” only and must not leak into structured relatives rows.
- The user expectation is:
  - whatever is extracted into parsed_json must be visible in intake preview and auto-hydrated into the exact form field if that field exists in the preview/wizard.

FILES TO REVIEW AND CHANGE
Only touch files that are needed.

1) Parser / normalization
- app/Services/BiodataParserService.php
- app/Services/ControlledOptionNormalizer.php
- app/Services/HoroscopeRuleService.php
- app/Services/Parsing/Parsers/AiFirstBiodataParser.php   (only if final sanitization / merge behavior needs small additive support)

2) Intake pipeline / snapshot / mapping
- app/Http/Controllers/IntakeController.php
- app/Services/ManualSnapshotBuilderService.php
- app/Services/Preview/PreviewSectionMapper.php
- app/Services/IntakeApprovalService.php

3) Wizard hydration / save pipeline
- app/Http/Controllers/ProfileWizardController.php
- resources/views/intake/preview.blade.php
- resources/views/matrimony/profile/wizard/full.blade.php
- resources/views/matrimony/profile/wizard/sections/**/*.blade.php
- resources/views/components/profile/horoscope-engine.blade.php
- resources/views/components/profile/address-row.blade.php
- resources/views/components/profile/*siblings*.blade.php
- resources/views/components/profile/*relative*.blade.php

4) Tests
- tests/Unit/BiodataParserIntakeAccuracyTest.php
- add new tests if needed:
  - tests/Unit/BiodataParserFullCoverageRegressionTest.php
  - tests/Feature/IntakePreviewHydrationRegressionTest.php
  - tests/Feature/ProfileWizardHydrationRegressionTest.php

DO NOT TOUCH unrelated files.

TASK 1 — AUDIT THE FULL FIELD PIPELINE
Create a field-by-field mapping audit table in your working notes (not a persisted app artifact) for:
- OCR/parsed_json source path
- approval snapshot path
- preview field name
- wizard field name
- final mutation destination

Must include at least:
- core.full_name
- core.gender
- core.date_of_birth
- core.birth_time
- core.birth_place
- core.religion
- core.caste
- core.sub_caste
- core.height_cm
- core.annual_income
- core.primary_contact_number
- core.father_name
- core.father_occupation
- core.mother_name
- core.mother_occupation
- core.brother_count
- core.sister_count
- core.other_relatives_text
- contacts[]
- education_history[]
- career_history[]
- addresses[]
- horoscope[].rashi / nakshatra / charan / gan / nadi / devak / kuldaivat / gotra / blood_group
- siblings[]
- siblings[].spouse / sibling_spouse rows
- relatives[]

Use this audit to find every place where parsed_json keys and preview/wizard input names differ.

TASK 2 — FIX PARSER COVERAGE ADDITIVELY
In app/Services/BiodataParserService.php:

A) Preserve existing behavior. Do NOT delete old extractors.
B) Add targeted helper methods and call them before/after current rules as safe fallback layers.

Required parser improvements:
1. Horoscope combined-line extraction
   Add a dedicated additive extractor for Marathi combined horoscope lines such as:
   - “नाड २ आध्य गण :- राक्षस. चरण :- ४”
   - “रास :- वृश्चिक नक्षत्र :- मृग”
   - “देवक :- वासनिचा वेल रक्त गट :- B+ve”
   The extractor must:
   - split one OCR line into multiple field candidates safely
   - never assign one field’s value into another field
   - prefer explicit label boundaries
   - support OCR variants:
     - नाड / नाडी / नाडी
     - आध्य / आधी / आदि / आदी / adya OCR variants → canonical nadi = adi
     - गण -> rakshasa/deva/manav canonical key
     - रक्त गट / रक्तगट / blood group
   - reject numeric garbage for blood group
   - keep charan numeric only 1..4

2. Strict canonical controlled-option normalization
   In ControlledOptionNormalizer and/or parser normalization:
   - Nadi must only resolve to one of canonical allowed values:
     - adi
     - madhya
     - antya
   - Gan must only resolve to canonical:
     - deva
     - manav
     - rakshasa
   - Rashi, Nakshatra, Blood Group also must normalize safely to canonical allowed values when confident.
   - If confidence is not enough, keep raw text in parsed_json text slot but DO NOT assign to wrong canonical field.

3. Sister + spouse extraction
   Extend family structure extraction so that:
   - “बहीण …” creates a sister sibling row.
   - adjacent “दाजी …” line is treated as spouse details for the most recent sister row when context strongly supports it.
   - do not create fake sibling rows from count-only relation labels.
   - do not lose the sister just because spouse line exists.
   - keep existing brother logic intact.

4. Address role inference
   Improve address extraction so that:
   - candidate family home / parents home address
   - candidate current/work-linked address
   - sibling address / relative location
   are not all flattened into one ambiguous address bucket when context is available.
   Keep raw backup text if structured inference is uncertain.

5. Career separation
   Ensure candidate career history remains candidate-only.
   Father occupation and brother occupation must not leak into candidate career_history.
   Keep current Amdocs/Bharat Forge split logic; extend only if needed.

6. OCR text salvage
   Where OCR has partial corruption:
   - preserve raw line context in parsed_json if structured split is uncertain
   - do not drop extractable info silently
   - add confidence_map for new extracted paths

TASK 3 — BUILD A CENTRAL FIELD DESTINATION MAP FOR PREVIEW/WIZARD
Problem suspected:
parsed_json has fields, but preview/wizard form names do not align consistently.

Implement a single additive mapping layer, preferably in:
- app/Services/Preview/PreviewSectionMapper.php
and/or
- app/Services/ManualSnapshotBuilderService.php

Requirement:
For every parsed_json field that has a defined UI destination, the same destination key must be used consistently in:
- preview display
- preview editable input names
- approval_snapshot_json
- wizard hydration

Examples to enforce:
- core.brother_count -> field name used by siblings count UI
- core.sister_count -> field name used by siblings count UI
- core.other_relatives_text -> Other Relatives textarea
- horoscope[0].nadi or normalized equivalent -> horoscope row nadi select/text
- horoscope[0].gan -> horoscope row gan select/text
- horoscope[0].charan -> horoscope row charan
- horoscope[0].rashi -> horoscope row rashi
- core.primary_contact_number -> primary contact slot
- siblings[] parsed rows -> sibling engine rows
- sister spouse details -> spouse sub-form rows if that UI exists

If the app currently has inconsistent names like:
- brother_count vs brothers_count
- sister_count vs sisters_count
normalize them through one adapter layer rather than sprinkling ad-hoc fixes everywhere.

TASK 4 — FIX PREVIEW FORM HYDRATION
In intake preview:
- Every parsed section must render all available extracted data in the correct section.
- If a UI field exists for a parsed value, prefill it automatically.
- Do not hide parsed data just because the pretty UI path is missing.
- Where no dedicated form field exists yet, show the parsed value in the correct section explicitly so there is zero silent loss before approval.

Specific rules:
1. Horoscope section:
   - if canonical ID can be resolved, select that option in the dropdown
   - if only raw text exists and no confident canonical mapping, show it visibly for correction, not silently blank
2. Siblings:
   - if parsed_json contains sister row, the preview must instantiate the sister row
   - if spouse details were parsed, spouse sub-form must open/populate for that sibling
3. Other Relatives:
   - map `core.other_relatives_text` or equivalent final key into the exact textarea used by preview
4. Contacts:
   - primary contact must populate primary slot
   - secondary contacts must populate additional contacts only when genuinely additional
5. Education/Career:
   - structured rows from parsed_json must create rows in the engines, not only summary text

TASK 5 — FIX WIZARD HYDRATION / APPLY PATH
In ProfileWizardController + related blades:
- Ensure initial values from profile/intake snapshot hydrate the same field names used in preview.
- Ensure horoscope engine, siblings engine, relatives engine, address engine all accept the snapshot shape produced by preview/approval.
- Avoid any transform that drops unknown rows or reindexes spouse linkage incorrectly.
- If a parsed field has a dedicated DB-backed destination entity:
  - it must be represented in the wizard state and then sent via MutationService.

TASK 6 — ENSURE MUTATION PATH REMAINS SSOT-SAFE
Do NOT introduce direct writes.
Verify final approval/apply path still goes through MutationService only.

If any controller currently writes directly to MatrimonyProfile or related entities for this intake flow:
- refactor only that path to MutationService
- keep changes minimal
- do not widen scope beyond intake/wizard fields being fixed

Also verify:
- no silent overwrite
- conflict handling remains untouched unless absolutely necessary
- raw_ocr_text remains immutable
- approval snapshot remains immutable after approval

TASK 7 — ADD REGRESSION TESTS
Add/extend tests for this exact case.

A) Parser regression test
Add a full biodata regression test with the exact OCR text from the user case.
Assert at minimum:
- full_name
- gender=female
- religion/caste/sub_caste
- date_of_birth
- birth_time
- height_cm
- annual_income
- father_name / father_occupation
- mother_name / mother_occupation
- brother_count = 1
- sister_count = 1
- primary contact extracted
- candidate education row present
- candidate career row present
- father job NOT in candidate career row
- brother occupation attached to brother sibling row if logic supports it
- nadi resolves to canonical adi
- gan resolves to canonical rakshasa
- charan = 4
- rashi resolves to वृश्चिक or canonical mapped equivalent
- blood_group resolves to B+ if OCR permits, otherwise remains visible raw candidate without poisoning another field
- other_relatives_text contains the itar natevaik content
- sister spouse line is linked to the sister row if supported

B) Intake preview hydration test
Simulate parsed_json -> preview payload generation and assert:
- parsed keys become the exact input names used in preview
- horoscope row preselects/populates
- sibling rows are created correctly
- other_relatives_text populates textarea
- primary contact populates primary field

C) Wizard hydration test
Simulate approved snapshot -> wizard render data and assert:
- same fields are hydrated
- no brother_count / brothers_count or sister_count / sisters_count mismatch
- horoscope data not dropped
- sibling spouse linkage not dropped

D) Keep all existing tests passing.

TASK 8 — HANDLE POWERSHELL / TINKER DEBUGGING ISSUE
Do not change app logic for this.
But add a short developer note in your final delivery:
- The provided PowerShell tinker commands failed because `$` escaping was mangled in double-quoted PowerShell strings.
- Recommend using single quotes or a temporary PHP script for future DB inspection.
This is only for delivery notes, not app code.

IMPLEMENTATION RULES
- Smallest safe change only.
- Prefer helper methods and adapter layers over broad rewrites.
- Add new methods instead of replacing stable old methods.
- No schema changes unless absolutely required. If you think schema is required, STOP and explain; do not implement.
- No unrelated UI redesign.
- No deletion of old rules.
- No conversion of structured entities into JSON storage.

EXACT PLACES TO INSPECT FIRST
1. app/Services/BiodataParserService.php
   - parse()
   - horoscope extraction block
   - family structure extraction
   - count extraction
   - relation/spouse linkage logic
   - returned array keys
2. app/Services/ManualSnapshotBuilderService.php
   - where parsed_json becomes approval snapshot
3. app/Services/Preview/PreviewSectionMapper.php
   - where preview section data is built
4. app/Http/Controllers/IntakeController.php
   - preview load / approve flow
5. app/Http/Controllers/ProfileWizardController.php
   - form hydration + save adapters
6. horoscope/sibling/relative Blade components
   - actual input names and expected row shapes

EXPECTED DELIVERABLE FROM CURSOR
Return:
1. List of changed files with one-line reason each
2. Exact bug fixes implemented
3. Any key field-name mismatches found and how they were unified
4. Tests added/updated
5. Commands run for verification
6. Confirmation that:
   - no direct update() introduced
   - old parser rules were preserved
   - MutationService-only governance remains intact

VERIFICATION COMMANDS
Run and report:
- php artisan test
- php artisan route:list | findstr /I "intake matrimony/profile/wizard"
- php artisan optimize:clear

Manual smoke test:
1. Upload the provided biodata again
2. Confirm parsed JSON contains the corrected horoscope/sibling/relatives data
3. Confirm intake preview auto-fills those exact fields
4. Approve and apply
5. Open full profile wizard
6. Confirm the same values remain present in:
   - horoscope
   - siblings
   - relatives
   - other relatives
   - contacts
   - education
   - career
7. Confirm no previous working biodata formats regressed

ROLLBACK NOTES
If a new mapping layer causes regressions:
- revert only the new adapter/helper methods and new tests
- keep prior stable parser logic intact
- do not revert unrelated controlled-option or mutation governance code