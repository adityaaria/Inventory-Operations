# Phase 0 Specification: Bootstrap and Evidence Baseline

Status: Approved for planning  
Date: 2026-08-31  
References: `AGENTS.md`, `SDD.md`, `PLAN.md`, `docs/planning/CONSTITUTION.md`

## 1. Purpose

Phase 0 establishes a clean, assessment-compliant foundation for the Inventory & Order Management System. The slice must make the repository runnable, testable, and ready for later vertical features without implementing authentication, master data, Purchase Orders, Sales Orders, reports, dashboard, or optional enterprise features.

The result should be a minimal native PHP 8.2+ application skeleton with Docker Compose, MySQL 8 connectivity configuration, Composer autoloading, a front controller, a simple router, baseline test/static-analysis tooling, schema/seed skeleton, and initial evidence documents.

## 2. Requirement Trace

Primary requirement support:

- `ARCH-01`: repository/interface-ready project structure and unit-testable architecture baseline.
- `DB-01`: database schema/seed foundation, MySQL 8 service, constraints-ready design.
- `TEST-01`: PHPUnit unit test bootstrap.
- `TEST-02`: integration test bootstrap for real MySQL Docker usage.
- `TEST-03`: PHPStan/static analysis bootstrap.
- `DESIGN-01`: initial class diagram placeholder/evidence path.
- `DESIGN-02`: ADR folder and first architecture-decision placeholders.
- `DESIGN-03`: quality evidence folders for future refactor/SRP/tech debt records.

Phase 0 also supports later requirements by creating the mandatory folder and tooling foundation.

## 3. In Scope

Phase 0 must include:

- approved repository structure from `SDD.md` section 9;
- `composer.json` with PSR-4 autoloading for `App\\`;
- PHPUnit and PHPStan configured as development tooling;
- Dockerfile for the PHP application runtime;
- `compose.yaml` with app/web service and MySQL 8 service;
- `.env.example` with placeholders only;
- config loader that reads environment values without committing secrets;
- `public/index.php` front controller;
- minimal router capable of mapping HTTP method + path to handlers;
- minimal Request/Response abstractions if useful for clean controller boundaries;
- safe baseline error handling for 404 and unexpected errors;
- `database/schema-and-seed.sql` skeleton;
- `tests/Unit` and `tests/Integration` bootstrap;
- initial smoke test proving the test runner works;
- PHPStan config at level 5 or higher if practical at this stage;
- initial documentation/evidence folder layout.

Initial documentation/evidence files should include:

- `docs/planning/scope.md`;
- `docs/planning/backlog.md`;
- `docs/planning/DECISIONS_PENDING.md`;
- `docs/architecture/class-diagram-initial.md`;
- `docs/architecture/adr-001-layered-repository.md`;
- `docs/architecture/adr-002-stock-concurrency.md`;
- `docs/quality/tech-debt.md`;
- `docs/testing/test-scenarios.md`;
- root `ai-usage-log.md`.

## 4. Out of Scope

Phase 0 must not implement:

- login, logout, session workflow, or user management;
- product/category/warehouse/supplier/customer CRUD;
- Purchase Order or Sales Order workflows;
- stock mutation, ledger insert behavior, or business transactions;
- dashboard, reports, CSV export, JSON API, low-stock job;
- frontend visual polish beyond a minimal working response/error page;
- optional audit trail, idempotency, CSRF hardening, charts, import, MinIO, Redis, queues, or external integrations.

## 5. Architecture Contract

The bootstrap must preserve the later Controller -> Service -> Repository architecture:

- `app/Controller` is reserved for HTTP controllers.
- `app/Service` is reserved for business rules and transaction orchestration.
- `app/Repository/Contract` is reserved for repository interfaces.
- `app/Repository/MySql` is reserved for PDO-backed repositories.
- `app/Repository/InMemory` is reserved for fakes used by unit tests.
- `app/Security`, `app/Http`, `app/Validation`, and `app/Exception` hold shared infrastructure.

Manual constructor injection is the only allowed dependency-wiring approach. Do not introduce a framework container.

The initial router/front controller may be minimal, but it must not create a pattern where SQL, transactions, authorization rules, or business workflows live in HTTP handlers.

## 6. Runtime and Configuration

Docker Compose must provide:

- PHP application service;
- MySQL 8 database service;
- environment-variable configuration compatible with `.env.example`;
- persistent or named database volume unless the implementation documents why it is omitted for early bootstrap;
- application access through a documented local port.

Configuration must:

- read from environment variables;
- provide clear errors for missing required values;
- keep `.env` out of source control;
- keep `.env.example` safe and non-production;
- avoid committed credentials beyond clearly fake demo placeholders.

## 7. Database Baseline

`database/schema-and-seed.sql` must exist and be executable later from a clean MySQL container. In Phase 0 it may contain only a skeleton, but it should establish the intended direction:

- database/schema setup comments or initial statements;
- seed section placeholder;
- no production secrets;
- no framework migration dependency.

Actual tables for users, products, orders, stock, and ledger may be implemented in later slices.

## 8. Testing and Static Analysis

Phase 0 must make these commands available through Composer scripts or documented direct commands:

- run all PHPUnit tests;
- run unit tests;
- run integration tests or show an integration test bootstrap placeholder;
- run PHPStan.

At minimum, Phase 0 should add a small smoke test that proves autoloading and PHPUnit are wired correctly. Integration tests may be skipped only if no persistence behavior exists yet, but the integration test directory/configuration must be ready for Phase 1 onward.

Tests must not require external network access. Future unit tests must be able to run without real PDO, session state, or MySQL.

## 9. Acceptance Criteria

Phase 0 is complete when:

1. repository structure exists and matches the SDD ownership boundaries;
2. `composer install` can install project dependencies;
3. Composer autoloading works for `App\\` classes;
4. Docker Compose can build and start the PHP app and MySQL 8 services;
5. the app responds through `public/index.php` or the configured web root;
6. the router returns a safe response for a known route and 404 for an unknown route;
7. `.env.example` documents required variables and `.env` is ignored;
8. PHPUnit smoke test passes;
9. PHPStan runs at level 5+ or a documented baseline explains temporary warnings;
10. initial planning, architecture, quality, testing, and AI usage evidence files exist;
11. no forbidden framework, ORM, DI container, frontend framework, CSS framework, or admin template is introduced.

## 10. Verification Commands

Expected verification commands after implementation:

```bash
composer install
composer test
composer analyse
docker compose up --build
```

If Composer scripts use different names, the README must document exact equivalents.

## 11. Risks and Decisions

Known Phase 0 risks:

- The repository currently has no Git metadata, so commit-based evidence cannot be produced until Git is initialized or the project is moved into a Git repository.
- The official brief PDF could not be extracted with available local tools during initial planning; `SDD.md` is treated as the implementation-ready brief translation unless the user supplies extracted PDF text or tooling is installed.
- Docker/PHP dependency versions must stay compatible with PHP 8.2+ and MySQL 8.

Pending decisions to record if they affect Phase 0:

- exact local app port;
- whether the PHP runtime uses Apache, PHP built-in server, or PHP-FPM plus web server;
- exact Composer script names.

## 12. Handoff to Implementation Plan

The implementation plan should break Phase 0 into small checkpoints:

1. create structure and Composer metadata;
2. add config/env handling;
3. add front controller/router/error baseline;
4. add Docker Compose and Dockerfile;
5. add schema/seed skeleton;
6. add PHPUnit/PHPStan setup and smoke test;
7. add evidence docs;
8. run verification and document results.
