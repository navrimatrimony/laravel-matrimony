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
| Per-Suchak reusable customer plan preset | `suchak_customer_plans` (`SuchakCustomerPlan`) | Suchak via `SuchakCustomerPlanService` | mgmt `GET/POST/PUT/DELETE /suchak/customer-plans`; carousel via `resolveCarousel()` | **Materialized into `suchak_service_packages` at SEND** through `SuchakPackageCatalogService::createCustomPackage` — **never FK-linked** back. `preset_key` NULL = full custom plan; `basic`/`premium` = OVERRIDE row for a code preset (price/visibility/order only). Presets stay code-defined in `SuchakDefaultPlans`; DB rows only override/add. NOT the platform `suchak_plans` catalog. `private_note` is Suchak-only, never in the customer carousel. |
| Candidate horoscope facts (rashi, nakshatra, charan, gan, nadi, yoni, devak, kul, gotra, navras, birth weekday) | `profile_horoscope_data` (one row per profile, `ProfileHoroscopeData`) | horoscope wizard step / full PUT / `MutationService` | `horoscope.*` | The row EXISTING is not the same as the row being FILLED — every column is nullable, so a row of NULLs is normal. Never read "row exists" as "data present"; that was defect 2 below. `varna_id` / `vashya_id` / `rashi_lord_id` columns exist here but are **derived from `rashi_id`** and must not be collected as separate inputs — `HoroscopeRuleService` derives them. |
| Manglik status (मंगळ) | `profile_horoscope_data.mangal_dosh_type_id` → `master_mangal_dosh_types` | manual entry only | `horoscope.mangal_dosh_type_id` | **The only home for this fact.** It is NOT derivable from rashi/nakshatra and is NOT part of the 36 gunas — Ashta-Koota does not contain Mangal. Compare it only through `MangalCompatibility`. 7 lookup values; `don_t_know` / `other` mean UNKNOWN, never "no". |
| Yoni (योनी) vocabulary | `master_yonis`, **canonical Sanskrit keys only** (`ashwa`, `gaja`, `mesha`, `sarpa`, `shwan`, `marjar`, `mushak`, `gau`, `mahish`, `vyaghra`, `mrga`, `vanar`, `nakul`, `singh`) + the `other` sentinel | `MasterLookupSeeder::seedYonis()`; derivation in `NakshatraAttributesSeeder` | `yoni_id` | **Bit us (fixed 2026-07-26):** an English duplicate set (`horse`, `elephant`, …) sat beside the Sanskrit rows, both active. See trap 10. Sanskrit is canonical because `master_nakshatra_attributes` derives from it. Retired spellings still resolve via `GunamilanMasterData::YONI_ALIASES`. Never add male/female polarity rows (`YoniPolaritySeeder` is a retired no-op). |
| Nakshatra → gan / nadi / yoni derivation | `master_nakshatra_attributes` | `NakshatraAttributesSeeder` | — | The autofill authority. A blank `gan_id`/`nadi_id`/`yoni_id` on a profile is filled from here at scoring time, so a blank column is never a scoring penalty. |
| Rashi → varna / vashya / rashi lord derivation | `master_rashis.varna_id` / `vashya_id` / `rashi_lord_id` | `AshtakootaMasterSeeder` | — | Varna, Vashya and Graha Maitri have **no user input** — they are pure functions of rashi. Do not add dropdowns for them. |
| "Only show me matches whose गुणमिलन works out" | `profile_preference_criteria.gunamilan_required` (boolean, default false) | web horoscope wizard section; member app + Suchak app kundali section via `MatrimonyProfileApiController` (2026-07-26) | `gunamilan_required` — flat, on the SAME profile read/write both apps already use: member `GET/PUT /api/v1/matrimony-profile`, Suchak `GET/PUT /api/v1/suchak/nxt/{representation}/profile` (thin adapter over the same controller). Also mirrored inside `partner_preferences`. | **Asked on the kundali/horoscope screen, stored as a partner criterion** — it is a statement about the PARTNER, but it is collected where the user is already looking at their own patrika. One fact, one input, one destination: there is no second column and no second question anywhere. Terminology is **गुणमिलन / gunamilan**, never "kundali". Only written when the request actually carries the key, so a screen that omits it (every screen except kundali) can never silently clear a saved `true`. Read side always returns a definite boolean, never null, so a brand-new profile still has a toggle state. Crosses three silent allow-lists — `buildMobileProfileSnapshotFromApi()`'s `preferences` fragment, `MutationService::syncPreferencesFromSnapshot()`'s `$allowed`, and `buildGovernanceParityProfilePayload()` — pinned by `tests/Feature/Matching/GunamilanRequiredApiToggleTest.php` (API) and `GunamilanRequiredToggleTest.php` (web). A not-computable gunamilan is UNKNOWN and must not reject a candidate. |
| Taluka / district position ("where is this place?") | `addresses.lat` + `addresses.lng` **on the taluka/district row itself** | `GeoCentroidBackfillService` (migration 2026_07_26_170100 once, then `php artisan locations:backfill-geo-centroids [--force] [--state=]`) | none (internal) | **DERIVED, not surveyed:** the marginal MEDIAN of the row's descendant village coordinates. There is no second home — no centroid table, no cache key, no parallel column; the request path reads this column and never re-aggregates (that aggregate was 73% of a production suggestions request). **Village lat/lng are NOT authoritative** — `addresses` was geocoded BY NAME, so 77.2% of MH villages carry another village's point; never use a village coordinate directly for a nearby decision. Partly repaired 2026-07-26 from India Post — see the village-position row below and read `addresses.geo_source` before trusting any village point. Hence `GeoCentroidBackfillService::accept()`: in-bounds + >=70% of villages within 25 km of the median + <=100 km from the district centre. Measured Maharashtra after the 2026-07-26 village repair: 35/35 districts and **328/358 talukas** accepted, 30 kept NULL (27 low-consensus, 6 far-from-district — a taluka can fail both; e.g. Gaganbawada's median lands 635 km away in Akola). Scope is Maharashtra only, resolved via `addresses.slug`; every other state is NULL by design. **NULL is a valid, safe answer** — `NearbyGeographyResolver::nearbyTalukaIds()` then returns `[$talukaId]` (the anchor itself), which narrows the pool but never mis-points it. **Provenance is the SAME `addresses.geo_source` column the village rows use — no `is_manual` flag, no override table.** Two values at this level: `village_median` (derived here, on district rows as well as taluka rows) and `owner_manual` (a human supplied the centre). Those last 30 were filled by hand by the product owner on 2026-07-26, verified row-by-row against name + parent district, the state bounding box, and <=150 km from their own district centre, then stamped `owner_manual`. **A row stamped `owner_manual` is skipped by the backfill before the `--force` branch is even reached** — it was never derived from the villages, so re-deriving it would restore the exact median the gate refused, and `--force` on a refused row CLEARS the coordinate. `AuditGeoCentroidsCommand` excludes those rows from its drift count for the same reason (their distance from the village median is the point, not a defect). Verify with `php artisan locations:audit-geo-centroids --state=maharashtra` (read-only, exits 1 on a bad stored centre); thresholds live in the service so writer and auditor agree. Regression cover: `tests/Feature/Location/GeoCentroidManualCentreTest.php`. |
| Village position ("where is this village?") | `addresses.lat` + `addresses.lng` **on the village row**, with `addresses.geo_source` naming the provenance of that exact pair | `RepairVillageCoordinatesCommand` (`php artisan geo:repair-village-coordinates [--apply] [--force] [--rollback=<batch>] [--osm] [--state=]`), sourced from the India Post office directory CSV via `App\Support\Location\PostalDirectory` | none (internal) | **The only home for a village's position — no parallel column, no side table of "better" coordinates.** Original values were NAME-geocoded, so same-named villages swapped points across India. Repaired Maharashtra-only on 2026-07-26: of 44,853 MH villages, **6,006 `india_post_name_pincode`** (normalised office name AND pincode match — a real point for that village), **5,638 `india_post_pincode_area`** (pincode only — the marginal median of that pincode's offices, so every village in the pincode shares one COARSE point; residual p50 4.8 / p90 15.2 km), **33,209 `legacy_name_geocode`** (assessed and deliberately left alone). `geo_source` NULL = never assessed (every non-Maharashtra village). **Read `geo_source` before trusting a village point** — a `pincode_area` row is an area, not a place. Five gates, each one added because it caught a measured failure: pincode-district cross-check (933 rows excluded), pincode scatter <=25 km (11,392), office point not bulk-filled across pincodes (570 — the CSV gave 35 Nashik offices one identical point), office agrees with its own pincode peers (579 — pincode 442606 plots an office in Punjab), and never-make-it-worse (18,402 already inside their pincode area + 1,317 already inside their taluka's accepted consensus radius). Against OpenStreetMap on a 27-village panel the error went median 6.1→0.8 km, p90 24.1→11.0 km, max 635→26 km, with no item worse by more than 0.8 km. |
| How a village coordinate was decided, and what it was before | `address_geo_repairs` (one row per assessed village per run: `old_lat`/`old_lng`/`old_geo_source`, `new_lat`/`new_lng`, `decision`, `match_type`, `reason`, `moved_km`, `batch`) | `RepairVillageCoordinatesCommand` | none (internal) | **History, not current state — not a duplicate of `addresses.geo_source`.** `geo_source` answers "what is this coordinate now?"; this table answers "what was it before, and why did it change?". It is also the BACKUP that makes the repair reversible: `--rollback=<batch>` restores `old_lat`/`old_lng`/`old_geo_source` for every row of that batch and deletes the batch. Proven on 2026-07-26: apply → rollback → dry-run reproduced the pre-repair decision counts exactly. Re-running `--apply` is a no-op (rows already carrying a `geo_source` are skipped), so the command is idempotent; `--force` restores each row to its FIRST journalled coordinate before re-deciding. |
| "This candidate was SHOWN to a Suchak for this seeker" + what the Suchak decided | `suchak_match_suggestions` (`SuchakMatchSuggestion`) | `SuchakMatchSuggestionLogService` | none yet — recording only, no route/controller in this phase | **Append-only impression+decision log.** NOT `profile_matches` (that is a replace-on-write cache of *current* top matches — `MatchingService::replacePersistedMatches()` deletes every row for a profile then re-inserts, so it has no history and no decision). NOT `user_match_behaviors` (member-actor actions, no Suchak actor, no impression record), NOT `profile_match_tab_skips` / `interests` / `shortlists` (single outcome signals, nothing about what was offered). Idempotency key `(seeker_profile_id, candidate_profile_id, run_key)`; a later `run_key` deliberately creates a NEW row — that is how a candidate re-surfaces after the ~30-day cooling period. Rows are never deleted or bulk-replaced; a decision only updates its own row's `decision` / `rejection_reason_code` / `rejection_note` / `decided_at`. `score` + `reasons_json` are a FROZEN suggestion-time snapshot — never recompute them in place. |

---

## 2. Engine registry — one capability, one engine

Before writing a service that does any of these, **use the existing one**.

| Capability | Engine | Notes |
|---|---|---|
| "Does this table / column exist?" | `App\Support\SchemaPresence` | The ONE process-level memo for `Schema::hasTable` / `hasColumn`. Every optional table and legacy column in this codebase is guarded by one of those, inside per-profile and per-pair code paths — a single production suggestions request issued **1,625 `information_schema` round trips (1.2 s)** re-answering questions whose answer cannot change while the process lives. `MatrimonyProfile`, `ProfileCanonicalResidenceService` and `NearbyGeographyResolver` had grown their own private statics for this; they all delegate here now. Flushed automatically on Laravel's migration events (`AppServiceProvider`), which is the only moment a schema can change mid-process. Do not add a fourth private memo. |
| Derived taluka / district coordinate | `App\Services\Location\GeoCentroidBackfillService` | Owns the median formula, the per-state bounds (`STATE_BOUNDS`, public) and the acceptance gate (`accept()`). The migration and `locations:backfill-geo-centroids` both call it; `locations:audit-geo-centroids` re-applies the same three checks read-only. Change a threshold in ONE place — the service — or the writer and the auditor start disagreeing. |
| Marital status keys, dependent fields, year sanity | `App\Support\MaritalDependencyRules` | Canonical status vocabulary. Consumed by web wizard, mobile API and Suchak flow. |
| Minimum marriage age (F18 / M21) | `App\Support\MarriageAgePolicy` | Shared by web wizard, mobile API, Suchak manual create. |
| Fuzzy person-name matching | `App\Support\NameMatcher` | **Three name-comparison engines exist** — this one (fuzzy, scored), `DuplicateDetectionService` (exact snapshot compare), `IntakeDuplicateFieldMatchEvaluator` (intake-to-intake). Extend one; do not add a fourth. Known P2: strips Devanagari vowel marks. |
| Mobile normalisation | `App\Support\MobileNumber` | |
| Contact visibility / masking | `ContactVisibilityDecision`, `ContactVisibilityStrictness`, `SuchakCandidateMaskingService` | |
| Consent contact roles | `App\Support\ConsentContactRole` + `SuchakConsentContactSuggestionService` | |
| Suchak pre-create duplicate check | `SuchakCandidateDuplicateCheckService` | mobile + name + DOB(±1y) + gender scoring, plus optional weak village/caste signal. Tiers: `confirmed`/`high` = app may hard-stop, `medium`/`low` = advisory. Server itself never blocks. Also owns `owner_type` (mine / other_suchak / platform_member / unrepresented) — it reuses `SuchakProfileRepresentation::scopeWithValidConsent()` + `scopePubliclyRoutable()` for "actively represented" and "may name the other Suchak"; do not write a second ownership rule. |
| Suchak write-gate on a represented profile | `SuchakProfileRepresentation::suchakMayEditProfile()` (+ `requiresConsentBeforeSuchakEdit()`, `CONSENT_GATED_EDIT_MODES`) | The ONLY predicate deciding whether a Suchak may write to a represented candidate. Built on the existing `hasValidConsent()`. Enforced once, in `SuchakRepresentedProfileApiController::authorizedContext($forWrite: true)` — every write endpoint funnels through it. Do not re-derive consent state per endpoint. |
| Consent WhatsApp hand-off link | `SuchakConsentService::whatsappShareUrl()` | Single implementation shared by the consent sheet API and the duplicate→link response. |
| Candidate name masking ("Shriram Kadam" → "Shriram K.") | `App\Support\CandidateNameMask` | The ONE name mask for pre-consent surfaces. `SuchakCandidateDuplicateCheckService::maskName()` now delegates to it; the pending-consent-claims feed uses it too. Mobile masking stays `ConsentContactRole::maskMobile()`. Do not hand-roll a third. |
| Is this representation a pending (un-consented) claim? | `SuchakProfileRepresentation::isPendingConsentClaim()` + query twins `scopeExcludingPendingConsentClaims()` / `scopeOnlyPendingConsentClaims()` | The two scopes are exact complements and must stay that way: whatever the customer feeds hide, the consent-requests feed shows — otherwise a claim becomes unreachable and the Suchak can never resend the consent. `excluding…` is used by `SuchakCustomerListService`, the customer-detail and share-card controllers and the web dashboard; `only…` by `SuchakPendingConsentListService`. |
| Suchak pending consent-claim feed | `App\Modules\Suchak\Services\SuchakPendingConsentListService` → `GET /api/v1/suchak/consent-requests` | **Read model, no table.** The complement of `SuchakCustomerListService`: the only surface an un-consented claim is visible on, so the consent-first flow has no dead end. Returns masked name/mobile, the consent id + status, and `consent_link_available: false` always (the raw token is hashed at rest) — a usable link only ever comes from `POST /suchak/consents/{consent}/resend`. It mutates nothing; promotion to a real customer stays `SuchakConsentService::promoteConsentedClaimToCustomer()`. |
| Suchak account approve/reject/suspend/archive | `SuchakAccountLifecycleService` | **The only approval path.** Single and bulk admin actions both route through it so activity logging and guard rails stay identical. |
| Suchak permissions / can-operate | `SuchakAccessService` | |
| Profile writes (all of them) | `App\Services\MutationService` | Owns the income key→column mapping. |
| Mobile onboarding step snapshots | `MobileProfileStepSnapshotService` | Rejects unknown keys and `*_option`; rejects `marriages`. |
| Photo URL resolution | `ProfilePhotoUrlService` | |
| Profile display payload | `MobileProfileDisplayPresenter` | |
| Ready-made Suchak service presets (Basic / Premium) | `App\Modules\Suchak\Support\SuchakDefaultPlans` | **Code-defined, never seeded as DB rows.** `suchak_customer_plans` rows only OVERRIDE a preset (price/visibility/order) or ADD custom plans on top; the preset name/price/services stay in this class. `SuchakCustomerPlanService::resolveCarousel()` merges code presets + overrides + visible customs. Do not duplicate the presets into the DB. |
| Suchak payment-received tracking feed | `App\Modules\Suchak\Services\SuchakPaymentRequestTrackingService` | **Read model over existing `suchak_payment_requests` — NO tracking table.** `GET /suchak/payment-requests` (search / filter=pending\|paid\|all / pagination / outstanding-totals summary). Customer name+mobile come from `customerContext.candidateProfile` (`full_name`, `primary_contact_number` accessor), plan name from `customerAgreement.package_name`. Always scoped to the authed `suchak_account_id`. Do not build a second list. |
| Suchak paid-mark reversal (request level) | `SuchakPaymentRequestService::reversePaidMark()` → `POST /suchak/payment-requests/{id}/reverse-paid` | Flips a PAID request back to OPENED/SENT; mandatory `reason` audited on the immutable `suchak_payment_request_events` trail + `SuchakActivityLog` (`payment_request_paid_reversed`). **Distinct from** the ledger-grade `SuchakCustomerPaymentCorrectionService::postReversal()` (rewrites the ledger on a recorded `SuchakCustomerPayment`). Mark-paid stays `SuchakCustomerPaymentService::recordManualPayment` via `POST .../mark-paid` (optional `note` → `collection_note`). |
| गुणमिलन / 36-guna Ashta-Koota scoring | `App\Services\Gunamilan\GunamilanService` | **The only scorer.** `calculate($viewer, $target)` for one pair; `kootaKeyFor($profile)` + `compare($brideKey, $groomKey)` for bulk (flatten each profile once, then every pair is pure array math, **0 queries**). Read `computable` before `total_points` — false means UNKNOWN, never "incompatible". Threshold `COMPATIBLE_THRESHOLD = 18.0`, **inclusive**. Do not re-implement any koota table elsewhere. |
| Gunamilan master-table snapshot | `App\Services\Gunamilan\GunamilanMasterData` | Container **singleton** + `Cache::rememberForever`. The one in-memory copy of rashis / nakshatras / yonis / gans / nadis / varnas / vashyas / rashi lords / mangal types. Owns the canonical yoni alias map and `isComputableKey()` (which is what stops the `other` sentinel rows being scored as real values). Call `forget()` after seeding or editing any horoscope master table. Never add a fresh `DB::table('master_…')` lookup to a per-pair code path. |
| One profile's flattened Gunamilan inputs | `App\Services\Gunamilan\GunamilanKootaKey` | Ten scalars: rashi position, nakshatra number, varna / vashya / lord / gan / nadi / yoni keys, mangal key, gender. Null means "not available", never "zero". Build once per profile per run. |
| मंगळ / Manglik comparison | `App\Services\Gunamilan\MangalCompatibility` | **Separate from the 36 gunas on purpose** — Ashta-Koota does not contain Mangal, so folding it into the total would produce a number no printed patrika could reconcile. Both-manglik and both-non-manglik are compatible; exactly one manglik is not; anything unknown is `not_computable` and must never be a rejection. `WEIGHT = 0.05` (low, per owner). |
| Horoscope dependency rules, autofill, validation warnings | `App\Services\HoroscopeRuleService` | nakshatra + charan → rashi; nakshatra → gan/nadi/yoni; plus the Tara / Bhakoot / Graha Maitri tables. Reads `GunamilanMasterData`, so its lookups are query-free. Key-based twins (`taraForNumbers`, `bhakootForPositions`, `grahaMaitriPointsForLords`) exist for the hot path — use those, not the id-based ones, inside a loop. |
| Member interest send / accept / reject / withdraw | `App\Services\Interest\InterestActionService` | **The ONE engine for every interest action, on every surface.** Web (`InterestController`), mobile (`Api\InterestApiController`) and the showcase auto-responder (`ShowcaseIncomingInterestResponderService`, via the shared `applyAcceptEffects()` / `applyRejectEffects()`) all call it; controllers only render the returned `InterestActionOutcome`. It exists because the web and API controllers each carried their own copy and silently drifted (2026-07-27): the API sent **no notification at all** — so an interest sent from the Flutter app never reached the receiver and the product looked like "nobody ever replies" — and it also skipped the block check, the paid reveal gate on accept, and the `profile_contact_visibility` + `contact_access_log` grant. Do not re-implement any interest guard in a controller, a job or a command. Refusals are `RuleResult`s from `ErrorFactory` (localized via `lang/{mr,en}/interest.php`), never bare strings. The reveal gate reuses `InterestSendLimitService::isIncomingInterestUnlocked()` — there is no second unlock predicate. Cover: `tests/Feature/Api/MobileInterestParityApiTest.php`. |
| Suchak match suggestion log (impression + decision) | `App\Modules\Suchak\Services\SuchakMatchSuggestionLogService` | **The only writer of `suchak_match_suggestions`.** `recordSuggestions()` (bulk upsert, idempotent per seeker+candidate+run_key, refreshes only `score`/`reasons_json` so a decision is never erased), `recordDecision()` / `recordDecisionForPair()` (chosen / rejected+reason code / ignored), `alreadySuggestedCandidateIds()` (exclusion set), `suggestedRecently($seeker, $days)` (still-too-soon set) and `cooledOffCandidateIds()` (re-surfacable). Decision + rejection-reason vocabularies are PHP consts on `SuchakMatchSuggestion` (varchar columns, no DB enums). It **records only** — it does not rank, filter or change any existing matching behaviour; the ranking engine stays `App\Services\Matching\MatchingService`. Do not write a second suggestion-history table. |

| "Does this profile's contact go through a Suchak?" | `App\Support\Suchak\SuchakContactRouting` | **The ONE routing predicate**, plus the routable-representation lookup, the Suchak display name and the Suchak's own (masked) number. It existed as five hand-copied private methods — `ProfileContactActionController`, `ContactRequestController`, `Api\ContactActionApiController`, `Api\ContactInboxApiController` and `MobileProfileDisplayPresenter` — which had already drifted on their `Schema::hasTable` guards, i.e. the same profile could be "routed" on one surface and not another. All five delegate here now. The only phone value this class can return is the **Suchak's** business number; the candidate's number is never read. |
| Suchak request lifecycle (create → view → reply → forward → candidate answer → SLA close) | `App\Modules\Suchak\Services\SuchakRequestPipelineService` | **The only writer of `suchak_profile_requests` / `suchak_pipelines` / `suchak_pipeline_events`.** Web (`PublicProfileRequestController`, `ProfileRequestReplyController`) and both apps (`Api\MemberSuchakRequestApiController`, `Api\Suchak\SuchakProfileRequestsApiController`) all call it, so an app request is indistinguishable from a web one (pinned by `SuchakRequestPipelineApiTest`). Consent is checked once, in `assertSuchakMayActOnRequest()`, reusing `hasValidConsent()` — no second consent rule. **First-answer-wins** lives in `recordCandidateDecision()`: candidate and Suchak both may answer, the race is decided under `lockForUpdate`, and the loser gets `already_answered` (attribution derived from the immutable pipeline event, **not** a new column). SLA close reuses `expirePipelineIfPastSla()` via the scoped `expireDuePipelines*()` sweeps — there is no cron and must not be a second timer. |
| Suchak request payload shape (member + Suchak + contact card) | `App\Modules\Suchak\Services\SuchakRequestPresenter` | One shape for all three surfaces: `memberRequestPayload()`, `suchakRequestPayload()`, `suchakBlock()`, `contactStateFor()` and the shared `decisionResponse()` both apps read. Owns the four additive contact-card states (`suchak_request_available` / `_pending` / `_answered` / `_closed`) and status labels from `lang/{mr,en}/profile.php` (`suchak_request_status_*`, which already covered all nine states). Never emits a candidate contact number. |

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
7. **`suchak_plans` vs `suchak_customer_plans` — same word "plan", different
   meaning.** `suchak_plans` is the PLATFORM subscription catalog a Suchak *buys*
   (route `GET /suchak/plans`, `SuchakBillingApiController`, consumed live by
   Suchak-apk). `suchak_customer_plans` is the per-Suchak reusable presets a
   Suchak *sells* to their customers (route `/suchak/customer-plans`,
   `SuchakCustomerPlanService`). Never mount one on the other's route (the new
   resource is deliberately at `/customer-plans`, not `/plans`, to avoid
   shadowing the platform endpoint), and never reuse one model for the other.
8. **Never re-add `source_template_id` / `template_*` FKs or `*_json` stage
   columns to `suchak_service_package*`.** The reusable-plan "template" now lives
   in the separate, mutable `suchak_customer_plans` table and is materialized at
   send with NO back-FK. `SuchakServicePackage` stays immutable / admin-approved /
   per-customer. `SuchakPackageRateCardFoundationTest` guards those send-time
   tables — do not "link" the two models.
9. **`profile_matches` vs `suchak_match_suggestions` — both hold (profile,
   matched profile, score, reasons), and they are NOT interchangeable.**
   `profile_matches` is a *cache of the current answer*:
   `MatchingService::replacePersistedMatches()` wipes every row for a profile and
   re-inserts, so asking it "what did we already show this seeker last month" is
   always wrong — the evidence was deleted. `suchak_match_suggestions` is the
   *append-only history*: what was shown, when, with the frozen score/reason
   snapshot, plus the Suchak's decision. Never read history out of
   `profile_matches`, never bulk-delete `suchak_match_suggestions`, and never add
   decision columns to `profile_matches` (2026-07-26).
10. **The same 14 yonis existed twice, in two languages, both active**
    (2026-07-26). `master_yonis` held the Sanskrit set (`ashwa`, `gaja`, …) that
    `master_nakshatra_attributes` autofills from, **plus** an English set
    (`horse`, `elephant`, …) added later by a seeder. Both appeared in the
    dropdown, and `GunamilanService::YONI_ENEMY_PAIRS` was written in English —
    so the enemy-pair rule never fired on an autofilled profile, and the same
    animal under two spellings failed the `===` test. **4 of the 36 gunas were
    wrong on every autofilled profile.** Fixed by making Sanskrit canonical,
    remapping the FKs and deactivating the duplicates. A near-miss of the same
    shape: `keet` was missing from the Vashya pair table, so every Vrishchika
    pairing hit an undocumented 0.5 fallback.
11. **"No data" scored as "incompatible"** (2026-07-26). `calculate()` set
    `available` from the mere EXISTENCE of a `profile_horoscope_data` row, so a
    row of NULLs returned `available: true, total_points: 0/36` and a naive
    `>= 18` check called it INCOMPATIBLE — which would have excluded every
    member who never opened the horoscope step. A `computable` flag and a
    nullable `is_compatible` now separate "0 points" from "unknown". The same
    class of bug: the `other` rows on `master_gans` / `master_nadis` /
    `master_yonis` / `master_rashis` / `master_nakshatras` mean "did not know",
    so comparing two `other` nadis as equal invented a Nadi dosha out of
    nothing. `GunamilanMasterData::isComputableKey()` is the one place that
    decides a sentinel is absence, not a value.
12. **A threshold that drifted by one** (2026-07-26).
    `MobileProfileDisplayPresenter` tested `<= 18.0`, quietly making the
    owner's "18 of 36 is compatible" rule mean 19+. Thresholds live on
    `GunamilanService::COMPATIBLE_THRESHOLD`; do not re-type the literal.

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
