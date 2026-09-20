# Design System Fase 1 — Known Gaps

Date: 2026-09-20
Scope: Core interaction plus data-heavy and layout/navigation enhancements:
semantic tokens, buttons, forms, alerts, status badges, modal/dialog, tables,
filters, pagination, dashboard data states, application shell, sidebar,
responsive drawer, skip link, page headers, toolbars, typography scale, and
active palette aliases, native form controls, and presentation-only table selection.
Cross-page component standardization now also covers error pages, form pages,
order detail pages, shared action groups, and semantic alerts.
Blocked on: `design-system.md`, `components.css`, `js/table-select.js` (referenced by
`refactor-instructions-specific.md` but not present in this repository).

## Visible mismatches introduced by this phase

- Some isolated legacy color literals remain in status and validation surfaces;
  they are intentionally retained because their semantic meaning and contrast
  need a separate palette decision.
- Global focus ring is now derived from `--focus-ring`; browser-level contrast and keyboard smoke verification remain manual.
- Responsive drawer, focus return, scroll lock, and narrow-viewport layout still require manual browser smoke verification because browser-level E2E automation is not configured in this repository.
- Typography and active palette changes also require manual browser review for exact visual contrast because browser-level visual regression is not configured.
- Products selection, select-all mixed state, and bulk-action bar still require manual browser keyboard smoke; the bar intentionally has no business mutation action.
- Exact visual parity across all page groups still requires manual browser review; browser automation is not configured.

## Implemented in this slice

- Added `--primary-hover`, `--focus-ring`, `--motion-fast`, and `--motion-normal`.
- Added reduced-motion behavior for all transitions/animations.
- Added `.button-danger`, `.button-quiet`, `.field`, `.field-label`, `.field-hint`, and semantic alert variants.
- Added canonical status modifiers while preserving legacy order/stock aliases.
- Added `.modal-footer` and modal close-button focus treatment without changing dialog lifecycle behavior.
- Added data-heavy tokens/states for tables, filtered-empty, data error, metrics, panels, and dashboard charts.
- Added accessible `aria-sort`, table search labeling, filtered-empty rows, pagination `aria-current`, and chart roles.
- Added application-shell navigation accessibility: active route `aria-current`, skip link, mobile drawer/backdrop, Escape close, focus restoration, scroll lock, and responsive page/dashboard layout.
- Added typography tokens (`--fs-*`, `--fw-*`) and migrated primary headings, controls, navigation, tables, metadata, and metric values to the shared scale.
- Added active palette aliases for page/auth backgrounds, surfaces, accent soft/border, shadows, and spinner track; migrated identified blue/teal gradients and hover surfaces.
- Standardized native select/checkbox styling and added a presentation-only selection helper for the Products table with select-all, indeterminate state, selected count, and labelled bulk-action region.
- Standardized error pages (`403`, `404`, `500`), create/edit form fields and actions, PO/SO detail summaries/tables/actions, and generic request-error alerts across the view inventory.
- Added `tests/JavaScript/design-system.test.js`, `tests/JavaScript/tables.test.js`, `tests/JavaScript/charts.test.js`, `tests/JavaScript/navigation.test.js`, `tests/JavaScript/selection.test.js`, and `tests/JavaScript/view-components.test.js`; the current JavaScript suite contains 41 passing tests.
- Docker verification for this enhancement: PHPUnit unit 89 tests/361 assertions, integration 14 tests/62 assertions, PHPStan 108 files with no errors, and all view syntax checks passed.

## Deferred entirely (not started)

- Font-size/font-weight token scale (`--fs-*`/`--fw-*`) — none exist yet;
  `refactor-instructions-specific.md` gave 4 example sizes (badge 11px,
  table 13px, page title 20px, big number 24px) without a complete scale or
  token names.
- Future work remains for a complete palette audit, business semantics for bulk actions, a shared PHP layout extraction, and genuinely new components such as a toggle control.

## Remaining external references

`design-system.md`, `components.css`, and `js/table-select.js` are not present
in this repository. They are not required for the completed table/dashboard
slice, but would be useful references before adding new toggle or bulk-action
components.
