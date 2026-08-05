# U5 Implementation Contract — Suchak OTP fails closed in production

**Unit:** U5  
**Authority:** `docs/MARKETPLACE-MASTER-EXECUTION-SSOT.md` §U5  
**Schema:** none  

## Runtime truths referenced by U5

None cited by name. Behaviour mirrors `MobileOtpService::resolveDeliveryMode()`'s production awareness via `app()->isProduction()`.

## Behaviour

In `SuchakRegistrationService::issueOtp()` when `mobile_verification_mode === 'dev_show'`:

- **Non-production** (`local` / `testing` / staging): return plaintext OTP (unchanged).
- **Production:** still use `delivery => dev_show` and store the hashed OTP, but return `'otp' => null` so an AdminSetting alone cannot emit plaintext OTP.

No new architecture; no schema; WhatsApp path unchanged.

## Tests

- Force production + `dev_show` → response/service `otp` is null.
- Default testing env + `dev_show` → OTP still present (existing staged registration continues).

## Regression

- `SuchakStagedRegistrationApiTest` green (testing env still gets `debug_otp`).
- Assert `EnsureSuchakLegacyOtpEnabled::enabled()` is false when `app()->isProduction()` and config unset (default OFF in production).

## Rollback

`git revert <sha>`
