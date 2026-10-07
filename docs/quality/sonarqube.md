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
| Coverage | 69.9% overall (PHP `app/` + `views/` 79.4% by PHPUnit/PCOV; JavaScript only where loaded as modules) |
| Duplications | 5.6% |
| Size | 12.9k lines of code |
| Unit/integration tests imported | 428, 0 failures, 0 errors |

The default gate checks new code only, so a first analysis passes it almost by definition. The overall ratings above are therefore the meaningful evidence, and they were brought to A by the work below.

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

## Reviewed issues (accepted or false positive, each with a comment in SonarQube)

| Rule | Count | Reason |
|---|---|---|
| php:S2092 session cookie without `secure` | 1 accepted | The flag is configuration-driven: on in production (`SESSION_COOKIE_SECURE=auto`), and `public/index.php` refuses to start in production without secure cookies. Off only for local HTTP, where a secure cookie would never be sent. |
| php:S3699 result of `session_write_close()` | 1 false positive | Returns `bool` since PHP 7.2; the project targets PHP 8.2+. |
| php:S2003 on value-returning `require` | 8 accepted | `$config = require config.php` must return the Config object every time; `require_once` would return `true` on a second load (for example in tests). |
| php:S2003 in `public/router.php` | 1 accepted | Built-in server router includes the front controller once per request; deliberately unchanged. |
| Web:S6845 `tabindex` on non-interactive element | 16 accepted | `tabindex="0"` is on the scrollable table region (`role="region"`, `aria-label`). WCAG 2.1.1 and the axe rule *scrollable-region-focusable* require keyboard users to reach and scroll it. |

## Remaining maintainability smells (rating A)

Largest groups: php:S112 generic `RuntimeException`/`LogicException` (49), css:S4666 duplicate selectors (47), javascript:S7721 inner functions (26), php:S1192 repeated literals (24), Web:S6819 ARIA roles where native elements exist (24), php:S3776 cognitive complexity (largest in `BusinessOperationService::propose`). They do not change behaviour; the prioritized plan is to split the proposal workflow into smaller methods, introduce domain exception types and consolidate duplicate CSS selectors (see `docs/quality/tech-debt.md`).

## Known analysis warnings

- Two templates (`views/inventory-operations/recommendations.php`, `show.php`) are not indexed by the PHP analyzer, so their coverage is not imported. Paths, encoding and Git status were checked and are normal; ratings and the gate are unaffected.
- "Missing blame information" for files not yet committed. It disappears after the work is committed.

## How to reproduce

```bash
# 1. Coverage reports (PHPUnit in the test container, JavaScript with Node)
mkdir -p var/coverage && chmod 777 var/coverage
docker compose --profile quality run --build --rm -v "$PWD/var/coverage:/var/www/html/var/coverage" test php scripts/coverage-report.php
node --test --experimental-test-coverage --test-reporter=lcov --test-reporter-destination=var/coverage/lcov.info tests/JavaScript/*.test.js

# 2. Local SonarQube (bound to localhost only; Docker Desktop with 4 GB needs the smaller heaps)
docker run -d --name ioms-sonarqube -p 127.0.0.1:9100:9000 -e SONAR_ES_BOOTSTRAP_CHECKS_DISABLE=true \
  -e SONAR_WEB_JAVAOPTS="-Xmx384m -Xms128m" -e SONAR_CE_JAVAOPTS="-Xmx512m -Xms128m" -e SONAR_SEARCH_JAVAOPTS="-Xmx384m -Xms384m" \
  sonarqube:community
# log in at http://127.0.0.1:9100 (admin/admin, change the password), create a token

# 3. Analysis with SonarScanner CLI (use the native build on Apple Silicon; the Docker scanner image is amd64-only and its JavaScript analysis times out under emulation)
SONAR_TOKEN=<token> sonar-scanner -Dsonar.host.url=http://127.0.0.1:9100
```

Configuration: `sonar-project.properties`. Credentials and tokens are never stored in the repository.
