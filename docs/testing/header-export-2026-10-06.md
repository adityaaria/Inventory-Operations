# Header actions: no Home, Export beside Create/Import

Requirements: UI-01, VIEW-01, FIND-01. Latest user direction supersedes the earlier export-in-filters placement.

Removed Home links from all 10 views where they appeared. Existing sidebar/breadcrumb navigation and contextual links back to resource lists remain available. Shared table enhancement now puts Export CSV in the page header toolbar, alongside Create and Import when available. Dashboard's nested status table also uses the page header export action. Empty export-only filter sections and their CSS were removed. Existing server filters and adjacent table/pagination spacing remain unchanged.

The export button remains type=button, exports current rendered table rows, and is created once per table. Existing role-based write actions remain conditional. No persistence, stock, authorization or report API changes.

Checks after Docker rebuild:

- JavaScript: 41 tests passed, including updated behavior test for existing/missing header toolbar and repeated enhancement.
- PHPUnit unit suite: 218 tests, 617 assertions passed.
- PHPStan level 5: 109 files, no errors; existing age advisory.
- git diff --check: passed.
- Chrome: 13 pages at 360/1024/1440/1920px, 52 page/viewport checks passed. No Home toolbar links; one header export per datatable; no export within filters; no duplicate table search; full viewport shell, no document horizontal overflow, zero filter/table/pagination gaps, no JS exceptions. Sales/Warehouse user endpoint access remains 403.
- Eight screenshots and browser-results.json refreshed in `docs/testing/screenshots/neutral-workspace/`.

No integration suite run because persistence did not change. CSV download contents were not rechecked in this task; export generation logic was unchanged.
