# Mobile fit — 2026-10-06

Requirement IDs: UI-01, VIEW-01, FIND-01, ERR-01. Mandatory responsive usability, refined at the user's request. Native PHP templates/custom CSS/Vanilla JS retained; no framework or dependency introduced.

Baseline:
- 29 routes at 320px and 768px (58 checks). Ten list/detail routes made main content horizontally scroll even though the document itself appeared contained: products, users, categories, warehouses, suppliers, customers, PO/SO lists, PO/SO detail.
- Evidence: screenshots/mobile-fit/baseline-results.json.

Implemented:
- All 12 table-bearing views render an accessible, named, keyboard-focusable table-scroll region directly in PHP. Wide tables scroll locally rather than shifting the page; tables already fitting the region need no horizontal scroll.
- Filter/table/pagination remain adjacent, including corner styling through the new wrappers. Table data, sort, export and form semantics remain intact.
- Up to 960px the workspace uses the mobile drawer, including tablets. Menu and breadcrumb share one compact header row. Toolbar actions fit a two-column grid; form controls span available width; summary/detail/graph/pagination layouts shrink or stack appropriately.
- Touch controls use 44px minimum heights; text inputs/selects use 16px text to avoid small input sizing on mobile. Long headings, labels and summary values wrap without expanding the page.
- Drawer has an explicit Close button, closed-menu inert/aria-hidden state, focus entry/return, Tab containment, Escape/backdrop close and media-query reset. Resize does not steal focus from the page. Profile remains at the bottom or reachable through drawer scroll on short viewports.
- Dialog panels use dynamic viewport bounds, contained scroll and a sticky separated action footer on mobile. Background page scroll is locked while any dialog is open. Cancel/Submit remain between left/right below separator.

Changed files:
- public/assets/css/app.css; public/assets/js/navigation.js
- views/partials/workspace-start.php
- table wrappers in dashboard, products, users, categories, warehouses, suppliers, customers, PO/SO index + detail and Reports templates (12 views)
- tests/JavaScript/navigation-behavior.test.js (extended existing behavior test)
- SDD/KNOWLEDGE/design-system/AI log; this report and original browser artifacts.

Security/transactions:
- Server role checks, CSRF, form actions and data queries unchanged. Hiding/inert state only affects navigation presentation. No stock, ledger, persistence/schema or transaction changes.

Commands and final results:
- node --test tests/JavaScript/*.test.js: 46 passed. Extended drawer test covers inert state, open/close focus, Tab/Shift+Tab containment and desktop restoration.
- Docker PHPUnit Unit suite: 243 tests / 702 assertions passed.
- Docker PHPStan level 5 app/config/public: 110 files, no errors; pre-existing version-age advisory remains.
- All PHP views passed php -l.
- Docker app built/restarted, final CSS/header synchronized to running app.
- git diff --check passed.
- Integration tests were not rerun: persistence and SQL unchanged.

Browser coverage:
- 29 routes at nine sizes: 320x568, 360x800, 390x844, 430x932, 568x320, 844x390, 768x1024, 960x600 and 1440x900. Covers all 26 authenticated templates, stock-report variant, login and actual 404.
- 500 template rendered directly in the browser at each size (template geometry check, not a simulated server failure).
- Additional Sales/Warehouse dashboard/products/SO/report/actual Users 403 checks at 320px and 768px. Total: 290 page/role/viewport checks.
- No document/main horizontal overflow; visible non-table form controls fit and meet 44px target heights. Every table is in a named local region. Zero measured filter/table/pagination gaps.
- Drawer bounds/focus/close/inert tested; horizontal table scrolling moves only the region, not page or main.
- Long heading/field label/select option stress case at 320px passed. Initial test fixture incorrectly tried an Admin-only create page as Warehouse; changed the stress page to Reports rather than altering authorization.
- 110 dialog checks at 320x568, 390x844, 568x320, 768x1024 and 1440x900: create/edit/import/confirmation/server validation, one header, separated between footer, panel fit, Cancel close and no runtime exceptions.
- Product dialog footer visibility checked separately: portrait bottom 827 within 844px, landscape bottom 307 within 320px; footer stays below header.
- 22 original PNG screenshots saved. Product mobile header/filter, form, drawer and landscape dialog visually inspected.

Evidence: screenshots/mobile-fit/browser-results.json, dialog-results.json, baseline-results.json and PNGs in the same directory.

Limits: Chromium emulation verified; real iOS/Android devices and physical soft-keyboard behavior were not exercised. Normal vertical page scrolling remains necessary for long forms/datasets; wide tables intentionally scroll inside their region.
