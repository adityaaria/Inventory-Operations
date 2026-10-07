# AGENTS.md
## Codex Execution Policy - Inventory & Order Management System

This file is the highest-level operating contract for Codex in this repository.

## 1. Authority Order

When instructions conflict, use this precedence:

1. Official Final Project Brief.
2. `AGENTS.md`.
3. `SDD.md`.
4. `KNOWLEDGE.md`.
5. approved ADR / trainer decision in `docs/planning/`.
6. current implementation and tests.
7. optional enhancement ideas.

Never silently reinterpret the official brief.

For requirement navigation before enhancements and bug fixes, read `KNOWLEDGE.md`; use its PDF page references to check the official brief. The summary does not override the authority order above.

## 2. Non-Negotiable Constraints

- PHP 8.2+ Native OOP.
- MySQL 8 + PDO prepared statements.
- Controller -> Service -> Repository separation.
- repository interface boundary with manual constructor injection.
- HTML + custom CSS + Vanilla JS + Fetch API.
- Docker Compose.
- PHPUnit unit + integration tests.
- PHPStan level 5+ preferred.
- no Laravel/CodeIgniter/Symfony/Slim/ORM/framework DI container.
- no React/Vue/Angular/jQuery/CSS framework/admin template.
- never mutate stock outside stock service transaction + ledger.
- never place authorization only in UI.

## 3. Implementation Strategy

Work in vertical slices and keep every slice demoable.

Mandatory order:

1. bootstrap/Docker/config/router;
2. authentication/session/authorization;
3. users + master data;
4. PO + transactional receipt;
5. SO + approval + concurrency-safe issue;
6. ledger integrity;
7. search/filter/sort/pagination;
8. dashboard/report;
9. JSON API;
10. low-stock command;
11. responsive/security polish;
12. architecture/testing/quality evidence;
13. release hardening;
14. optional enterprise additions only after mandatory green.

## 4. Mandatory Change Protocol

Before editing:

- identify requirement IDs;
- inspect existing code/tests/schema/docs;
- identify affected invariants;
- determine authorization requirements;
- determine transaction boundary;
- determine test impact.

After editing:

- run relevant unit tests;
- run relevant integration tests when persistence changed;
- run static analysis;
- update docs/evidence;
- report changed files and remaining risks.

## 5. Architecture Rules

Controller:
- HTTP only; no SQL/transaction/domain logic.

Service:
- business rules/state transitions/authorization orchestration/transaction orchestration;
- no PDO/superglobal/session implementation detail.

Repository:
- persistence and SQL;
- prepared statements only;
- no HTML/business workflow orchestration.

Security:
- AuthContext/Authorization central enough to avoid repeated ad-hoc role checks.

## 6. Stock Safety Rules

Every Receipt/Issue/Adjustment/Transfer-like mutation MUST:

1. validate authorization and state;
2. begin transaction;
3. lock relevant ProductStock row(s) with `FOR UPDATE`;
4. validate quantities after lock;
5. mutate ProductStock;
6. append StockLedger;
7. update source order/operation state;
8. append audit record if enabled;
9. commit;
10. rollback completely on failure.

Acquire multiple stock locks in deterministic order.

## 7. Optional Feature Gate

Do not start optional features until:

- all mandatory requirements implemented;
- mandatory tests green;
- static analysis acceptable;
- Docker clean boot verified;
- mandatory docs/evidence substantially complete.

Preferred optional order:

1. audit trail;
2. idempotent receipt/issue;
3. structured logging + CSRF hardening;
4. inventory adjustment approval;
5. warehouse transfer;
6. low-stock PO recommendation;
7. dashboard chart;
8. CSV import;
9. MinIO adapter;
10. stock reservation only if time permits.

Redis is deferred unless explicitly justified in an ADR.

## 8. Forbidden Agent Behavior

Never:

- install a framework to speed up implementation;
- add an ORM;
- generate CRUD using forbidden tooling;
- hide failing tests with skip/delete;
- bypass service/ledger for stock changes;
- weaken DB constraints to make tests pass;
- hardcode dashboard numbers;
- add a status not in the brief without decision documentation;
- invent trainer decisions;
- commit credentials or `.env`;
- fabricate screenshots/test reports/evidence;
- claim a command passed if it was not run.

## 9. Ambiguity Protocol

When the brief is ambiguous:

1. record the question in `docs/planning/DECISIONS_PENDING.md`;
2. choose no broader scope than necessary;
3. mark any temporary default explicitly;
4. do not present the default as official requirement;
5. update SDD/ADR after trainer confirmation.

Known ambiguities include SO rejection representation, PO cancellation after partial receipt, multi-warehouse low-stock semantics, and exact order-number format.

## 10. Task Report Format

At the end of each task output:

```text
Requirement IDs:
Mandatory/Optional:
Files changed:
Behavior implemented:
Security/authorization:
Transaction/invariants:
Tests added/updated:
Commands executed + result:
Docs/evidence updated:
Known gaps/risks:
Recommended next task:
```
