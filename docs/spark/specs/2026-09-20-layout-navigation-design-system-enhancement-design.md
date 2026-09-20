# Layout & Navigation Design System Enhancement

## Status

Approved design checkpoint for the third design-system enhancement batch.

## Goal

Improve the shared application shell, navigation, page headers, toolbars, and responsive layout so users can move between operational pages consistently on desktop, tablet, and mobile without changing routes, permissions, business workflows, or persistence behavior.

The visual direction remains **Operational clarity**: navigation should be predictable, page context should be obvious, and content should remain usable when the viewport becomes narrow.

## Scope

Included components:

- application shell and sidebar navigation;
- active navigation state;
- mobile navigation drawer and backdrop;
- page header and toolbar layout;
- skip link and main-content landmark;
- dashboard responsive grid;
- keyboard focus, Escape handling, and reduced-motion behavior.

Target runtime files:

- `public/assets/css/app.css`;
- `public/assets/js/navigation.js`;
- `public/assets/js/app.js` only if the existing initialization contract requires it;
- existing server-rendered pages consumed by the shell.

## Constraints

- Keep PHP 8.2+, custom CSS, server-rendered HTML, Vanilla JS, and Fetch API.
- Do not add a frontend framework, CSS framework, new runtime dependency, or browser-only requirement for baseline rendering.
- Do not change repositories, services, queries, API routes, authorization, CSRF, session behavior, or business status vocabulary.
- Preserve progressive enhancement: pages remain server-rendered and usable if navigation JavaScript fails.
- Keep the existing `InventoryNavigation.buildShell()` entry point and route matching behavior unless a compatibility-preserving internal refactor is required.

Evidence:

- `AGENTS.md`;
- `.docs/FEATURE_MAP.md`;
- `.docs/TESTING_STRATEGY.md`;
- `public/assets/css/app.css`;
- `public/assets/js/navigation.js`;
- `public/assets/js/app.js`;
- representative files under `views/`.

## Shared Component Contract

### Application shell

The generated shell continues to use `.app-shell`, `.sidebar`, `.brand`, `.side-nav`, `.main-content`, and `main.page`.

- Desktop uses a persistent sidebar and a flexible content column.
- Mobile uses the same sidebar as an off-canvas drawer.
- The drawer has a stable `id` and is controlled by a menu button with `aria-controls` and `aria-expanded`.
- A backdrop closes the drawer when activated.
- Opening the drawer prevents background page scrolling on narrow viewports.
- Closing the drawer restores focus to the menu button.
- Escape closes an open drawer.
- The shell is not built for authentication pages.

### Navigation

- Existing navigation items, labels, URLs, and route matching remain unchanged.
- The active route is communicated visually and with `aria-current="page"`.
- The navigation retains its `nav` landmark and accessible label.
- The mobile menu button has an accessible name and visible focus state.
- Keyboard users can reach every navigation item and close the drawer without a pointer.

### Skip link and main content

- A skip link is available as the first actionable control in the shell.
- The primary page content has a stable `id` targeted by the skip link.
- Focus indicators use the existing focus-ring token and remain visible against shell surfaces.

### Page header and toolbar

- `.page-header` remains the shared context container for page title, subtitle, and actions.
- `.toolbar` maintains a clear relationship between filters, search, and primary actions.
- Desktop layouts may be horizontal; tablet layouts may wrap; mobile layouts stack controls without clipping.
- Primary actions retain the existing semantic button hierarchy.
- Page content uses consistent horizontal padding and a readable maximum width.

### Dashboard and responsive content

- Existing `.dashboard-grid`, `.dashboard-panel`, `.metric-card`, and `.quick-panel` semantics remain intact.
- Dashboard columns collapse from desktop to tablet to a single-column mobile layout.
- Tables and other wide content may scroll horizontally rather than overflow the viewport.
- Responsive changes are CSS-only wherever possible; no route or data-loading behavior changes.

## State and Accessibility Rules

- Active navigation must not rely on color alone; `aria-current` is required.
- Drawer state must be represented by `aria-expanded` and a matching visual state.
- Escape and backdrop interactions must be safe when the drawer is already closed.
- Focus must not disappear when the drawer closes.
- Reduced-motion users must not receive animated transitions that ignore `prefers-reduced-motion`.
- The shell must not trap keyboard users on desktop or when JavaScript is unavailable.
- Mobile drawer behavior must not alter authorization; it only changes presentation of already-authorized links.
- No new global body state may leak after drawer close.

## Implementation Sequence

1. Add semantic contract tests for shell, navigation, active state, skip link, drawer controls, and responsive class hooks.
2. Add shared layout tokens and desktop shell/header/toolbar refinements.
3. Extend `navigation.js` with skip link, mobile drawer, backdrop, Escape handling, and focus restoration.
4. Add responsive CSS for drawer, page header, toolbar, dashboard grid, and content overflow.
5. Verify all existing pages still build the shell correctly and authentication pages remain excluded.
6. Update design-system quality evidence.

## Testing and Verification

Automated checks:

- `node --test tests/JavaScript/*.test.js`;
- focused navigation contract tests for shell creation, active route, drawer controls, Escape, backdrop, and focus restoration;
- `composer test:unit`;
- `composer test:integration` as a regression check;
- `composer analyse`;
- PHP syntax check for all `views/*.php`;
- `git diff --check`.

Manual smoke scope:

- desktop sidebar navigation and active route;
- mobile menu open/close, backdrop, Escape, focus return, and scroll lock;
- page header and toolbar at narrow viewport;
- dashboard grid and wide table behavior;
- authentication page remains without the application shell;
- keyboard focus visibility and skip link.

## Non-goals

- No route or URL changes.
- No new navigation item, permission, role, or authorization rule.
- No business-flow, API, repository, service, or database changes.
- No replacement of the current sidebar with a different information architecture.
- No visual redesign of product branding beyond shell/layout consistency.

## Gaps / Unknowns

- Browser-level E2E automation is not configured; responsive and focus smoke checks remain manual where no browser tool is available.
- The mobile breakpoint is an implementation detail and may be tuned within the existing responsive conventions without changing the component contract.
- A future palette audit may address remaining legacy color literals separately.

## Evidence

- `.docs/PROJECT_PROFILE.md`;
- `.docs/FEATURE_MAP.md`;
- `.docs/TESTING_STRATEGY.md`;
- `public/assets/css/app.css`;
- `public/assets/js/navigation.js`;
- `public/assets/js/app.js`;
- representative files under `views/`.
