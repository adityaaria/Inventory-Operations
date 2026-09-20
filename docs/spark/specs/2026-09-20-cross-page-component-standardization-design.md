# Cross-page Component Standardization Design

## Status

Approved design checkpoint for the cross-page UI consistency enhancement.

## Goal

Ensure every application page uses the shared design-system contracts for page shell, forms, alerts, status, tables, detail summaries, and action groups while preserving routes, business workflows, authorization, CSRF, and persistence behavior.

## Audit Findings

The audit found no UI-related architecture or authorization bypass. It found these consistency gaps:

- `403` is styled while `404` and `500` are raw HTML pages;
- create/edit views use mixed plain labels and `.field` wrappers;
- create/edit views lack a consistent page header and form action group;
- PO/SO detail tables are unclassified and can diverge from list-table presentation;
- detail pages lack a consistent summary and action-group contract;
- alerts mix generic `.alert` and semantic modifiers.

The project memory is current as of `2026-09-20`; no delta scan is required.

## Scope

Included page groups:

- error pages: `403`, `404`, `500`;
- master-data create/edit pages;
- purchase-order and sales-order create pages;
- products, categories, warehouses, suppliers, customers, users, purchase orders, and sales orders list pages;
- purchase-order and sales-order detail pages;
- dashboard and reports compatibility checks;
- authentication-page compatibility checks.

Included components:

- page header and context hierarchy;
- toolbar/back navigation;
- form fields and form actions;
- semantic alerts;
- status badges;
- detail summary;
- list and detail table contracts;
- state-changing action grouping.

## Constraints

- Keep standalone server-rendered PHP views and existing progressive Vanilla JS.
- Do not introduce a shared PHP layout refactor in this batch.
- Do not change routes, HTTP status codes, form methods/actions, field names/values, CSRF, authorization, services, repositories, queries, transactions, or business statuses.
- Keep `.data-table` enhancement behavior for list tables.
- Use `.detail-table` for PO/SO detail tables so they do not gain list search/export/selection behavior accidentally.
- Existing modal, navigation, form validation, confirmation, and table helpers remain the behavior source of truth.

## Shared Component Contracts

### Error page

- Loads `app.css` and the minimum required assets.
- Uses `main.page` as the root content surface.
- Uses `.page-header`, `.app-title`, `h1`, and a descriptive message.
- Uses a semantic alert modifier where an alert is appropriate.
- Uses a toolbar/back link with existing button hierarchy.
- Does not build the authenticated sidebar shell.
- Preserves current HTTP status and security-safe error message.

### Page header

- Uses `.page-header` for page context.
- Uses `.app-title`, heading, and optional `.page-subtitle` consistently.
- Uses `.toolbar` for navigation and page actions.
- Page actions preserve current visibility and authorization conditions.

### Form page

- Every visible field uses `.field` and `.field-label`.
- Helper copy uses `.field-hint`; field-specific errors use `.field-error`.
- Submit/cancel controls are grouped in `.form-actions`.
- Submit uses primary hierarchy; cancel/back uses `.button-quiet` unless an existing action has a stronger semantic requirement.
- Existing form method, action, CSRF field, names, values, and authorization conditions remain unchanged.

### Alert and status

- Request/page errors use `.alert-danger` unless the existing semantic meaning is informational, warning, or success.
- Order and master-data states use existing semantic status classes and vocabulary.
- Color is not the only state signal; existing text remains visible.

### List table

- List pages retain `.data-table`, existing search/sort/export/pagination contracts, and selection only where explicitly opted in.
- Existing row actions and server-side query parameters remain unchanged.

### Detail page

- PO/SO detail tables use `.detail-table` and `scope="col"` headers.
- Summary facts use `.detail-summary` label/value pairs.
- State-changing forms are grouped in `.form-actions`.
- Existing confirmation and loading behavior remains active.
- No detail-page table receives list-table enhancement accidentally.

## Accessibility Rules

- Every visible form control has a programmatic label or an existing equivalent accessible name.
- Table headers use `scope="col"` where applicable.
- Buttons and links retain visible focus through the shared focus token.
- Error messages are visible and semantically differentiated.
- Status text remains present alongside status styling.
- Error pages remain readable without JavaScript.

## Implementation Sequence

1. Add failing cross-page contract tests and inventory expected page groups.
2. Standardize error pages and verify HTTP/status rendering is untouched.
3. Standardize create/edit form markup and action groups.
4. Standardize PO/SO detail summaries, tables, statuses, and action groups.
5. Normalize alert modifiers and verify list/dashboard/report compatibility.
6. Run full automated verification and view syntax checks.
7. Update quality evidence with coverage and remaining manual browser gaps.

## Testing and Verification

Automated checks:

- DOM-free view source contract tests for all page groups;
- `node --test tests/JavaScript/*.test.js`;
- PHPUnit unit suite;
- PHPUnit integration suite;
- PHPStan;
- PHP syntax check for all views;
- `git diff --check`.

Manual smoke scope:

- each error page;
- one create and one edit page per master-data shape;
- purchase-order and sales-order create/detail actions;
- list filters, tables, pagination, status, and existing selection behavior;
- login page exclusion from app shell.

Browser automation is not configured; unavailable visual/focus checks must be documented rather than fabricated.

## Non-goals

- No shared PHP layout extraction.
- No business workflow or authorization change.
- No new status vocabulary.
- No new endpoint or persistence change.
- No redesign of information architecture.

## Gaps / Unknowns

- Standalone view duplication remains by design for this batch; layout extraction is deferred.
- Exact visual rendering across browsers still requires manual review.
- Some existing plain labels may be retained only where a compatible shared field contract would alter specialized markup; such exceptions must be documented in evidence.

## Evidence

- `.docs/PROJECT_SCAN.md`;
- `.docs/PROJECT_PROFILE.md`;
- `.docs/FEATURE_MAP.md`;
- `.docs/TESTING_STRATEGY.md`;
- `views/`;
- `public/assets/css/app.css`;
- `public/assets/js/app.js`;
- `public/assets/js/tables.js`;
- existing JavaScript and PHP tests.
