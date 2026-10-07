# Neutral workspace design system

Updated: 2026-10-06. Requirements: UI-01, VIEW-01, FIND-01, DASH-01; authorization and logout retained under AUTH-01/02 and USR-01.

## Direction

User supplied an agency dashboard image as the visual reference. Adopt its white workspace, muted sidebar, compact navigation/icons, fine borders, modest component rounding, small uppercase panel labels and restrained status colors. The user's follow-up requires the authenticated workspace to fill the browser: no outer margin, maximum-width cap, enclosing border or rounded frame. Preserve internal content padding. Use the application's inventory terminology and actual role-scoped aggregates. Revenue trends, conversion rates, notification buttons, avatars of real people, and other sample-image features are not introduced without corresponding requirements and data.

## Implementation

- `public/assets/css/app.css`: shared semantic tokens and neutral workspace component rules. Gray canvas `#e5e4e2`, white surfaces, warm gray secondary surfaces `#f5f4f2`, text `#202120`, muted text `#686b68`, borders `#e3e3e0`, dark primary actions. Green/amber/red remain semantic status colors rather than decorative primary accents.
- Native system font stack; no downloaded font or UI package. Compact type scale, tabular metric numbers, soft shadows and 6–12px corner radii.
- `views/partials/workspace-start.php`, `workspace-end.php`, `profile-menu.php`: server-rendered Operations/Management navigation, original inline SVG icons, breadcrumb, active route and Profile. Role comes from the authenticated session via presentation context in public/index.php. Server guards remain authoritative. `public/assets/js/navigation.js` only enhances the mobile drawer; it does not rebuild or move page layout.
- Profile remains at the bottom with no separator/disclosure arrow. Its existing POST/CSRF logout form is preserved. The JS-disabled PHP fallback remains usable.
- Filters, datatables and pagination stay adjacent with zero component gaps; duplicate Search table remains removed. Export CSV is in the page header toolbar beside Create/Import, following the latest user instruction; pages without those actions still show export in the header. Redundant Home buttons are removed; sidebar/breadcrumb navigation remains available.
- All five Management lists use the same pagination presentation with fixed 10-row server pages and Previous/Next controls, including empty/single-page lists. Full list data for dropdowns is independent of displayed pages.
- Dashboard chart styling becomes monochrome while preserving actual status counts and accessible labeling. Total PO/SO metric cards sum the existing role-scoped aggregate maps; no query or stock changes and no fabricated statistics.
- Responsive shell uses the mobile/tablet drawer up to 960px. Tables remain horizontally scrollable inside page content; the document itself must not overflow. Existing reduced-motion rules remain effective.

The neutral workspace rules are kept in one labeled section after legacy responsive rules so they form the final cascade. Future component edits should update these shared rules rather than add per-page styling.

## Verification

- JavaScript suite: 41 passed; JS syntax and git diff checks passed. Updated the palette contract test to expect a neutral body background.
- PHPUnit unit suite: 218 tests, 617 assertions passed after final rebuild.
- PHPStan level 5: 109 files, no errors; existing dependency age advisory remains.
- Docker app rebuilt; MySQL stayed healthy.
- Chrome 154 via DevTools protocol: 13 authenticated pages at 360px, 1024px, 1440px and 1920px (52 page/viewport checks), workspace starts at x=0/y=0 and spans the viewport, no JS runtime exceptions, no document horizontal overflow, no duplicate table search, zero measured filter/table/pagination gaps, mobile drawer opens.
- Pages checked: dashboard, products, product create, PO list, SO list, reports, users, categories, warehouses, suppliers, customers, PO detail and SO detail.
- Sales and Warehouse Staff dashboard/navigation checked: Profile present, Users menu absent, GET /users returns 403.
- Eight original screenshots captured. Dashboard desktop/mobile and product form screenshots visually reviewed. Browser measurements are stored alongside screenshots.

Evidence: `docs/testing/screenshots/neutral-workspace/browser-results.json` and PNGs in that directory. Preview: [dashboard desktop](../testing/screenshots/neutral-workspace/dashboard-1440.png), [dashboard mobile](../testing/screenshots/neutral-workspace/dashboard-360.png), [products desktop](../testing/screenshots/neutral-workspace/products-1440.png), [PO desktop](../testing/screenshots/neutral-workspace/purchase-orders-1440.png).

Limits: persistence is unchanged, so integration tests were not rerun. Not every modal, form validation error or keyboard path was exercised in the browser. The reference guides styling rather than introducing its unrelated business features. No framework, template, ORM, dependency or backend layer was added.

### Navigation loading

UI-01 / ERR-01: native navigation uses a non-blocking 3px top indicator after 180ms. Fast requests do not flash a full-page overlay. Repeated starts preserve the timer; cancellation and `pageshow` reset it, including restored pages. Download links, modified clicks and same-page anchors do not start page loading. The indicator exposes a status and uses a stationary bar with reduced motion. Native routing and modal fetch behavior remain in place.

### First-paint stability (follow-up)

All 26 authenticated templates render the shared shell directly in PHP. Reports evaluates its PHP template instead of sending raw source. CSS opts into native cross-document transitions (120ms) for users without reduced motion; browsers without support retain native navigation. No fetch-based routing or third-party dependency is introduced. Source: https://developer.chrome.com/docs/web-platform/view-transitions/cross-document .

Validation: browser main coordinates before scripts and after scripts matched at x=224, y=48.09375 on a 1440px viewport. Complete sidebar/Profile present with JS asset requests blocked. 52 page/viewport checks and role access checks passed.

### Dialog layout

Each dialog has one header. Fetched page headings become the dialog title; subtitles remain as descriptions. Initial load and 422 validation use the same renderer. Form, import and confirmation actions use a full-width footer below a separator, Cancel left / Submit right, including mobile. Dialog forms omit the page form card padding/shadow. Evidence: ../testing/dialog-ui-2026-10-06.md.

### Reports workspace

Reports reuses Dashboard metric-grid, dashboard-panel and chart styling for real report summaries. Four cards/two charts respond to selected report/date range; detail filters, scrollable table and pagination stay adjacent. Header CSV exports all matching records server-side and opts out of generated client export. Role-aware stock availability, Sales own-order scope, empty and invalid-input states are preserved. Evidence: ../testing/reports-enhancement-2026-10-06.md and screenshots/reports/.

Report detail Status cells reuse the existing order status-badge/tone classes (normal, warning, success, danger), including pending/partial aliases. CSV values remain plain status text.

### Mobile fit across the application

At <=960px use a compact Menu/breadcrumb row and drawer with Close/focus/inert handling. Toolbars use fitting grids, form controls have 16px text/44px targets, long text wraps, pagination stays contained, and dialogs use viewport bounds with sticky action footers. All table-bearing templates render a named table-scroll region; horizontal movement is isolated to wide tables. Native table semantics and existing export/sort behavior remain intact. Evidence: ../testing/mobile-fit-2026-10-06.md.

### Audit remediation

The drawer uses a single canonical <=960px breakpoint; obsolete 760px drawer definitions and duplicate final header media blocks were removed. Supported list headings use full-result server sort links; remaining local headings advertise current-page scope. Paginated table export reads “Export Current Page”; Reports retains full filtered server export. Product detail reuses the existing workspace/detail/table components. Real mobile devices remain a verification gap; see audit remediation evidence.

UI follow-up 2026-10-07: interactive text and buttons carry no underline; affordance comes from button styling (`.button`, `.action-link`, toolbar links) or, for record links inside tables, semibold weight with a hover background. Sorting uses header arrows only (↕ inactive, ↑/↓ active, `aria-sort` and a descriptive `aria-label`); there is no separate Order filter, and filter forms carry the active sort as hidden fields. Action, Select and blank headers never sort (`data-no-sort` opts out). Filters must be the element directly before `.table-scroll` (dialogs and helper text go elsewhere; selection forms bind checkboxes with the `form` attribute) so the table attaches to the filter panel and pagination. Document detail pages place Transaction Timeline in the header toolbar beside the back link. Evidence: ../testing/ui-ux-2026-10-07.md.
