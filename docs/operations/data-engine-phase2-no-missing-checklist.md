# Data Engine Phase-2 No-Missing Checklist

Date: 2026-05-07

## Scope

This checklist covers Phase-2 regrouping work for:

- `resources/views/layouts/admin.blade.php`
- `resources/views/admin/data-engine/index.blade.php`
- `resources/views/admin/data-engine/issues.blade.php`
- `resources/views/admin/data-engine/comparisons.blade.php`
- `resources/views/admin/data-engine/workflows.blade.php`
- `resources/views/admin/data-engine/rollback.blade.php`

## What Was Added (Additive Only)

- Sidebar Data Engine submenu with direct entries to key operational pages.
- Overview page grouped into section blocks:
  - Control
  - Governance
  - Health and Ops
  - Live Runtime and Guidance
  - History
- Issue Center grouped issue actions into collapsible blocks:
  - Simulation and safety details
  - Fix actions
- Comparisons, Workflows, and Rollback pages grouped into collapsible sections.
- Explicit submit type added for governance-profile jump buttons on:
  - Overview page
  - Issue Center

## CTA Inventory (Critical Paths)

### Navigation and entry points

- Data Engine sidebar submenu links:
  - Overview
  - Comparisons
  - Issue center
  - Workflows
  - Rollback center
  - Audit trail
  - System health
  - Data lineage
  - Data integrity
  - Reports
  - Governance profile
- "Open governance profile" entry points:
  - Overview quick action input
  - Issue Center quick action input
  - Comparisons report header and row-level links
  - Silent-loss and repeater-alert links on overview

### Governance profile action buttons

- Rebuild snapshot
- Re-run comparison
- Check API parity
- Refresh coverage summary
- Re-run section check
- Open full form (wizard)
- Open public profile
- Open issue center (fix)
- Open rollback center

## Runtime Verification Summary

Verified in browser session:

- Admin login successful.
- Overview, Issue Center, Comparisons, and Governance profile pages load correctly.
- Sidebar Data Engine submenu entries visible and route-highlight works.
- Issue Center "Open governance profile" button navigates to profile route.
- Governance profile tabs are interactive and render without raw JavaScript leakage.
- Browser console has no functional JS errors for these flows (only Vite connect logs).

## Safety/Regression Checks

- Blade syntax checks passed for modified files.
- Lint checks passed for modified files.
- Route list confirms required Data Engine and governance profile routes exist.

## Remaining Before Full Completion

- Execute and record each governance action button result as pass/fail matrix:
  - success toast/message visibility
  - failure visibility (if applicable)
  - badge/status refresh confirmation
- Produce final per-page click-map table with explicit pass/fail rows for every visible CTA.
- Capture final screenshot set for each page state in final report packet.

## Conclusion

Phase-2 regrouping is implemented and stable, but full project completion still requires action-execution proof and final click-map audit closure.

