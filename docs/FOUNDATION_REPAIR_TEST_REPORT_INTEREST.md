# Foundation Repair & Hardening — Test Report

Re-verification of existing features — no assumptions, SSOT-first.

---

## Feature Name
**Interest** (Send, Accept, Reject, Withdraw + Sent/Received listing)

---

## Test Scenarios Executed

| # | Scenario | Method |
|---|----------|--------|
| 1 | Happy path (send, accept, reject, withdraw, list sent/received) | Controller + Service + Model trace |
| 2 | Invalid input (bad profile id, bad interest id) | Route model binding + controller guards |
| 3 | Empty / null / whitespace input | Request/URL analysis |
| 4 | Repeated action (double submit, retry) | firstOrCreate + status guards |
| 5 | Wrong action order (e.g. withdraw after accept) | Status checks in withdraw/accept/reject |
| 6 | Unauthorized access (accept/reject as non-receiver, withdraw as non-sender) | Ownership guards |
| 7 | State misuse (lifecycle / status mismatch) | ProfileLifecycleService in store/accept/reject |
| 8 | Data overwrite / silent data loss risk | Update/delete scope analysis |
| 9 | Controller → Service → Model → DB + guards | Full trace |

---

## PASS / FAIL
**FAIL**

---

## If FAIL

### Root Cause
Blade views for **Received Interests** and **Sent Interests** assume the related profile (sender or receiver) is always present. When the related `MatrimonyProfile` is **missing** (e.g. soft-deleted — Eloquent excludes soft-deleted from `belongsTo` so the relation returns `null`), the view still accesses properties on that relation in the `@else` branch, causing a **runtime error** (trying to get property 'gender' of null).

- **received.blade.php**: Line 46 in the `@else` block uses `$interest->senderProfile->gender` without checking `senderProfile` for null. The preceding `@if` only checks `senderProfile && profile_photo && photo_approved`; when we fall through to `@else`, `senderProfile` can still be null (e.g. no photo or rejected photo), but when the sender **profile itself** is missing, `senderProfile` is null and `->gender` throws.
- **sent.blade.php**: Line 96 uses `$interest->receiverProfile->gender` in the `@else` block. The `@else` is reached when `receiverProfile` is falsy or has no approved photo; if `receiverProfile` is null (receiver profile soft-deleted), `->gender` throws.

**Expected behavior:** List pages should render without throwing when a related profile is missing; show “Profile Deleted” / placeholder and no crash.

**Actual behavior:** Received (and Sent) interests page can throw `ErrorException` when the sender (or receiver) profile is missing, e.g. after soft-delete.

### Affected Files / Methods
- **resources/views/interests/received.blade.php** — `@else` block (lines 45–54): access to `$interest->senderProfile->gender` when `senderProfile` can be null.
- **resources/views/interests/sent.blade.php** — `@else` block (lines 95–104): access to `$interest->receiverProfile->gender` when `receiverProfile` can be null.

(Controller logic for Interest: **InterestController::store**, **accept**, **reject**, **withdraw**, **sent**, **received** — guards, lifecycle, and idempotency behave as required; failure is view-only.)

### Data Risk Level
**MEDIUM**  
No silent data loss and no DB corruption; interest records and profile data remain correct. Risk is **page crash (500)** and broken UX when listing interests for a user whose counterpart profile has been soft-deleted. Likely after admin/user archival or suspension with soft-delete, or data cleanup.

---

## Summary Table (Controller/Service/Model)

| Area | Result |
|------|--------|
| store() guards (auth, own profile, sender/receiver lifecycle, completeness) | PASS |
| accept() / reject() receiver lifecycle + ownership + pending | PASS |
| withdraw() sender ownership + pending | PASS |
| Double submit / wrong order | PASS (blocked or idempotent) |
| Unauthorized access | PASS (403) |
| View: null sender/receiver profile on list pages | **FAIL** (crash in Blade) |

---

*Report generated per Foundation Repair & Hardening Sprint — Cursor Testing Prompt (LOCKED). No fixes applied; findings only.*
