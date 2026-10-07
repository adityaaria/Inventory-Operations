# Presentation guideline gap closure — 7 October 2026

Requirement IDs: TEST-01/02/03, ARCH-01/02, DB-01, SEC, UI-01. Mandatory/Optional: evidence required by `Guidelines - Presentation Final Project (Peserta).pdf` (Intermediate level), requested by the user.

| Gap against the guideline | Outcome |
|---|---|
| No SonarQube report | Local SonarQube analysis: Quality Gate passed; Security, Reliability and Maintainability rated A; 0 hotspots; coverage 69.9%. Report, screenshots, exports and reviewed issues: `docs/quality/sonarqube.md`. |
| Refactor log stale and wrong about Git | `docs/quality/refactor-log.md` extended with R-006 to R-013 (each with verification) and the Git note corrected. |
| Class diagram from 31 August | `docs/architecture/class-diagram-as-built.md` gains an additions diagram (constructor dependencies checked against code) and an inventory generated from `app/` (151 files, none missing). |
| ERD missing six tables | `database/erd.dbml` now has 21 tables matching `database/schema-and-seed.sql`, with the new ledger column `quantity_delta` and its CHECK. |
| README missing newer features | README documents the outstanding report, multi-item orders, replenishment selection, drafts, header sorting and the SonarQube procedure. |
| No backup of the demo flows | 12 screenshots from the real application in `docs/presentation/demo-cadangan/`, reproducible with `tests/Browser/demo-screenshots.cjs`. |
| Stale quality documents | `docs/quality/phpstan-report.txt` (155 files) and `docs/testing/test-results.md` refreshed from the latest run; `docs/quality/tech-debt.md` marks TD-001/TD-005 resolved and adds TD-020 to TD-023 from SonarQube. |
| Commit and push of the final version | Not done: publishing to the assessed repository is left to the user's confirmation. |

Code changes made while closing the SonarQube findings (details and reasons in `docs/quality/sonarqube.md`):

- Braces on every PHP control structure (PHP-CS-Fixer `control_structure_braces`, 61 files, behaviour unchanged).
- `require_once` for the Composer autoloader in scripts and test workers.
- Per-run credentials and TLS 1.2 minimum in `scripts/verify-capacity.py` and `scripts/verify-operations.py`.
- Visually hidden label for the PO receive quantity (new `.visually-hidden` utility).
- `replaceAll` and a shared default timer object in `public/assets/js/ui-helpers.js`.
- `scripts/coverage-report.php`, `sonar-project.properties`, PCOV measuring `views/`.
- PO and SO detail pages show "SKU — product name" instead of the product ID (visible in the demo screenshots).

Commands executed + result (final run after all changes):

- `docker compose --profile quality run --build --rm test`: **428 PHP tests / 1949 assertions OK**, PHPStan level 5 **no errors (155 files)**, **65 JavaScript tests passed**. [Log](gap-closure-2026-10-07/quality.txt).
- `python3 -m unittest discover -s tests/Operations`: 35 passed.
- Fresh disposable stacks: business HTTP 76/76, work queue 13/13, timeline 21/21, business browser 50/50 ([business](gap-closure-2026-10-07/business/)); outstanding 24/24, replenishment 15/15, draft check 7/7, draft browser 18/18, UI sweep 16 pages without findings ([sweep](gap-closure-2026-10-07/ui-sweep.json)).
- SonarQube scan as above; `git diff --check` passed.
- Localhost:8080 rebuilt: readiness ready, data unchanged (31 PO, 24 SO, 18 ledger rows), audit-trail and dashboard-low-stock checks passed.

Incident during the work: Docker Desktop stopped (all containers exited with code 255) and the session scratchpad holding the SonarQube credentials was cleared. Localhost was restarted with its data intact, and SonarQube was recreated and re-analysed; the reviewed-issue decisions were re-applied with the same justifications.

Known gaps/risks: two inventory-operation templates are not indexed by Sonar's PHP analyzer (coverage not imported; ratings unaffected); "missing blame" warning until the work is committed; physical devices not tested; D-07/D-08 await trainer confirmation.
