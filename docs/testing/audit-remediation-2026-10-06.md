# Audit remediation evidence — 6–7 October 2026

Scope: fixes requested for the 13 findings in `../quality/project-audit-2026-10-06.md`. Work started 6 October and continued into 7 October. No application dataset was reset, no Git commit was requested, and no trainer business decision was invented.

## Finding status

| Finding | Current status | Implementation / evidence |
|---|---|---|
| A01 Duplicate SO issue | Fixed | Source order locked/validated inside StockService transaction; all transitions use the same lock order; interleaved repeated issue commits one ledger row and one unit only. |
| A02 Stale role/inactive session | Fixed | Runtime AuthGuard resolves current user from repository, rejects inactive sessions, and uses current role. Revocation/downgrade regression tests. |
| A03 Tests mutate app DB | Fixed for future runs | All integration connections use TestDatabase's `_test` guard. Docker quality profile provides separate disposable MySQL, no host port, before/after reset. Existing application data was preserved. |
| A04 Stale PO receipt status | Fixed | Source PO/items are read after acquiring the source-order lock; two final partial receipts finish Received. |
| A05 Product detail | Fixed | `/products/show?id=…`, read-only authenticated access, category/prices/status, total/per-warehouse stock, Admin-only edit. Nine role/viewport functional checks and screenshots. |
| A06 Input coercion / raw DB errors | Fixed | Raw scalar/integer/money validation, service length limits, safe repository constraint mapping, scalar-safe filters/auth/CSRF/CSV, unexpected errors remain server errors. |
| A07 Spreadsheet formulas | Fixed | Frontend/server CSV neutralization covers leading whitespace/control characters; JavaScript regression cases. |
| A08 Partial failed import | Fixed | CsvImportService runs the entire batch in a repository transaction; six module regression cases prove rollback of the first valid row when the next row fails. |
| A09 Export/sort scope | Fixed with explicit scope | Generic paginated export is **Export Current Page**. Product/PO/SO supported headers are native server sort links retaining filters and resetting page; other local headings advertise current-page sorting. Reports exports every matching authorized row. |
| A10 Non-atomic movement audit | Fixed | Strict audit append before commit; audit failure rolls back stock, ledger and source state. Success request telemetry does not duplicate movement audit. |
| A11 Report/list query scaling | Fixed patterns identified | DB grouped aggregates + LIMIT/OFFSET; streamed unbuffered CSV; route-specific master lookups; batch product stock/order item reads; aggregate low-stock count. No production-size benchmark claimed. |
| A12 Tooling/docs reproducibility | Fixed | Runtime/test Docker targets, Node/PHPUnit/PHPStan config in test image, isolated quality runner, PHPStan 2.3.0, refreshed README/SDD/ADR/diagram/debt/release references. |
| A13 CSS/device verification | CSS debt reduced; hardware checks pending | Removed obsolete 760px drawer block and contradictory drawer rules; merged header media blocks into canonical 960/360 groups (2,051 → 1,963 lines). Current browser emulation passes; physical devices remain unavailable. |

## Invariants and implementation

Stock workflow lock order: begin transaction → lock source order → authorize/validate current state and quantities → lock stock rows in product-ID order → validate current stock with locking reads → mutate stock → append ledger → update source state → append strict audit → commit. Failures roll back together. Submit/approve/cancel/order operations also lock source state, preventing concurrent status overwrites. Repositories share the manually injected PDO connection; services do not contain PDO/SQL/superglobal access.

Required movement audit uses the enabled runtime AuditLogRepositoryInterface directly and fails closed. Auth/request telemetry remains explicitly best-effort, separate from stock evidence. Anonymous sessions remain anonymous; role and active state are checked server-side. UI is presentation only.

Known duplicate/FK/length/constraint PDO failures become safe validation errors. Malformed prices, fractional reorder quantities, array inputs and invalid upload shapes are rejected before casts. Unexpected exceptions are not displayed as CSV row errors. The bootstrap handler now shares styled/viewport-aware errors and returns JSON for API failures.

Reports preview and full exports use the same date/owner SQL source. Preview aggregates in MySQL and fetches only ten rows; HTTP export uses an unbuffered iterator and streams to output. Empty exports retain their CSV headers. Legacy string-returning report methods remain useful for unit tests; HTTP endpoints use the streaming path.

## Commands and actual results

Reproducible gate:

```bash
docker compose --profile quality run --build --rm test
```

Docker runtime and test images were built from the final code/config/lockfile. The quality container used the image as built, without manual file copies. Earlier development used a separate temporary working test container; its results do not substitute for the final image run.

Final image run passed **289 PHP tests / 1,182 assertions**, **47 JavaScript tests**, and **PHPStan 2.3.0 level 5 with no errors**. Final quality output is saved in `audit-remediation-quality-output.txt`; the recorded test/static/JavaScript counts are taken from that output. PHPStan runs at level 5 using `phpstan.neon`, with 114 analyzed files. Syntax checking also passed for 152 app/config/public/scripts/views PHP files, including new templates.

Browser evidence:

| Check | Result |
|---|---|
| Page/role/viewport regression | 303 checks at 320, 360, 390, 430, 568, 844, 768, 960 and 1440px; no document/main overflow, controls fit, all tables locally scrollable, filter/table/pagination gaps zero. |
| Drawer and long-content checks | Close/focus/inert/bounds passed; long heading/label/select content fits. |
| Dialog regression | 110 checks across mobile, landscape, tablet and desktop; create/edit/import/confirmation, server 422, single header and Cancel close passed. |
| Functional clean-seed browser checks | 25 checks: server sort/filter state, explicit export scope, full CSV, stable empty headers, array-date rejection, detail role access, Sales own export/stock prohibition, API JSON401. |
| JavaScript browser exceptions | None in those runs. |

JSON and original screenshots: `screenshots/audit-remediation/browser-results.json`, `dialog-results.json`, `functional-results.json`, and 27 PNG files. Full mobile/dialog regression ran after CSS consolidation. After correcting new product-detail summary markup to the canonical dl/div/dt/dd component, targeted role/viewport functional checks and screenshots were regenerated. This is Chrome emulation, not physical mobile-device proof.

## Clean startup

An independent Compose project `inventory-release-audit`, app port18083 and DB port13307, started with a newly created MySQL volume and the built runtime image. The override only selected that existing image. Seed counts: 30 products, 13 PO, 12 SO, 2 warehouses. Browser checks confirmed total order summary25 and full CSV26 lines including header, along with three-role product detail behavior. This project and its newly created volume were removed after verification. The main application's volume was preserved.

## Tests added/updated

- AuditRemediationTest: live-role/inactive-session revocation, raw invalid scalar/numeric inputs, Sales product-detail/edit boundaries, fail-closed DB guard.
- AuditRemediationIntegrationTest: duplicate issue, receipt completion, issue/cancel interleaving, required audit rollback, all six atomic imports and safe duplicate mapping.
- ReportPreviewTest / ReportQueriesIntegrationTest: bounded aggregate/page boundary and full scoped streaming, with cursor mode restored afterward.
- CsvImportTest / csv-security.test.js: array/multiple-upload rejection and formula-leading/control-character CSV values.
- Existing integration fixtures now use the isolated connection boundary; bootstrap test verifies database suffix and native prepared statements.

## Remaining limitations

- Physical iOS/Android/Safari and soft keyboards require actual hardware; no result fabricated.
- Pending trainer policies remain unchanged (self-approval, rejection representation, partial PO cancellation, low-stock semantics, number format).
- Historical application data potentially affected by old tests was not deleted or re-seeded. Any reconciliation should use verified opening balances/history; the new test gate prevents further accidental changes.
- Real simultaneous-process/load stress is not claimed; the required controlled interleavings prove the reproduced races. Report/list memory/query patterns were improved, but no production-volume latency benchmark was performed.
- Composition root and CSS can still be refined over time; maintenance debt is reduced, not claimed eliminated. Local JSON request logs still lack production retention/central monitoring.

Requirement IDs: AUTH-01, USR-01, PRD-01, WH-01, PO-01, SO-01, VIEW-01, FIND-01, REPORT-01, VAL-01, ERR-01, UI-01, ARCH-01/02, TEST-01/02/03, DESIGN-01/02/03. Mandatory requirements were repaired before optional import/export/audit polish. Recommended next task: real-device/keyboard validation and confirmed trainer decisions, followed by a release review of current evidence.
