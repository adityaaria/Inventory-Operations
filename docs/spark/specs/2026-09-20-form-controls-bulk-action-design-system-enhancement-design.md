# Form Controls & Bulk Action Design System Enhancement

## Status

Approved design checkpoint for the fifth design-system enhancement batch.

## Goal

Standardize native select, checkbox selection, and the presentation of bulk actions so data-heavy pages have consistent controls and accessible selection feedback without introducing new business mutations.

## Scope

Included components:

- native select styling in `.field`, `.filters`, and `.form`;
- checkbox selection contract for eligible data tables;
- select-all header checkbox semantics;
- reusable bulk-action bar presentation;
- selected count, loading, error, disabled, and empty-selection states;
- keyboard and screen-reader behavior.

Target files will be limited to existing CSS, Vanilla JS, tests, and one representative view only if a concrete table selection use case is identified during planning.

## Constraints

- Keep native `<select>` and checkbox controls; no custom dropdown or third-party component.
- Do not change field names, values, form submission, routes, authorization, services, repositories, queries, stock workflows, or database behavior.
- Bulk-action bar is initially presentation-only; it must not trigger receipt, issue, approval, delete, adjustment, transfer, or other business mutations.
- Any future mutation must be a separate approved enhancement with server-side authorization and domain-service validation.
- Preserve progressive enhancement: server-rendered tables remain usable without JavaScript.

## Shared Component Contract

### Native select

- `.field`, `.filters`, and `.form` selects share surface, border, spacing, typography, disabled, invalid, and focus-visible treatment.
- Native browser selection behavior remains unchanged.
- Existing labels, `name`, `value`, required state, and query parameters remain unchanged.

### Checkbox selection

- A selection-enabled table uses a stable row checkbox class and a header select-all checkbox.
- Row checkboxes expose an accessible name tied to the row identity.
- Header select-all exposes an accessible name describing the visible table rows.
- Select-all supports checked, unchecked, and indeterminate states.
- Selection state is client-side presentation state only in this batch.

### Bulk-action bar

- Hidden when no rows are selected.
- Visible when at least one row is selected.
- Shows the selected count as text.
- Exposes a labelled region for assistive technology.
- Supports disabled, loading, and error presentation states.
- Does not invent or execute an action when no action is explicitly available.
- Destructive actions, if presented in a future batch, must use the existing confirmation dialog.

## State and Accessibility Rules

- Every checkbox must have an accessible label or `aria-label`.
- Select-all must expose mixed state through the native `indeterminate` property and a meaningful label.
- Bulk-action status must not rely on color alone.
- Keyboard focus remains visible for select, checkbox, and action controls.
- Space toggles a focused checkbox; Tab reaches all controls in predictable order.
- Errors and loading states remain readable and do not remove the selected count unexpectedly.
- Authorization is never inferred from enabled/disabled UI; server-side validation remains authoritative.

## Implementation Sequence

1. Add failing CSS/source contract tests for select, checkbox, and bulk-action hooks.
2. Standardize select and checkbox CSS using existing design tokens.
3. Add a small progressive selection helper only if an existing representative table is suitable.
4. Add the bulk-action bar presentation without mutation handlers.
5. Add focused tests for selection count, select-all indeterminate state, and accessible attributes.
6. Run the complete regression suite and syntax checks.
7. Update quality evidence and document that business actions remain deferred.

## Testing and Verification

Automated checks:

- `node --test tests/JavaScript/*.test.js`;
- focused source-contract tests for controls and selection behavior;
- PHPUnit unit and integration suites in Docker;
- PHPStan in Docker;
- PHP syntax check for all views;
- `git diff --check`.

Manual smoke scope:

- select controls in product/order/report filters;
- checkbox keyboard focus and Space interaction;
- select-all checked/unchecked/indeterminate state;
- selected count and bulk-action bar visibility;
- no action mutation when the bar is used.

Browser-level smoke remains manual where browser automation is unavailable.

## Non-goals

- No custom dropdown component.
- No new business action or endpoint.
- No stock mutation, approval, delete, receipt, issue, adjustment, or transfer behavior.
- No authorization rule change.
- No broad table redesign beyond the selection contract.

## Gaps / Unknowns

- A concrete table target for selection must be chosen during planning based on existing markup and an actual UI need.
- Bulk-action business semantics and permissions remain intentionally undefined until a separate requirement is approved.
- Browser visual and keyboard smoke remains unavailable in the current environment.

## Evidence

- `.docs/PROJECT_PROFILE.md`;
- `.docs/FEATURE_MAP.md`;
- `public/assets/css/app.css`;
- existing table views and `public/assets/js/tables.js`;
- `tests/JavaScript/design-system.test.js`.
