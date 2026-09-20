# Core Interaction Design System Enhancement

## Status

Approved design checkpoint. This specification covers the first design-system enhancement batch for the Inventory & Order Management System.

## Goal

Improve the consistency, clarity, accessibility, and reuse of the core interaction components used throughout the server-rendered PHP views:

- buttons and action groups;
- form fields and validation states;
- alerts and request errors;
- badges and operational statuses;
- modal, import, and confirmation dialogs.

The visual direction is **Operational clarity**: interactions should be easy to scan, status should be unambiguous, and destructive or asynchronous actions should communicate their state clearly.

## Project Constraints

- Keep PHP 8.2+, native OOP, server-rendered HTML, custom CSS, and Vanilla JS.
- Do not add a frontend framework, CSS framework, icon dependency, or design-system package.
- Keep the existing Controller -> Service -> Repository architecture untouched.
- Do not change routes, authorization, CSRF handling, order workflows, or stock transaction boundaries.
- Preserve compatibility with current view markup while migrating components incrementally.
- Keep business terminology and business rules in feature views/services; component CSS/JS must remain business-neutral.

Evidence:

- `AGENTS.md`
- `.docs/PROJECT_PROFILE.md`
- `.docs/FEATURE_MAP.md`
- `public/assets/css/app.css`
- `public/assets/js/forms.js`
- `public/assets/js/form-validation.js`
- `public/assets/js/modal.js`
- `public/assets/js/dialog.js`

## Current Baseline

The project already has shared CSS tokens for surfaces, text, borders, semantic colors, spacing, radius, and shadows. Existing interaction classes include `.button`, `.button-primary`, `.form`, `.field-error`, `.alert`, `.status-badge`, `.modal-panel`, `.confirm-panel`, and `.is-loading`.

Vanilla JS already provides:

- client-side field validation;
- `aria-invalid` and `aria-describedby` wiring;
- submit loading and disabled behavior;
- retryable request errors;
- modal focus trapping;
- Escape-to-close behavior;
- focus restoration;
- confirmation dialogs;
- asynchronous modal form loading.

The enhancement should consolidate and clarify these existing patterns rather than replace them.

## Component Contract

### Tokens

Use semantic tokens for component states instead of page-specific literals. The token layer should cover:

- primary and primary-hover interaction colors;
- accent color;
- success, warning, danger, info, and neutral states;
- state background surfaces;
- focus ring color and opacity;
- control height, spacing, radius, and transition duration;
- component shadow levels.

Existing approved palette values remain the source of truth unless a token is explicitly introduced to resolve an interaction-state mismatch. Token names must describe meaning, not a specific page or domain.

### Buttons

Supported semantic variants:

- `.button`: neutral/secondary action;
- `.button-primary`: primary submit or create action;
- `.button-danger`: destructive action;
- `.button-quiet`: low-emphasis action where supported;
- `.is-loading`: asynchronous action state.

Every variant must define default, hover, focus-visible, disabled, and loading behavior. Loading must preserve the action’s meaning, disable repeat submission, and expose a visible progress state.

Existing `button[type="submit"]` behavior must remain compatible during migration.

### Form fields

The preferred semantic structure is:

```html
<label class="field">
    <span class="field-label">Name</span>
    <input name="name" aria-describedby="name-hint name-error">
    <span class="field-hint" id="name-hint">Optional supporting text.</span>
    <span class="field-error" id="name-error" role="alert">Name is required.</span>
</label>
```

The existing direct-label markup remains supported while views migrate. Invalid controls must retain `aria-invalid="true"` and reference the generated error message through `aria-describedby`.

### Alerts

Use one base `.alert` component with semantic variants:

- `.alert-info`;
- `.alert-success`;
- `.alert-warning`;
- `.alert-danger`.

Alerts must remain readable without relying on color alone. Request errors and server-rendered validation messages should share the same visual structure and retry/action placement.

### Badges and status

Keep `.status-badge` as the base class and use semantic state modifiers for active, pending, approved, received, fulfilled, cancelled/inactive, low stock, normal stock, and draft states.

Status text remains supplied by the domain view. CSS only owns visual treatment. Existing aliases such as `status-pendingapproval`, `status-partiallyreceived`, and `stock-low` remain compatible while canonical modifiers are introduced.

### Modal and dialog

The modal contract remains:

- `.modal-backdrop` or feature dialog backdrop;
- `.modal-panel` / `.confirm-panel`;
- `.modal-header`;
- `.modal-body`;
- optional `.modal-footer` or `.confirm-actions`.

Required behavior:

- `role="dialog"` and `aria-modal="true"`;
- a valid accessible title reference;
- focus moves into the dialog;
- Tab focus is trapped while open;
- Escape closes the dialog;
- focus returns to the trigger after close;
- loading and request errors are visible inside the dialog;
- reduced-motion users do not receive mandatory animation.

## Visual and Accessibility Rules

- Semantic color is assigned by meaning, not by feature page.
- Focus-visible treatment is consistent across buttons, links, and form controls.
- Text and labels remain explicit when color or motion is unavailable.
- Disabled and loading states must be visibly distinct from default states.
- Interactive targets remain usable on narrow screens.
- Transitions are short and must respect `prefers-reduced-motion`.
- No new icon dependency is required; text and semantic markup remain the fallback.

## Migration Plan

Implementation proceeds in vertical slices:

1. Normalize semantic tokens and interaction-state utilities.
2. Standardize button variants and action-group spacing.
3. Standardize form controls, field hints, validation, and alert states.
4. Normalize status badge modifiers while retaining legacy aliases.
5. Improve modal, import-dialog, and confirmation-dialog shell states.
6. Migrate representative views: login, products, purchase orders, sales orders, and users/import.
7. Search for remaining legacy selectors and literals, then classify each as migrated, compatible, or intentionally retained.

No database, repository, service, controller, route, or authorization changes are part of this batch.

## Testing and Verification

### Automated checks

- `composer test:unit`
- `composer test:javascript`
- `composer analyse`
- existing view escaping and CSRF tests

### Behavior checks

- Submitting a form enters loading/disabled state and prevents duplicate submission.
- Client validation focuses the first invalid control and associates its error text correctly.
- Server/request errors render consistently and retain retry behavior.
- Modal Escape, focus trap, focus restoration, and retry behavior remain functional.
- Legacy status classes continue to render while canonical classes are introduced.
- No endpoint, authorization rule, CSRF requirement, or stock/order invariant changes.

### Manual smoke scope

Inspect login, dashboard, products, purchase-order list/detail, sales-order list/detail, and user CSV import at desktop and narrow viewport widths. Verify default, hover, keyboard focus, invalid, loading, disabled, empty/error, and modal states where applicable.

## Non-Goals

- No full app-shell redesign in this batch.
- No table/filter/pagination redesign beyond compatibility with the core controls.
- No new theme switcher or dark mode.
- No backend API or database changes.
- No replacement of the existing modal implementation with a third-party library.
- No change to business status vocabulary or workflow semantics.

## Open Implementation Decisions

The implementation plan must choose exact token values only where current tokens do not express the approved operational states. Any new value must be documented next to its semantic token and verified for contrast. Existing approved palette values must not be silently replaced.

## Gaps and Unknowns

- Browser-level end-to-end automation is not configured; manual smoke verification remains required for modal and focus behavior.
- The current stylesheet contains a few legacy literal colors and status aliases; each must be audited during implementation before removal.
- Shared PHP view layout extraction is out of scope, so component migration remains view-local.

## Evidence

- `.docs/PROJECT_PROFILE.md`
- `.docs/FEATURE_MAP.md`
- `.docs/TESTING_STRATEGY.md`
- `public/assets/css/app.css`
- `public/assets/js/forms.js`
- `public/assets/js/form-validation.js`
- `public/assets/js/modal.js`
- `public/assets/js/dialog.js`
- `views/products/index.php`
- `views/products/create.php`
- `views/purchase-orders/show.php`
- `views/sales-orders/show.php`
- `views/users/index.php`
