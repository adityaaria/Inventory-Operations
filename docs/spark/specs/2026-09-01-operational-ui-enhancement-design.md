# Operational UI Enhancement Design

Date: 2026-09-01  
Status: Approved concept, pending written-spec review  
Scope: Presentation-only enhancement for the existing native PHP inventory and order management app.

## Goal

Improve the application from a plain assessment UI into a clearer operational dashboard interface for inventory, purchase order, sales order, report, and admin workflows.

## Approved Direction

Use an operational dashboard style:

- Dense but readable layout for repeated work.
- Consistent navigation across modules.
- Clear table hierarchy and row scanning.
- Status badges for order and stock states.
- Better form controls and button hierarchy.
- Responsive behavior for mobile-width review.

## Non-Goals

- Do not change business logic.
- Do not change stock transaction or ledger behavior.
- Do not change authorization rules.
- Do not add Laravel, Symfony, Slim, ORM, React, Vue, Angular, jQuery, Bootstrap, Tailwind, admin templates, or any frontend dependency.
- Do not add new mandatory or optional business workflows.

## UX Requirements

### Shell and Navigation

Every browser page should feel like one application, not unrelated standalone documents. Add a shared visual header pattern through CSS and minimal markup:

- App title visible near the top.
- Primary navigation for common modules.
- Current content area remains simple and server-rendered.
- Existing role-based authorization remains server-side; UI navigation is convenience only.

### Dashboard

Dashboard should expose metrics as compact operational cards:

- Role label.
- Inventory value.
- Low-stock rows.
- PO receipt queue.
- SO issue queue.
- Status summary tables.

The dashboard must continue reading values from services/repositories, not hardcoded totals.

### Lists

Product, purchase order, sales order, user, and master-data lists should be easier to scan:

- Filter rows should look like a real toolbar.
- Tables should use consistent header, row hover, numeric alignment where practical, and action styling.
- Status-like values should use badge classes.
- Empty states should be visible when result sets are empty.

### Forms

Forms should look consistent and be comfortable to use:

- Labels remain explicit.
- Inputs/selects/buttons share consistent sizing.
- Primary actions should be visually distinct.
- Error alerts should stay prominent and escaped.
- CSRF hidden inputs remain present.

### Responsive Behavior

At narrow widths around 360px:

- Navigation wraps cleanly.
- Filters stack vertically.
- Forms fill available width.
- Wide tables scroll horizontally inside the content surface instead of breaking text layout.
- Buttons should not overflow their containers.

## Implementation Shape

Prefer the smallest presentation layer change:

- Extend `public/assets/css/app.css` with a design system: color tokens, buttons, badges, cards, tables, filters, responsive rules.
- Add small reusable markup patterns directly in existing PHP views where needed.
- Keep all escaping through `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` or `Html::e()`.
- Do not introduce a template engine or layout system during this enhancement unless the existing duplication blocks the UI work.

## Priority Pages

1. `views/auth/login.php`
2. `views/dashboard/index.php`
3. `views/products/index.php`
4. `views/purchase-orders/index.php`
5. `views/sales-orders/index.php`
6. `views/reports/index.php`
7. Shared CRUD pages via global CSS.

## Verification

Run:

```bash
php -l` equivalent over modified PHP files
composer test
composer analyse
APP_PORT=8082 docker compose up -d --build app
curl smoke check for /login, /dashboard, /products, /purchase-orders, /sales-orders, /reports, /reports/orders.csv
```

Manual visual review:

- Desktop browser width.
- Mobile/narrow width around 360px.
- Login, dashboard, products list, PO list, SO list, reports page.

## Risks

- The current app has repeated page markup. Broad shell changes could touch many files, so this enhancement should rely heavily on CSS and only small markup updates.
- Dotted CSV routes must keep using Docker router script behavior from Phase 8.
- UI hiding must not replace server-side authorization.

## Self-Review

- Placeholder scan: no placeholder requirements remain.
- Scope check: presentation-only, no business workflow changes.
- Constraint check: no forbidden framework or dependency is introduced.
- Ambiguity resolved: choose operational dashboard style over marketing or decorative UI.
