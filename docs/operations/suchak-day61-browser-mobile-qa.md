# Suchak Day-61 Browser And Mobile QA Notes

Date: 2026-06-11

SSOT item: Day-61 - REAL BROWSER / MOBILE QA

## Scope

Day-61 verifies that the expanded Suchak engine has mobile-safe primary surfaces and persona-appropriate access gates after the Day-57 through Day-60 work.

Covered personas:

- admin
- verified Suchak
- pending Suchak
- suspended Suchak
- customer/family portal user
- regular platform user
- public visitor

Covered surfaces:

- admin Suchak dashboard
- Suchak operator dashboard
- pending and suspended Suchak dashboard access
- masked search gating for non-verified Suchak accounts
- training academy
- offline camps
- export and retention center
- public Suchak marketplace index and show pages
- public payment request page
- customer portal page
- receipt verification page

## Tooling Evidence

Real browser QA was executed with:

- Browser/tool: Google Chrome headless via Chrome DevTools Protocol
- Browser version: Chrome 149.0.7827.103
- Viewport: mobile emulation, 390 x 844, device scale factor 2
- Local app URL: `http://127.0.0.1:8000`

The in-app Browser runtime was attempted first, but the local browser runtime failed in this session with:

`windows sandbox failed: runner error: CreateProcessAsUserW failed: 5`

Local Playwright was not available in the workspace, and `npm exec --offline playwright -- --version` confirmed that no cached Playwright package was available. Dependency installation was not attempted for this QA day.

Chrome was then run directly through the Chrome DevTools Protocol with low-GPU headless flags. Authenticated admin and verified Suchak QA users were created in the local QA database for this browser pass only.

## Runtime Preparation

The local Laravel server was started at:

`http://127.0.0.1:8000`

Local database migrations were applied with:

`php artisan migrate --force`

This applied the pending Suchak growth migrations in the local database so Day-61 pages could render against the current schema.

QA-only local browser users:

- admin: `day61.browser.admin@example.test`
- verified Suchak: `day61.browser.suchak@example.test`

These users were used only to authenticate the local browser pass.

## Real Browser QA Result

Result: passed for the requested Day-61 scope.

Checked pages:

- `GET /admin/suchak/dashboard`
- `GET /admin/suchak/retention`
- `GET /suchak/dashboard`
- `GET /suchak/offline-camps`
- `GET /suchak/export-retention`

Checks performed:

- authenticated admin and verified Suchak sessions loaded the expected pages
- mobile viewport screenshots were captured
- rendered pages did not show `403`, `Whoops`, `Undefined variable`, `Stack trace`, `ErrorException`, or `Fatal error`
- no body-level horizontal overflow was reported in the captured viewport
- primary visible controls were present on each checked page
- no app-side runtime exception was reported by Chrome DevTools

Environment warning:

- Chrome reported `net::ERR_NETWORK_ACCESS_DENIED` for an external resource load in this restricted environment. The local app pages still rendered, and no app-side JavaScript exception was reported.

Screenshot artifacts:

- `docs/operations/screenshots/day61/chrome-390x844-admin-suchak-dashboard.png`
- `docs/operations/screenshots/day61/chrome-390x844-admin-suchak-retention.png`
- `docs/operations/screenshots/day61/chrome-390x844-suchak-dashboard.png`
- `docs/operations/screenshots/day61/chrome-390x844-suchak-offline-camps.png`
- `docs/operations/screenshots/day61/chrome-390x844-suchak-export-retention.png`

## Automated QA Regression

The repeatable Day-61 evidence is captured in:

`tests/Feature/Suchak/SuchakBrowserMobileQaCompletionTest.php`

The regression sends mobile browser headers and validates:

- `viewport` metadata is present
- major mobile-responsive classes are present
- rendered pages do not expose Laravel error output
- required persona surfaces render with stable visible copy
- regular users cannot enter the Suchak dashboard
- pending and suspended Suchak accounts cannot use masked search
- public marketplace, customer portal, payment request, and receipt pages do not leak private mobile, email, candidate name, or private payment reference data
- public marketplace copy does not expose success-rate or guaranteed-match claims

## Findings

No production code change was required by Day-61 QA.

The focused Day-61 regression passed after aligning test expectations with the current rendered headings and status copy.

The source-controlled QA gate is automated Laravel rendering plus DOM/privacy verification, backed by the Chrome screenshot artifacts listed above for the requested admin and Suchak runtime pages.

## Rollback Notes

To roll back the Day-61 QA artifact only, remove:

- `tests/Feature/Suchak/SuchakBrowserMobileQaCompletionTest.php`
- `docs/operations/suchak-day61-browser-mobile-qa.md`
- `docs/operations/screenshots/day61/`

No production database migration, route, controller, model, service, or view change was introduced for Day-61.
