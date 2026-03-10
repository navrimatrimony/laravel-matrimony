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

## Admin translation control (future)

The layout is built so a future admin layer can:

- **Discover keys:** Use `config('translation_keys.namespaces')` to get all namespaces and keys (flatten to `nav.search_profiles`, etc.).
- **Read/write values:** Load and edit `lang/{locale}/*.php` (and optionally `lang/{locale}.json` for legacy keys). A future DB or API layer can override Laravel’s file-based loader.
- **AI-generated translations:** Store AI output in the same files or in a separate store; admin can then edit or override.
- **Aliases / quality:** Admin can manage labels and overrides without changing application code, as long as the app keeps using `__()` with the same keys.

Do not hard-code UI strings in Blade; use `__('...')` (or `@lang(...)`) so all UI flows through Laravel’s translator and remains manageable by admin.

## What we do not translate

User-generated content is not translated: names, addresses, company names, free text, biodata content. Only system/UI strings are in scope.
