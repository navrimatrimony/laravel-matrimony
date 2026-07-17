# UTF-8 / Marathi Mojibake — Local Root Cause & Repair

## Verdict

**Root cause:** Local MySQL **data** was double-encoded (UTF-8 bytes misinterpreted as Windows-1252 / CP1252, then stored again as UTF-8).  
**Not the cause:** Browser headers, HTML meta charset, PHP `default_charset`, Laravel response encoding, PDO/`SET NAMES`, table/column charset (all already `utf8mb4`).

Production renders correctly because production stored bytes are clean UTF-8. Local runtime config already matched production connection charset; local **row contents** did not.

## Evidence (local)

| Check | Result |
|-------|--------|
| `Content-Type` | `text/html; charset=utf-8` |
| `<meta charset="utf-8">` | Present |
| PHP `default_charset` | `UTF-8` |
| Laravel `mysql.charset` | `utf8mb4` |
| `SET NAMES` / session charset | `utf8mb4` / `utf8mb4_unicode_ci` |
| Table/column charsets | `utf8mb4` (no latin1 leftovers) |
| `lang/mr/*` source files | Clean Devanagari UTF-8 |
| New Marathi INSERT round-trip | Clean (`E0 A4 …` bytes) |
| Existing rows before repair | Classic mojibake (`à¤¨à¤µà¤°à¥€…`) in settings, masters, addresses, profiles, OCR text, … |

Example stored hex for corrupted `क`:

- Expected UTF-8: `E0 A4 95`
- Stored mojibake UTF-8: `C3 A0 C2 A4 E2 80 A2` (= `à` + `¤` + `•`)

That is exactly Windows-1252 reinterpretation of `E0 A4 95`.

After repair, login title / `admin_settings.site_identity_site_name` reads: **नवरी मिळे नवऱ्याला**.

## How it usually happens

Typical local Windows path:

1. Dump/export of UTF-8 (`utf8mb4`) data.
2. Import/client connection not forced to `utf8mb4` (often defaults toward `latin1` / CP1252 on Windows tools).
3. Multi-byte Indic text is split into single-byte characters and re-saved as UTF-8.
4. App reads “valid UTF-8” that is visually mojibake.

Runtime Laravel already sets:

```php
PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'
```

So **new writes are safe**. Only previously corrupted local rows needed repair (or a clean re-import).

## Permanent fix (code)

1. **Do not mass-edit source/lang files** — they were not the problem.
2. Repair utility:
   - `App\Support\Encoding\Utf8MojibakeRepair` — reversible CP1252 mojibake decode (Devanagari + other Indic; multi-pass for triple encoding).
   - `php artisan db:repair-utf8-mojibake` — dry-run by default; `--apply` only on `APP_ENV=local` unless `--force`.
3. Prefer preventing recurrence on import:

```bash
mysql --default-character-set=utf8mb4 -u root -p laravel_matrimony < dump.sql
```

Or in the SQL dump header:

```sql
SET NAMES utf8mb4;
SET character_set_client = utf8mb4;
```

## Prefer clean re-import when possible

If a known-good production dump is available, restoring it with `utf8mb4` is cleaner than repairing. Use the artisan repair when a re-import is not practical.

## Note on SQL `LIKE`

`utf8mb4_unicode_ci` is accent-insensitive, so `LIKE '%à¤%'` can false-positive on ASCII. The repair command uses a Unicode `REGEXP` marker plus the PHP repairer as the final gate.

## Validation checklist

After `--apply`:

- Login/title shows `नवरी मिळे नवऱ्याला`
- Master Marathi labels (`master_castes.label_mr`, etc.)
- Address Indic names
- Profile / Suchak / consent / OCR text that was corrupted
- New Marathi insert still round-trips
- English + emoji unchanged
