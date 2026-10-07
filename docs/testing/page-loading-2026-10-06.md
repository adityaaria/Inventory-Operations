# Page loading — 2026-10-06

Requirements: UI-01, ERR-01. User-requested UX enhancement using custom CSS and Vanilla JS. No persistence, stock transactions, authorization or schema changes.

Changed: modal.js delayed/reset loading lifecycle and status semantics; app.js ignores downloads, modified clicks and same-document anchors; app.css replaces dimmed fullscreen spinner with a 3px progress bar and stationary reduced-motion feedback.

Verification:
- node --test tests/JavaScript/*.test.js: 43 tests passed, including fast cancellation and repeated-start/pageshow recovery.
- Docker composer test: 250 tests, 1028 assertions passed.
- Docker PHPStan level 5 app/config/public: no errors; existing dependency-age advisory.
- Docker app rebuilt and restarted.
- Chrome DevTools: simulated pending loading stays hidden initially, appears after threshold at 3px with aria-hidden=false; native navigation to products clears it; pageshow reset and reduced-motion animation=none passed.

Limit: browser lifecycle was exercised directly because native navigation can replace the document before a delayed measurement. No claim of exhaustive browser history or network timing coverage. Native page requests still incur server/network time; no SPA or artificial navigation delay added.

## Follow-up: remaining flash

The initial indicator fix did not address the client-built layout. Navigation JS previously created the shell/sidebar and moved main content after HTML was parsed. Replaced this with workspace-start/end PHP partials in 26 authenticated templates, session-derived role presentation and drawer-only JS enhancement. Reports controller now evaluates its PHP template rather than returning raw source. Added 120ms cross-document CSS transitions when reduced motion is not requested; native fallback remains.

Files: public/index.php, app/Controller/ReportController.php, public/assets/js/navigation.js, public/assets/css/app.css, all 26 authenticated page templates, shared workspace/profile partials, navigation contract/behavior tests, WorkspaceLayoutTest, SDD/KNOWLEDGE/design-system/AI log.

Verification of final behavior:
- JavaScript: 44 tests passed. Drawer test verifies repeat initialization, open/close, aria-expanded and Escape focus return without DOM construction.
- PHPUnit: 253 tests, 1046 assertions passed (unit + integration). Three new role cases verify initial layout, active route, title escaping and restricted Users menu.
- PHPStan level 5: no errors; existing dependency-age advisory. All PHP view files passed php -l.
- Browser with JS asset requests blocked: one complete shell, sidebar/Profile and Admin menu already present. Main coordinates x=224/y=48.09375 matched the JS-enabled result at 1440px.
- Chrome: 13 routes at 360/1024/1440/1920px (52 checks), no runtime exceptions or horizontal document overflow, zero filter/table/pagination gaps, export positions and mobile drawer correct. Sales/Warehouse Users endpoint returns 403 and menu hidden. Screenshots and browser-results.json refreshed in screenshots/neutral-workspace.
- Native sidebar click Products -> Categories: pagereveal.viewTransition present, confirming the browser actually starts the native cross-document transition. First automated probe clicked before the page became paint-ready and observed no transition; retry after first paint with user gesture confirmed it.

Security and transaction invariants unchanged. No persistence or stock changes. Transition support depends on browser; native reloads and very slow navigation may skip animation. Full perceptual absence of flashing on every device is not claimed.

Reference: https://developer.chrome.com/docs/web-platform/view-transitions/cross-document
