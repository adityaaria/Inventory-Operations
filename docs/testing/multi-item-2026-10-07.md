# Multi-item Stock Operations — 7 October 2026

Requirement IDs: UI-01, AUTH-01/02, DB-01, ARCH-01/02, TEST-01/02/03. Mandatory/Optional: optional UI enhancement over ADR-009 operations; user authorized continuation of the recommended multi-item task.

Files changed: BusinessOperationController, create/item views, inventory-operations.js, custom CSS, controller unit tests, business integration test and browser acceptance harness; documentation/evidence below.

Behavior implemented: retain one primary product item and add/remove up to 99 supplementary items. Every item has independent quantity, count baseline or original source and customer goods-fit confirmation. Warehouse/destination/type/reason remain shared. Retained 422 inputs render all bounded safe item fields. Existing single-item HTTP payloads and original-movement shortcuts remain compatible. Count snapshots are preserved when adding/removing other rows; changing product/warehouse/type captures new baselines. Async revision guards prevent late responses from overwriting a newer selection. Duplicate products and return sources from different original warehouses block UI submission and remain independently rejected by the service.

Security/authorization: existing authenticated role/CSRF and independent Admin review retained. Source product/warehouse resolved by service, not trusted from browser. Supplementary payload shape and 100-total limit enforced on server; HTML values escaped. Every customer-return item requires goods-fit confirmation.

Transaction/invariants: no schema/repository/business transition change. All product changes still use the existing shared StockService transaction/ordered locks/ledger/audit. New integration test proves insufficient second product leaves both warehouses/products unchanged, then successful two-product posting conserves stock and repeated posting creates no additional ledger.

Tests added/updated: controller compatibility, two-item persistence, duplicate/malformed/oversized input rejection and 422 value retention; real-MySQL multi-product rollback/post replay; browser add/remove/duplicate/baseline preservation/two-product mobile modal acceptance.

Commands executed + result: isolated Docker quality gate passed **370 PHP tests /1637 assertions**, PHPStan level 5 without errors, **54 JavaScript tests**. [Quality log](multi-item-2026-10-07/quality.txt). Final HTTP/browser results and activation are recorded below after execution. Existing prior tests were retained; failures were not skipped.

Docs/evidence updated: ADR-009 current UI capability, debt register, SDD/KNOWLEDGE, AI usage and physical-device checklist.

Known gaps/risks: physical Android/iPhone observations and trainer approval remain pending; cross-warehouse returns and duplicate-product lines intentionally remain disallowed. An approved/posted document cannot be edited; cancel/repropose when appropriate. No new work queue/timeline/reporting scope inferred from this continuation.

Recommended next task: role-specific work queue, followed by linked transaction timeline; physical acceptance remains separately pending.

Acceptance results: `scripts/verify-business.py` passed **76 isolated HTTP checks**; [HTTP evidence](multi-item-2026-10-07/http.json). `node tests/Browser/business-enhancements.cjs docs/testing/multi-item-2026-10-07` passed **34 Chrome-emulation checks**, including duplicate prevention, removal preserving baseline and two-product proposal submission in a mobile modal; [browser evidence](multi-item-2026-10-07/browser.json). No active browser exception. Existing single-item transfer approval/posting and return confirmation still pass. Physical Android/iPhone remain NOT RUN.

Deployment/cleanup: runtime image built and activated at localhost:8080; readiness CLI returned `ready`. Production target rebuilt as inventory-operations-production:local without external deployment. No schema migration or direct stock edit. Disposable acceptance/quality projects removed after verification; main project retained. `git diff --check` passed.
