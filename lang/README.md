# Translation and locale (SSOT)

## Default language

- **Default locale:** English (`en`). Set in `config/app.php` (`locale` and `fallback_locale`).
- **Supported locales:** `en`, `mr` (Marathi).

## How locale is applied

- User selects language via `?locale=en` or `?locale=mr` (language switcher in the nav).
- `SetLocaleFromQuery` middleware (in the `web` group) reads the query param, validates it, stores the choice in the session, and sets `app()->setLocale($locale)`.
- If no query param is present, the middleware uses the session value. If none, it falls back to `en`.
- Because the middleware runs on every web request (guest and authenticated), the selected language persists across navigation, dashboard, profile wizard, profile show, search, and all Blade components.

## Where translations live

1. **JSON (legacy / current UI):**  
   `lang/en.json` and `lang/mr.json`  
   Keys are the English strings (e.g. `"Search Profiles"`). Views use `__('Search Profiles')`.  
   These files remain the source for existing UI; do not remove keys still in use.

2. **Namespaced PHP (key-based, admin-friendly):**  
   `lang/{locale}/{namespace}.php`  
   Examples: `lang/en/nav.php`, `lang/mr/profile.php`, `lang/en/wizard.php`, `lang/en/actions.php`, `lang/en/match.php`, `lang/en/contact.php`.  
   Use in views as: `__('nav.search_profiles')`, `__('profile.full_name')`, `__('wizard.save_next')`, `__('actions.send_interest')`, `__('match.location')`, etc.

## Key convention (namespaced)

- Format: `{namespace}.{key}` in snake_case.
- Namespaces: `nav`, `profile`, `wizard`, `actions`, `match`, `contact`.
- Examples: `nav.search_profiles`, `profile.full_name`, `wizard.save_next`, `actions.send_interest`, `contact.request_contact`, `match.location`.

## Admin panel madhun English / Marathi list edit कसे करायचे (आणि alias/key कसे जोडायचे)

सध्या **admin panel मध्ये translation edit करण्याचा कोणताही UI नाही**. सर्व इंग्रजी आणि मराठी यादी **फाइल्स मधूनच** संपादित करायच्या आहेत.

### कोणती फाइल कोणत्या साठी

| भाषा | फाइल | वापर |
|------|------|------|
| इंग्रजी | `lang/en/components.php` | वर्ण, बांधा, रक्तगट, आहार, धूम्रपान, दारू, physical/horoscope options, wizard options (religion, caste, mother_tongue इ.) |
| मराठी | `lang/mr/components.php` | वरील सर्वाचे मराठी अनुवाद |
| इंग्रजी | `lang/en/wizard.php` | Wizard लेबल्स (विभाग नावे, बटणे, preferences, contacts इ.) |
| मराठी | `lang/mr/wizard.php` | Wizard चे मराठी |
| इंग्रजी | `lang/en.json` | जुन्या keys (exact English string → translation) |
| मराठी | `lang/mr.json` | त्याच मराठी अनुवाद |

### Option lists — अचूक array path (edit करताना)

सर्व `lang/en/components.php` आणि `lang/mr/components.php` मध्ये `return [ ... 'options' => [ ... ] ]` अंतर्गत:

| UI लेबल | Array path | उदा. key |
|---------|------------|----------|
| वर्ण (Complexion) | `options.complexion` | very_fair, fair, wheatish, dark, other |
| बांधा (Physical build) | `options.physical_build` | slim, average, heavy, muscular |
| रक्तगट (Blood group) | `options.blood_group` (फक्त en — नेहमी इंग्रजी) | A+, B+, O+, not_known |
| आहार (Diet) | `options.diet` | vegetarian, eggetarian, non_vegetarian, vegan |
| धूम्रपान (Smoking) | `options.smoking` | no, yes, occasionally |
| दारू (Drinking) | `options.drinking` | no, yes, occasionally |
| मातृभाषा | `options.mother_tongue` | marathi, hindi, english |
| लग्न प्रकार प्राधान्य | `options.marriage_type_preference` | traditional, court, both |
| धर्म / जात | `options.religion`, `options.caste` | hindu, brahmin, इ. |
| जन्मकुंडली options | `horoscope.options` (वर्ण, वश्य, राशी, नक्षत्र इ.) | varna, vashya, rashi_lord, इ. |

### फाइल मध्ये edit कसे करायचे

1. **PHP array फाइल** (उदा. `lang/mr/components.php`):
   - फाइल उघडा, योग्य array आत जा (उदा. `'options' => [ 'diet' => [ ... ] ]`).
   - इंग्रजी फाइल (`lang/en/...`) मध्ये समान key structure असते; दोन्हीमध्ये एकसारखी keys ठेवा, फक्त value इंग्रजी किंवा मराठी बदला.
2. **JSON फाइल** (`lang/en.json`, `lang/mr.json`):
   - Key = exact string जे view मध्ये `__('...')` मध्ये वापरले आहे; value = त्या भाषेतील अनुवाद.
   - नवीन key जोडताना दोन्ही en.json आणि mr.json मध्ये जोडा.

### नवीन key / alias कसे जोडायचे

**Option 1: Namespaced key (components, wizard इ.)**

- ज्या namespace मध्ये जोडायचे ती फाइल उघडा: `lang/en/components.php` आणि `lang/mr/components.php` (किंवा `wizard.php`).
- योग्य array मध्ये नवीन key जोडा. Key नेहमी **snake_case** आणि इंग्रजीमध्ये (उदा. `new_option`).
- उदा. आहारात नवीन option:
  - `lang/en/components.php` → `'options' => [ 'diet' => [ ... 'jain_food' => 'Jain Food' ] ]`
  - `lang/mr/components.php` → `'options' => [ 'diet' => [ ... 'jain_food' => 'जैन अन्न' ] ]`
- View मध्ये ते key वापरताना: `$optionLabel($row, 'diet')` — DB मधील row चा `key` जर `jain_food` असेल तर वरील translation दिसेल.

**Option 2: JSON (exact string key)**

- जर view मध्ये `__('Some New Label')` असे वापरतात:
  - `lang/en.json`: `"Some New Label": "Some New Label"`
  - `lang/mr.json`: `"Some New Label": "नवीन मराठी लेबल"`

**Alias म्हणजे:** एकाच key साठी दोन भाषेतील भिन्न मूल्ये — ते वरीलप्रमाणे एक key दोन फाइल्स मध्ये (en आणि mr) वेगवेगळ्या value सह जोडले की झाले.

### भविष्यात admin panel मधून edit

भविष्यात DB-driven translations किंवा admin UI आल्यास:
- सध्या वापरलेले keys: `components.*`, `wizard.*`, आणि JSON keys — हेच DB/API मध्ये mirror करता येतील.
- Application code मध्ये फक्त `__('namespace.key')` किंवा `__('Exact string')` वापरत राहिल्यास, admin layer त्या keys ची value replace/override करू शकतो.

Do not hard-code UI strings in Blade; use `__('...')` (or `@lang(...)`) so all UI flows through Laravel’s translator and remains manageable.

## What we do not translate

User-generated content is not translated: names, addresses, company names, free text, biodata content. Only system/UI strings are in scope.
