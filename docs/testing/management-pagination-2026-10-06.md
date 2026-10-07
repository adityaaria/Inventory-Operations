# Management pagination

Requirements: FIND-01, VIEW-01, UI-01, ARCH-01; USR-01 authorization preserved.

## Behavior and boundaries

Users, Categories, Warehouses, Suppliers and Customers now use fixed 10-row server-side pagination through Controller → authorized Service → Repository contract. MySQL implements COUNT plus prepared SELECT with integer-bound LIMIT/OFFSET. Users sort by id; other lists sort by name then id to break ties. Existing full list methods remain available for dropdowns and dependencies.

Pagination value object validates query page input and clamps to available pages before computing offset. Empty lists render page 1 of 1 with disabled Previous/Next. Huge, malformed, negative and array page inputs remain safe. Shared pagination partial retains query parameters and escapes labels/URLs. Import-error rendering uses the same paginated listing path, preserving 422 and import feedback.

Authorization matches previous behavior: Users is Admin-only; master-data reading uses existing service authorization and writes/imports remain Admin-only. Export remains in the header and exports the current rendered page. No schema or stock mutation changes; pagination reads need no stock transaction. Newly added integration fixtures run inside a transaction and roll back.

## Tests and results

- New isolated ManagementPaginationTest: 13 cases, including invalid inputs, fixed size, bounds, inactive users, deterministic category ordering, empty results, Sales user-list denial, page 2 links retaining query params, and failed import.
- New ManagementPaginationIntegrationTest: five module cases against real Docker MySQL/native prepared statements, inserting 23 temporary records per module, verifying <=10 rows/page, complete coverage without duplicate IDs, stable repeated page reads, bounds and rollback cleanup.
- Focused new tests: 18 tests, 349 assertions passed.
- Final full `composer test`: 250 tests, 1028 assertions passed (unit and integration).
- JavaScript suite: 41 passed.
- PHPStan level 5: 110 files, no errors; existing dependency age advisory.
- Docker final rebuild/start and git diff --check passed.
- Browser checks: 13 pages at 360/1024/1440/1920px, 52 page/viewport checks. All five Management lists have pagination and <=10 rendered records; table/pagination gap is zero; header export placement, full viewport, role access and mobile drawer remain intact; no JS exceptions.
- Additional HTTP smoke on each module: page 2, negative page, beyond-last page and array page input handled; empty CSV import returns 422 with pagination and no PHP warning. No smoke data added.

An intermediate analysis run checked stale root files after a directory copy nested new app files inside the container; it reported two errors already corrected in the workspace. Rebuilding the final image removed that mismatch, and the final 110-file analysis above passed. No tests skipped or constraints weakened.

## Limits

Small seeded Management datasets show page 1 of 1 until more than 10 records exist; this is expected. Multiple-page behavior is proved with isolated unit fixtures and rollback-only MySQL fixtures. No new search/filter functionality was added to Management by this slice.
