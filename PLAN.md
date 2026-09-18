# PLAN.md
## Enterprise Assessment-Compliant Implementation Roadmap

## Phase 0 - Planning & Bootstrap

- [ ] Create approved repository structure.
- [ ] Composer PSR-4 autoload.
- [ ] PHPUnit + PHPStan tooling.
- [ ] Dockerfile + compose.yaml (`app`, `mysql`).
- [ ] `.env.example`, config loader, safe secrets policy.
- [ ] Router/front controller and error pages.
- [ ] initial schema-and-seed skeleton.
- [ ] initial ERD/class diagram/user stories/backlog.
- [ ] clean Docker boot verification.

Exit gate: clean environment starts successfully.

## Phase 1 - Authentication & Authorization

Requirements: AUTH-01, AUTH-02, USR-01.

- [ ] User schema and demo accounts.
- [ ] User repository interface + MySQL + fake.
- [ ] password hashing/verification.
- [ ] session regeneration and logout destruction.
- [ ] AuthContext/AuthGuard.
- [ ] centralized server-side role authorization.
- [ ] user management Admin only.
- [ ] auth unit/integration tests.

Exit gate: three roles login; Sales/Warehouse cannot access user admin endpoints.

## Phase 2 - Master Data & Multi-Warehouse Stock

Requirements: PRD-01, WH-01, VIEW-01, partial FIND-01.

- [ ] category/product/warehouse/supplier/customer.
- [ ] unique SKU/email.
- [ ] deactivation policy.
- [ ] ProductStock row per product/warehouse.
- [ ] stock total + warehouse breakdown.
- [ ] optional safe local product image upload.
- [ ] product search/filter/pagination.
- [ ] validation + empty states.

Exit gate: one product demonstrates different stock in two warehouses.

## Phase 3 - Purchase Order

Requirement: PO-01, ARCH-02 partial.

- [ ] PO Draft create/edit.
- [ ] Ordered transition.
- [ ] item remaining/received quantities.
- [ ] full + partial goods receipt.
- [ ] transaction + row locking.
- [ ] ProductStock increment + Receipt ledger atomic.
- [ ] PO status recomputation.
- [ ] integration tests for receipt.

Exit gate: full/partial receipt demonstrated with ledger.

## Phase 4 - Sales Order & Concurrency

Requirement: SO-01, ARCH-02, segregation of duties.

- [ ] SO Draft own-order rules.
- [ ] submit to PendingApproval.
- [ ] Admin approve/cancel/reject mapping decision.
- [ ] Sales approval blocked server-side.
- [ ] Approved-only goods issue.
- [ ] transaction + `FOR UPDATE` stock lock.
- [ ] insufficient-stock rollback.
- [ ] Issue ledger atomic.
- [ ] Fulfilled transition.
- [ ] controlled oversell integration scenario.

Exit gate: second issue cannot oversell.

## Phase 5 - Lists, Dashboard, Reports, API, Job

Requirements: FIND-01, DASH-01, REPORT-01, API-01, JOB-01.

- [ ] search/filter/sort/order pagination 10/page.
- [ ] >=30 products + >=25 combined orders seed.
- [ ] role-scoped aggregate dashboards.
- [ ] CSV ledger/order report by date range.
- [ ] JSON product availability endpoint.
- [ ] standalone low-stock script.

Exit gate: all role views and required evidence demoable.

## Phase 6 - UI, Validation, Security, Error Handling

Requirements: VAL-01, ERR-01, UI-01, DB-01.

- [ ] 360px + desktop usability.
- [ ] frontend convenience validation + backend authoritative validation.
- [ ] safe 403/404/500 behavior.
- [ ] HTML escaping.
- [ ] PDO prepared statements audit.
- [ ] session security audit.
- [ ] CSRF recommended hardening.
- [ ] DB constraints/indexes review.

Exit gate: intentional failure paths are safe.

## Phase 7 - Design & Quality Evidence

Requirements: ARCH-01, DESIGN-01..04, TEST-01..03.

- [ ] initial diagram preserved.
- [ ] as-built class diagram generated from actual code.
- [ ] ADR-001 layered/repository DIP.
- [ ] ADR-002 stock concurrency mechanism.
- [ ] ADR-003 stock ledger/source-of-truth decision.
- [ ] >=6 meaningful unit cases across >=3 areas.
- [ ] >=3 MySQL integration tests.
- [ ] PHPStan level 5+ report.
- [ ] refactor log >=3 entries.
- [ ] SRP audit.
- [ ] tech-debt register.
- [ ] critique exercise.
- [ ] genuine `refactor:` commit.
- [ ] ai-usage-log maintained.

Exit gate: engineering evidence can be traced to code and tests.

## Phase 8 - Enterprise Hardening (Optional)

Only after Phases 0-7 are green.

### 8A Audit Trail
- [ ] audit schema/repository/service integration.
- [ ] sensitive event list.
- [ ] no password/secret in payload.
- [ ] audit integration tests.

### 8B Idempotency
- [ ] operation key schema/unique constraint.
- [ ] duplicate receipt retry test.
- [ ] duplicate issue retry test.
- [ ] preserve stock/ledger exactly once.

### 8C Observability + optimistic locking
- [ ] structured application logger.
- [ ] request/correlation ID.
- [ ] product version conflict handling (409 behavior).

## Phase 9 - Business Bonus (Optional)

### 9A Inventory Adjustment
- [ ] propose/approve/apply flow.
- [ ] Adjustment ledger.
- [ ] transaction/test/evidence.

### 9B Warehouse Transfer
- [ ] transfer state model.
- [ ] atomic source/destination update.
- [ ] deterministic lock order.
- [ ] ledger reference and tests.

### 9C Low Stock -> PO
- [ ] dashboard action.
- [ ] prefilled but user-confirmed PO.

### 9D Dashboard Charts
- [ ] SVG/canvas, no framework.

### 9E CSV Product Import
- [ ] parse/validate/preview/confirm.

### 9F MinIO
- [ ] `FileStorageInterface`.
- [ ] local implementation remains baseline.
- [ ] MinIO adapter optional Docker profile/documentation.

## Phase 10 - Release Freeze & Defense

- [ ] clean-clone Docker run.
- [ ] schema/seed from empty DB.
- [ ] unit + integration tests pass.
- [ ] static analysis report final.
- [ ] no secrets in repo/history.
- [ ] traceability matrix complete.
- [ ] screenshots/evidence complete.
- [ ] final as-built diagram matches code.
- [ ] technical-defense rehearsal.
- [ ] safe-refactor rehearsal.
- [ ] final tag/release.
