# UI Shell, Dashboard, and Datatable Enhancement Design

Date: 2026-09-01

## Goal

Upgrade the native PHP inventory app from page-by-page screens into a modern operational workspace with a sidebar, main content area, informative dashboard visuals, datatable tools, modal create/update forms, confirmation dialogs, loading states, and consistent empty states.

## Scope

- Add a reusable client-side app shell enhancement using custom CSS and Vanilla JS.
- Keep all current PHP routes, controllers, services, repositories, authorization, and transactions unchanged.
- Preserve non-JS fallback: links and forms must still navigate and submit normally if JavaScript is disabled.
- Avoid new frontend frameworks, chart libraries, CSS frameworks, admin templates, or build tooling.

## UX Design

- Desktop layout uses a fixed-width sidebar for navigation and a main content surface for each page.
- Mobile layout stacks the sidebar above content with compact navigation.
- Dashboard shows KPI cards, status distribution charts, workload panels, and quick actions.
- Master/list pages use a datatable toolbar with filter controls, client-side CSV export for visible rows, sortable columns, loading affordances, and styled empty states.
- Create and edit links open in a modal dialog through progressive enhancement. The original full-page form routes remain valid.
- Mutating actions such as submit, approve, cancel, receive, issue, activate, and deactivate use a custom confirmation dialog before POST submission.
- Buttons show a loading state during form submission. Page navigation shows a lightweight loading overlay.

## Data Flow

- Server-rendered PHP remains source of truth for data and authorization.
- Dashboard chart data is derived from already-rendered dashboard arrays, embedded as JSON on the dashboard view.
- CSV export reads the currently rendered table rows in the browser.
- Modal form content is fetched from the existing create/edit routes and submitted to existing POST routes.

## Error Handling

- If a modal form returns validation errors, the modal body is replaced with the returned form content.
- If fetch fails, the browser falls back to normal navigation for links or normal form submission for posts.
- Confirmation dialogs only block client-side submission; server-side authorization and validation remain mandatory.

## Verification

- PHP syntax lint for app, public, and views.
- Full PHPUnit regression suite.
- PHPStan level 5 analysis.
- Docker rebuild on `localhost:8082`.
- HTTP smoke for CSS, JS, dashboard, products, users, and modal/export/chart markers.
