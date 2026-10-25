# SonarQube report

Analysis date: 7 October 2026 (local SonarQube Community Build 26.9, project key `inventory-operations`).

## Result

**Quality Gate: Passed** (default "Sonar way" gate). Screenshots: [overview](sonarqube/sonarqube-overview.png), [quality gate](sonarqube/sonarqube-quality-gate.png), [reviewed issues](sonarqube/sonarqube-reviewed-issues.png). Raw API exports: [quality-gate.json](sonarqube/quality-gate.json), [measures.json](sonarqube/measures.json), [reviewed-issues.json](sonarqube/reviewed-issues.json), [open-issue-facets.json](sonarqube/open-issue-facets.json).

| Measure (overall code) | Value |
|---|---|
| Security | 0 open issues, rating **A** |
| Reliability | 0 open issues, rating **A** |
| Maintainability | 280 open code smells, rating **A** |
| Security hotspots | 0 (review rating A) |
| Coverage | 69.9% overall on 7 October (PHP `app/` + `views/` 79.4% by PHPUnit/PCOV; JavaScript only where loaded as modules); 78.4% after the 9 October updates below |
| Duplications | 5.6% |
| Size | 12.9k lines of code |
| Unit/integration tests imported | 428, 0 failures, 0 errors |

The default gate checks new code only, so a first analysis passes it almost by definition. The overall ratings above are therefore the meaningful evidence, and they were brought to A by the work below.

## Update 9 October 2026: new-code coverage

The 7 October analysis became the New Code baseline. The enhancement work was committed (`276281b`) a few minutes after it, so Sonar's SCM blame counted those 1,670 lines as new code. Re-analysis gave **63.7% new-code coverage** and a failed gate (≥ 80% required). The gap was not missing tests but missing measurement: the DOM code in `form-drafts.js`, `order-items.js` and `inventory-operations.js` is exercised by the Chrome DevTools Protocol suites, whose coverage was never recorded.

| Change | Effect |
|---|---|
| `tests/JavaScript/ui-helpers.test.js`: tests for `escapeHtml` (all five characters, double escaping, non-strings) and `debounce` with real timers | JS unit tests 65 → 67, all pass; `ui-helpers.js` fully covered except the browser-only export branch |
| `tests/Browser/js-coverage.cjs`: second CDP client on the same Chrome tab, polling `Profiler.takePreciseCoverage` every 100 ms and writing per-line LCOV for `/assets/js/*.js` | Real browser execution measured; existing harnesses unchanged |
| `scripts/merge-lcov.cjs`: sums per-line hits from the Node and browser reports into `var/coverage/lcov.info` | One JavaScript report for Sonar (line coverage only; Node branch records are not carried over) |

Browser runs on a disposable stack (`scripts/verify-business.py --keep-running`, localhost:18086, served JS byte-identical to the repository): `tests/Browser/business-enhancements.cjs` 50/50, `tests/Browser/form-drafts.cjs` 18/18, `tests/Browser/ui-sweep.cjs` 16 pages without failures. Stack, volumes and Chrome were removed afterwards.

Result: **Quality Gate Passed** with new-code coverage **84.5%** (264 of 1,702 lines uncovered), 0 new issues and 1.1% new duplication. Overall coverage is 78.9%, with Security, Reliability and Maintainability all rated A and 0 hotspots. Exports: [quality-gate-2026-10-09.json](sonarqube/quality-gate-2026-10-09.json), [measures-2026-10-09.json](sonarqube/measures-2026-10-09.json). PHP coverage reused the 7 October Clover report because no PHP file changed at that point (it was regenerated for the accepted-issues update). Remaining uncovered new lines are mostly bootstrap wiring in `public/index.php`, the audit-trail, work-queue and timeline pages (covered only by HTTP suites, which run without PCOV), and dialog/navigation branches that no suite reaches.

## Update 9 October 2026: accepted issues fixed in code

25 of the 27 reviewed issues were removed by changing the code rather than keeping an exception. Accepted or false-positive issues: **27 → 2**. Gate still **Passed** (new-code coverage 84.5% of 1,765 lines, 0 new issues). Overall coverage 78.4%, all ratings A, 0 hotspots. Exports: [reviewed-issues-2026-10-09.json](sonarqube/reviewed-issues-2026-10-09.json), [quality-gate-2026-10-09.json](sonarqube/quality-gate-2026-10-09.json), [measures-2026-10-09.json](sonarqube/measures-2026-10-09.json).

| Issue | Was | Fix |
|---|---|---|
| Web:S6845 `tabindex` on table regions (16 indexed, 18 templates) | Accepted | Static `tabindex="0"` removed from templates. `InventoryTables.watchScrollRegions()` (`tables.js`, called from `app.js`) adds it only while the region actually overflows and re-checks on resize (`ResizeObserver`, `resize` fallback). Keyboard access (WCAG 2.1.1) is kept where it matters and non-scrolling tables leave the tab order. Checked in Chrome: `/products`, `/purchase-orders` and `/reports` have a tab stop at 360 px, none at 1440 px, and regain it after resizing 1440 → 360. Unit test in `tests/JavaScript/tables.test.js`. |
| php:S2003 value-returning `require` of `config/config.php` / `config/bootstrap.php` (9) | Accepted | `Config::fromEnvironment()` and `ApplicationBootstrap::boot()` (autoloaded) replace the returned values. `public/index.php`, CLI scripts and `tests/Support/large-dataset.php` call them directly. `config/*.php` remain as thin wrappers for shell one-liners used by the HTTP suites. Unit tests in `BootstrapSmokeTest` cover environment defaults and the global handler (logged, API error body hides the message). |
| php:S2003 in `public/router.php` | Accepted | `require_once`: the built-in server resets the included-files list per request, so the front controller still runs on every request. |

Kept, with comments in SonarQube:
- **php:S2092** (session cookie without `secure`): the flag must stay configuration-driven. Hard-coding it would break login over local HTTP. Production refuses to start without it.
- **php:S3699** (false positive): `session_write_close()` returns `bool` since PHP 7.2, and the check is the only way to detect a failed session write.

Verification: PHPUnit 430 tests / 1,956 assertions, PHPStan level 5 with no errors, JS 68 tests. Disposable stack: business HTTP 76/76, `business-enhancements.cjs` 50/50, `form-drafts.cjs` 18/18, `ui-sweep.cjs` 16 pages without failures (also the source of the browser coverage above). Stack and Chrome were removed afterwards.

## Starting point and fixes

First analysis (same day, before fixes): Security rating D (9 vulnerabilities), Reliability C (137 bugs; 23 reliability issues in Sonar's multi-quality view), 501 code smells, coverage 58.5%.

| Finding | Decision | Change |
|---|---|---|
| python:S2068 hard-coded passwords (6) | Fixed | Verification scripts generate per-run credentials with `secrets.token_urlsafe()` (`scripts/verify-capacity.py`, `scripts/verify-operations.py`). |
| python:S4423 weak TLS defaults (2) | Fixed | Test TLS contexts set `minimum_version = TLSv1_2`. |
| php:S121 / php:S2681 unbraced or misleading one-line control structures (≈197) | Fixed | PHP-CS-Fixer `control_structure_braces` across 61 files, run once from Docker (no new dependency); behaviour unchanged, full regression re-run. |
| php:S2003 `require` vs `require_once` for the Composer autoloader (8) | Fixed | `require_once` for `vendor/autoload.php`. |
| Web input without label (1) | Fixed | PO receive quantity has a visually hidden `<label>`. |
| javascript:S7781, S7737, S7721 (8) | Fixed | `replaceAll` for literal replacements; shared default timer object in `ui-helpers.js`. |
| Coverage reported per container path | Fixed | `scripts/coverage-report.php` writes project-relative paths; PCOV now also measures `views/`. |
| php:S2003 on templates and render methods (≈120) | Rule scoped out | Templates are rendered repeatedly in one process (a sort heading per column, 422 re-renders, many renders per unit test); `require_once` would print nothing the second time. Ignored only for `views/**` and controller render methods in `sonar-project.properties`. |

## Reviewed issues on 7 October (accepted or false positive, each with a comment in SonarQube)

All but S2092 and S3699 were later fixed in code; see the 9 October update above.

| Rule | Count | Reason |
|---|---|---|
| php:S2092 session cookie without `secure` | 1 accepted | The flag is configuration-driven: on in production (`SESSION_COOKIE_SECURE=auto`), and `public/index.php` refuses to start in production without secure cookies. Off only for local HTTP, where a secure cookie would never be sent. |
| php:S3699 result of `session_write_close()` | 1 false positive | Returns `bool` since PHP 7.2; the project targets PHP 8.2+. |
| php:S2003 on value-returning `require` | 8 accepted | `$config = require config.php` must return the Config object every time; `require_once` would return `true` on a second load (for example in tests). |
| php:S2003 in `public/router.php` | 1 accepted | Built-in server router includes the front controller once per request; deliberately unchanged. |
| Web:S6845 `tabindex` on non-interactive element | 16 accepted | `tabindex="0"` is on the scrollable table region (`role="region"`, `aria-label`). WCAG 2.1.1 and the axe rule *scrollable-region-focusable* require keyboard users to reach and scroll it. |

## Update 9 October 2026: all remaining issues refactored

Final analysis: **0 open issues** (218 before this step), **Quality Gate Passed**, Security / Reliability / Maintainability **A**, 0 bugs, 0 vulnerabilities, 0 hotspots, technical debt 0 min (debt ratio 0.0%), overall coverage **80.0%**, new-code coverage 81.1%, duplication 5.5%. Accepted: 1 (php:S2092, unchanged) plus the S3699 false positive. Exports: [quality-gate-2026-10-09.json](sonarqube/quality-gate-2026-10-09.json), [measures-2026-10-09.json](sonarqube/measures-2026-10-09.json), [open-issue-facets-2026-10-09.json](sonarqube/open-issue-facets-2026-10-09.json).

Refactorings R-014 to R-019 in `docs/quality/refactor-log.md`: typed exceptions, extracted steps for every method above cognitive complexity 15 (including `BusinessOperationService` and `StockService`, same validation order and transaction boundaries), `RequestOrigin` and signed ledger quantity instead of 8-parameter methods, `OperationalReportSql`, `App\Http\View` with explicit template data, merged CSS selectors, native `<section>`/`<dialog>`/`<output>` instead of ARIA roles, and capture-free JavaScript helpers moved to each file's IIFE. The earlier "deliberately left" items below were resolved this way: unused `$request` parameters were removed (the router still passes the request), template variables are passed explicitly, and `\w` is used with `re.ASCII` so it matches exactly `[A-Za-z0-9_]`.

How "no visual change" was verified:
- **CSS merge:** every element's computed style (and `::before`/`::after`, plus a forced hover/focus pass) was compared in Chrome before and after, on 31 pages for guest/Admin/Sales/WarehouseStaff at 360/768/1100/1440 px. Each of the 46 duplicate groups was first tested on its own. 41 merge into the later rule and 5 into the earlier one. The result had zero differences. A Chrome-reported `-webkit-tap-highlight-color` value that changed between identical snapshots was excluded as noise after an identical-stylesheet control run.
- **Element swaps:** box and computed style of every replaced element were compared before and after. The comparison caught one CSS rule keyed on `[role="status"]`, which was retargeted. The only remaining difference, audit-table height, came from different audit data and was confirmed by swapping `section` back to `div` in place (same height).

Verification after the refactor:
- **Unit and static:** PHPUnit 430 tests / 1,959 assertions, PHPStan level 5 with no errors, JavaScript 68, `tests/Operations` 35.
- **HTTP:** business 76/76, work queue 13/13, timeline 21/21, outstanding 24/24, replenishment 15/15, draft check 7/7.
- **Browser:** business 50/50, drafts 18/18, UI sweep 16 pages, dialog e2e 110 checks, mobile e2e 303 pages across 9 sizes, table tab stop at 360/1440 px.
- **End-to-end verifiers (both refactored):** `scripts/verify-operations.py --business-flows` 29/29 (backup, encrypted replica, restore, production TLS, monitor alerts, load) and `scripts/verify-capacity.py` passed (10,000 products / 20,000 orders, paging, CSV, load, PHP memory). Disposable stacks and volumes were removed.

Both runs used freshly built images. Evidence is in ignored `var/` directories, not in `docs/testing`.

## Update 9 October 2026: maintainability quick wins

Open code smells **280 → 218** (technical debt 2,539 → 2,026 minutes, debt ratio 0.7% → 0.5%, rating A). HIGH-impact smells 53 → 27. Gate **Passed** (new-code coverage 82.7%, 0 new issues). Overall coverage 79.0%. Facets: [open-issue-facets-2026-10-09.json](sonarqube/open-issue-facets-2026-10-09.json).

| Rule | Fixed | Change |
|---|---|---|
| php:S3358, javascript:S3358, python:S3358 nested ternaries | 14 | `match` or plain `if` in `StockLedgerEntry::delta`, `ReportController`, `public/index.php` (secure-cookie setting, HTTP and fallback error responses), sort heading, order line names, profile menu, navigation icons, report date range, operation status badge, form-drafts checkbox value, operations-job exit code |
| php:S1192 repeated literals | 21 | `INDEX_PATH`/`SHOW_PATH` constants in nine controllers; message constants in `BusinessOperationService` and `StockService`; list and create views reuse one partial path variable |
| Web:S5255 unlabeled navigation | 3 (28 elements) | Every page toolbar `<nav>` has `aria-label="Page actions"` |
| JavaScript small rules (S7761, S7768, S7755, S6582, S6353, S7754, S2681, S2004, S2486) | 17 | `dataset`, `after()`/`before()`, `.at(-1)`, optional chaining, `\d`, `some()`, braces on the restore early return, a named `hide` callback in the confirm dialog, comments on ignored storage errors |
| PHP/Python small rules (S6353, S1186, S108, S1481, S6035) | 7 | `\d` in PHP patterns, comment on the empty in-memory `lockCreation()`, explicit loop body in the backup integrity read, `_` loop index, character class |

Deliberately left:
- **php:S1481 (18) and php:S1172 (12):** false positives for this design. Controller locals are read by the `require`d template, and handlers keep the uniform router signature `(Request $request)`.
- **python:S6353 (2):** `\w` would also accept Unicode letters in database identifiers, so `[A-Za-z0-9_]` stays.
- **php:S1185:** `ValidationException` keeps its constructor so the message stays required.
- **Larger refactors after the presentation:** S112 domain exceptions (49), S4666 duplicate CSS selectors (47), S3776 cognitive complexity (15, mainly `BusinessOperationService`), S6819 ARIA roles (24), S7721 inner functions in the IIFE modules (26).

Verification: PHPUnit 430 / 1,956, PHPStan level 5 no errors, JS 68, Python operations 35. Disposable stack: business HTTP 76/76, work queue 13/13, timeline 21/21, outstanding 24/24, replenishment 15/15, draft check 7/7, browser business 50/50, drafts 18/18, UI sweep 16 pages without failures, table tab-stop check at 360/1440 px.

## Remaining maintainability smells (rating A, before the 9 October quick wins)

Largest groups: php:S112 generic `RuntimeException`/`LogicException` (49), css:S4666 duplicate selectors (47), javascript:S7721 inner functions (26), php:S1192 repeated literals (24), Web:S6819 ARIA roles where native elements exist (24), php:S3776 cognitive complexity (largest in `BusinessOperationService::propose`). They do not change behaviour; the prioritized plan is to split the proposal workflow into smaller methods, introduce domain exception types and consolidate duplicate CSS selectors (see `docs/quality/tech-debt.md`).

## Known analysis warnings

- Two templates (`views/inventory-operations/recommendations.php`, `show.php`) are not indexed by the PHP analyzer, so their coverage is not imported. Paths, encoding and Git status were checked and are normal; ratings and the gate are unaffected.
- "Missing blame information" for files not yet committed. It disappears after the work is committed.

## How to reproduce

```bash
# 1. Coverage reports (PHPUnit in the test container, JavaScript with Node)
mkdir -p var/coverage && chmod 777 var/coverage
docker compose --profile quality run --build --rm -v "$PWD/var/coverage:/var/www/html/var/coverage" test php scripts/coverage-report.php
node --test --experimental-test-coverage --test-reporter=lcov --test-reporter-destination=var/coverage/lcov-node.info tests/JavaScript/*.test.js
# Browser coverage: start headless Chrome with --remote-debugging-port=9225, then per harness:
#   node tests/Browser/js-coverage.cjs http://127.0.0.1:9225 var/coverage/lcov-browser-<name>.info &   # before the harness
#   node tests/Browser/<harness>.cjs ...                                                              # stops on Browser.close or SIGINT
node scripts/merge-lcov.cjs var/coverage/lcov.info var/coverage/lcov-node.info var/coverage/lcov-browser-*.info

# 2. Local SonarQube (bound to localhost only; Docker Desktop with 4 GB needs the smaller heaps)
docker run -d --name ioms-sonarqube -p 127.0.0.1:9100:9000 -e SONAR_ES_BOOTSTRAP_CHECKS_DISABLE=true \
  -e SONAR_WEB_JAVAOPTS="-Xmx384m -Xms128m" -e SONAR_CE_JAVAOPTS="-Xmx512m -Xms128m" -e SONAR_SEARCH_JAVAOPTS="-Xmx384m -Xms384m" \
  sonarqube:community
# log in at http://127.0.0.1:9100 (admin/admin, change the password), create a token

# 3. Analysis with SonarScanner CLI (use the native build on Apple Silicon; the Docker scanner image is amd64-only and its JavaScript analysis times out under emulation)
SONAR_TOKEN=<token> sonar-scanner -Dsonar.host.url=http://127.0.0.1:9100
```

Configuration: `sonar-project.properties`. Credentials and tokens are never stored in the repository.
