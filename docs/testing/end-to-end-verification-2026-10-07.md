# End-to-end verification — 7 October 2026

Requirement IDs: AUTH-01/02, USR-01, PRD-01, WH-01, PO-01, SO-01; related VIEW/FIND/DASH/REPORT/API/VAL/ERR/UI/DB/JOB/ARCH/TEST requirements mapped in KNOWLEDGE.md.
Mandatory/Optional: mandatory functional correctness and regression verification; no optional business feature or trainer policy added.

## Outcome and defects closed

No failures remain in the functional scenarios executed below. This is bounded test evidence, not a guarantee that every possible bug has been eliminated.

- PO/SO product selection previously showed only ten products because it reused paginated listing queries. A dedicated active-product repository query now supplies the complete picker.
- Invalid/repeated PO/SO state transitions previously produced HTTP 500. Services now return validation failures (422), with quantities, source state and ledger unchanged.
- Failed user/master/order forms lost attempted values. Safe scalar values now survive validation; escaped output, passwords and CSRF handling remain protected. Malformed operation IDs are rejected and cannot become a fabricated audit entity ID.
- Newly created/imported products and warehouses lacked zero stock pairs, hiding eligible products from low-stock reporting. Creation and import now initialize the complete matrix atomically through StockService. Existing positive balances remain untouched; zero initialization creates no fictitious ledger movement. Concurrent catalog creation is serialized and rollback tested. See ADR-006.

Files changed: controllers for users/master data/PO/SO; ProductRepository contract and implementations; StockCatalog repository contract and implementations; ProductService, WarehouseService, StockService, PO/SO services, transaction manager; FormState, RequestAuditRecorder; fourteen create/edit views; public/index.php; seed schema and initialize-stock-balances CLI; HTTP/browser/concurrency drivers and unit/integration regressions. Supporting documentation: README, KNOWLEDGE, SDD, class diagram and ADR-006. Earlier unrelated working-tree changes were preserved.

Security/authorization: tests cover Admin/Sales/Warehouse access and Sales ownership, server-side forbidden mutations, live role/account revocation, CSRF, malformed IDs, API unauthenticated responses and logout. Existing role requirements are preserved.
Transaction/invariants: receipt/issue locks, source transitions, ledger and audit remain atomic; rejected and competing operations cannot oversell or duplicate fulfillment. Catalog initialization shares the import transaction owner and locks catalog creation deterministically. Main stock before/after: 1,857 total units, 18 ledger rows, 60 of 60 stock pairs; positive-balance hash unchanged.

## Executed verification

| Check | Result |
|---|---|
| PHPUnit unit + isolated MySQL integration | 329 tests, 1,400 assertions passed |
| PHPStan level 5 | No errors |
| JavaScript | 50 tests passed |
| HTTP end-to-end on final runtime image | 199/199 passed |
| Browser page/role/viewport | 303 checks, zero errors |
| Browser dialogs | 110 checks, zero errors |
| Main application session smoke | 16/16 passed |
| PHP syntax in final running image | 158 files passed |
| Low-stock CLI | Exit 0 |
| Main stock repair invariants | Unchanged quantities/ledger; complete matrix |
| git diff --check | Passed |

Tests added/updated: 23 additional PHP test cases compared with the prior session baseline, including four real parallel PHP-worker/MySQL scenarios: duplicate issue, competing issue with insufficient stock, competing partial receipts, and concurrent product/warehouse creation. Browser coverage includes 320px through desktop and landscape sizes, drawer focus/inert behavior, local table scrolling, fitting controls and shared dialog validation/layout. Browser verification used Chrome emulation, not physical-device/Safari testing.

HTTP flow exercises login, permissions, user lifecycle, all master-data CRUD/validation/deactivation, product selection, PO draft/order/partial/full receipt and cancellation, SO ownership/submit/approve/issue/cancellation, insufficient-stock rollback, ledger/audit, filtering/pagination, dashboard/report CSV/date scope, imports with atomic rollback, API and logout. Business mutations run only in a guarded disposable Compose project, not the user's application dataset.

Commands executed + result:

- `docker compose --profile quality run --build --rm test`: fresh dependency download failed with GitHub DNS timeout; retained genuine failure output.
- Offline verification build using existing verified PHP/vendor/Node image and the identical composer.lock hash, then `docker compose --profile quality -f compose.yaml -f /private/tmp/inventory-e2e-quality-image.yaml run --rm test`: full quality gate passed.
- `python3 tests/HTTP/end-to-end.py --project inventory-e2e-verify --url http://localhost:18085 --output docs/testing/end-to-end-2026-10-07/http-final-image.json`: passed.
- Browser CDP drivers in tests/Browser against the final isolated image: passed.
- Offline runtime build and `docker compose up -d --no-build app`: final source deployed locally without reseeding the application database.
- `docker compose exec -T app php scripts/initialize-stock-balances.php`, `php scripts/check-low-stock.php`, recursive PHP lint and main HTTP session smoke: passed.

Docs/evidence updated: this report, ADR-006, architectural and requirement notes; raw output, reproduction results, screenshots and main stock snapshots in [end-to-end-2026-10-07](end-to-end-2026-10-07/). Initial HTTP reproduction recorded 10 failures/168 checks; expanded catalog reproduction recorded 7 failures/198 checks. Final 199 checks all pass. Historical outputs remain as evidence, not current failures.

Known gaps/risks: a fresh internet dependency build remains unverified after the recorded DNS failure; the offline gate verifies current source against matching locked dependencies already available locally. Physical-device/Safari checks and sustained load benchmarks are not covered. Production HTTP worker/TLS, backup/restore/log operations, trainer ambiguities, human review and release commit/tag evidence remain separate release tasks. No trainer approval is inferred.
Recommended next task: production deployment readiness and backup/restore validation; rerun the clean dependency build when network resolution is available.
