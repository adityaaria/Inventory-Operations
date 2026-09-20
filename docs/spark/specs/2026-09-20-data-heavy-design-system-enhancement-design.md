# Data-heavy Design System Enhancement

## Status

Approved design checkpoint for the second design-system enhancement batch.

## Goal

Standardize data-heavy presentation and interaction states across Products, Purchase Orders, Sales Orders, Reports, and Dashboard while preserving existing server-side queries, pagination semantics, roles, routes, and business workflows.

The visual direction remains **Operational clarity**: users should be able to scan data, understand current state, recover from errors, and navigate large result sets without ambiguity.

## Scope

Included components:

- data tables and sortable headers;
- table toolbar, search, and export action;
- server-side filter panels;
- pagination summary and controls;
- loading, empty, filtered-empty, and error states;
- dashboard metric cards, panels, charts, and quick actions;
- responsive behavior for narrow viewports;
- keyboard and screen-reader state communication.

Target views:

- `views/products/index.php`;
- `views/purchase-orders/index.php`;
- `views/sales-orders/index.php`;
- `views/reports/index.php`;
- `views/dashboard/index.php`.

## Constraints

- Keep PHP 8.2+, custom CSS, server-rendered HTML, Vanilla JS, and Fetch API.
- Do not add a chart library, frontend framework, CSS framework, or new runtime dependency.
- Do not change repositories, services, queries, API routes, authorization, CSRF, or business status vocabulary.
- Keep `tables.js` and `charts.js` progressive enhancements; server-rendered HTML remains usable if JavaScript fails.
- Preserve current pagination parameters, filter parameters, role scoping, and CSV export behavior.

Evidence:

- `AGENTS.md`
- `.docs/FEATURE_MAP.md`
- `.docs/TESTING_STRATEGY.md`
- `public/assets/css/app.css`
- `public/assets/js/tables.js`
- `public/assets/js/charts.js`
- target files under `views/`.

## Shared Component Contract

### Data table

`.data-table` remains the base table class. It must support:

- readable header and row hierarchy;
- sortable headers with default, ascending, and descending states;
- keyboard activation for sortable headers;
- `aria-sort` communication for the active sort column;
- horizontal overflow on narrow screens;
- an empty row that remains meaningful without JavaScript;
- generated filtered-empty state when client-side search hides all rows.

### Table toolbar

`.table-toolbar` contains `.table-search` and optional table actions such as export. Search input labels must be accessible, persisted search values must remain scoped to the current path/table, and toolbar actions must remain usable on narrow screens.

### Filter panel

`.filters` remains the server-side filter form. Its visual contract includes:

- clear grouping of query, select, and submit controls;
- consistent focus and invalid states;
- active filter values preserved through pagination and sorting;
- a responsive single-column layout on narrow screens;
- a distinction between no dataset and no results for the current filters.

### Pagination

`.pagination` contains a summary, current-page indicator, and previous/next actions. Active page uses `aria-current="page"`; unavailable actions use `aria-disabled="true"` and are not links. Existing URL generation and page calculations remain unchanged.

### Shared states

- `.loading-card` and `.table-loading`: data or action is in progress.
- `.empty-state`: no records exist or a widget has no data.
- `.filtered-empty`: records exist but current search/filter returns none.
- `.data-error`: data could not be loaded or processed; retry/action text is explicit when supported.

Empty, filtered-empty, and error states must have distinct copy and visual treatment. Color alone must not communicate the difference.

### Dashboard

- `.metric-card` presents a label and numeric value with stable hierarchy.
- `.dashboard-panel` groups a chart or table with a clear heading.
- `.chart` renders existing `data-chart` data without a new library.
- Empty chart data uses `.empty-state`.
- Chart data remains understandable through accompanying text/table where already present.
- `.quick-panel` and `.quick-list` retain existing navigation semantics while receiving the shared action hierarchy.

## State and Accessibility Rules

- Loading must not cause avoidable layout shifts.
- Empty and filtered-empty must not look like server errors.
- Error content must be visible and actionable when retry is available.
- Sort direction must be expressed through `aria-sort`, not only CSS arrows.
- Search inputs must have accessible labels.
- Pagination must expose current page and disabled actions to assistive technology.
- Tables may scroll horizontally on narrow viewports; content must not be clipped.
- Filter controls and metric cards stack appropriately below the existing responsive breakpoint.
- Animations are optional and must respect `prefers-reduced-motion`.
- Existing JS enhancement failures must not remove the server-rendered table, filter, pagination, or dashboard content.

## Implementation Sequence

1. Add the semantic CSS/JS contract tests for table, toolbar, filters, pagination, states, and dashboard components.
2. Normalize shared data-display tokens and table/toolbar styling.
3. Improve `tables.js` accessibility and state behavior without changing search/sort/export semantics.
4. Standardize filter and pagination markup while preserving existing query strings and role scope.
5. Add explicit loading, empty, filtered-empty, and error styles.
6. Migrate Products, Purchase Orders, Sales Orders, and Reports.
7. Migrate Dashboard metric cards, panels, charts, and quick actions.
8. Verify responsive and keyboard behavior.
9. Update design-system quality evidence.

## Testing and Verification

Automated checks:

- `node --test tests/JavaScript/*.test.js`;
- focused `tables.js` tests for search, sorting, keyboard activation, empty results, and CSV export;
- `composer test:unit`;
- `composer test:integration` as a regression check;
- `composer analyse`;
- PHP syntax check for all `views/*.php`;
- `git diff --check`.

Manual smoke scope:

- Products search/filter/table/pagination/export;
- Purchase Order and Sales Order status table/filter/pagination;
- Reports filter forms;
- Dashboard metric cards, chart data, empty chart, data table, and quick actions;
- narrow viewport layout and keyboard focus.

## Non-goals

- No query/repository/service changes.
- No pagination behavior changes.
- No new chart library.
- No app-shell/sidebar redesign.
- No endpoint, permission, authorization, CSRF, or business-flow changes.

## Gaps / Unknowns

- Browser-level E2E automation is not configured; modal and responsive smoke checks remain manual.
- Current chart rendering is CSS/HTML based and has no dedicated visual regression harness.
- Some legacy color literals in the stylesheet remain outside this batch and should not be removed without a separate palette audit.

## Evidence

- `.docs/PROJECT_PROFILE.md`
- `.docs/FEATURE_MAP.md`
- `.docs/TESTING_STRATEGY.md`
- `public/assets/css/app.css`
- `public/assets/js/tables.js`
- `public/assets/js/charts.js`
- `views/products/index.php`
- `views/purchase-orders/index.php`
- `views/sales-orders/index.php`
- `views/reports/index.php`
- `views/dashboard/index.php`
