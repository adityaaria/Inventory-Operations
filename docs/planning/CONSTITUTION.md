# Project Constitution
## Inventory & Order Management System

Version: 1.0.0  
Ratified: 2026-08-31  
Last amended: 2026-08-31

## 1. Authority and Scope

This constitution governs implementation decisions for the Inventory & Order Management System. It does not replace the official final project brief, `AGENTS.md`, `SDD.md`, approved ADRs, or trainer decisions. When sources conflict, the authority order in `AGENTS.md` applies.

The project goal is to deliver the mandatory assessment scope first: a native PHP 8.2+ inventory and order system with MySQL 8, Docker Compose, layered OOP architecture, transactional stock safety, tests, static analysis, and evidence traceable to requirement IDs.

Optional enterprise additions are allowed only after mandatory scope is implemented, tested, documented, and demonstrably stable.

## 2. Core Principles

### I. Assessment Brief First

Every implementation decision must preserve the official project brief. Do not reinterpret required behavior silently, broaden scope without evidence, or turn optional ideas into mandatory work.

Requirement IDs must be identified before meaningful changes and reported after work is complete. If a requirement is ambiguous, record the question in `docs/planning/DECISIONS_PENDING.md` or the trainer decision log, choose the narrowest temporary default, and mark it as temporary.

### II. Native, Layered PHP Architecture

The system must use PHP 8.2+ native OOP, MySQL 8, PDO prepared statements, HTML, custom CSS, Vanilla JavaScript, Fetch API, Docker Compose, PHPUnit, and PHPStan level 5+ where practical.

The application must keep Controller, Service, and Repository responsibilities separate:

- Controllers handle HTTP input/output only.
- Services own business rules, authorization orchestration, state transitions, and transaction orchestration.
- Repositories own persistence through interfaces and MySQL PDO implementations.
- Dependencies are wired manually through constructors at the composition root.

Frameworks, ORMs, CRUD generators, framework DI containers, frontend frameworks, jQuery, CSS frameworks, and admin templates are prohibited.

### III. Server-Side Security Is Authoritative

The server must enforce authentication, authorization, validation, and ownership rules. UI hiding is only a convenience and must never be the only protection.

Mandatory security controls include password hashing, password verification, session regeneration after login, protected routes, centralized authorization, prepared statements, HTML escaping, safe error output, ignored `.env`, placeholder-only `.env.example`, and safe upload handling.

Sales users must not approve or reject Sales Orders, even if they call the endpoint manually. Authorization-sensitive behavior must be tested independently from frontend behavior.

### IV. Stock Integrity Is Non-Negotiable

Stock may only change through the stock service transaction and ledger workflow. Every receipt, issue, adjustment, or transfer-like operation must:

1. validate authorization and operation state;
2. begin a database transaction;
3. lock relevant `product_stocks` rows with `SELECT ... FOR UPDATE`;
4. validate quantities after locks are acquired;
5. update `product_stocks`;
6. append `stock_ledger`;
7. update the source order or operation state;
8. append audit data if enabled;
9. commit;
10. roll back completely on failure.

When multiple stock rows are involved, locks must be acquired in deterministic order. Stock must never become negative, and ledger records are the audit trail for inventory movement.

### V. Evidence Before Completion

Work is complete only when behavior works end-to-end, server-side validation and authorization exist, persistence uses prepared statements, relevant logic has tests, error paths are safe, requirement evidence exists or is easy to produce, architecture documentation is updated when needed, and no prohibited technology has been introduced.

Do not claim a command passed unless it was actually run. Do not fabricate screenshots, reports, test output, or evidence. Do not skip, delete, or weaken tests to force a green result.

## 3. Mandatory Delivery Order

Implementation must proceed in demoable vertical slices:

1. bootstrap, Docker, config, router;
2. authentication, session, authorization;
3. users and master data;
4. Purchase Order and transactional goods receipt;
5. Sales Order, approval, and concurrency-safe goods issue;
6. stock ledger integrity;
7. search, filter, sort, pagination;
8. dashboard and report;
9. JSON API;
10. low-stock command;
11. responsive UI and security polish;
12. architecture, testing, and quality evidence;
13. release hardening;
14. optional enterprise additions only after mandatory green.

Each slice must remain explainable in a technical defense and traceable to the requirement matrix in `SDD.md`.

## 4. Quality Gates

Before editing, the implementer must identify requirement IDs, inspect relevant docs/code/tests/schema, identify affected invariants, determine authorization needs, determine transaction boundaries, and determine test impact.

After editing, the implementer must run relevant unit tests, run integration tests when persistence or transactions changed, run static analysis where available, update docs/evidence when required, and report changed files plus remaining risks.

Minimum quality evidence for release includes:

- meaningful PHPUnit unit tests across at least three logic areas;
- MySQL integration tests for stock receipt, partial receipt, and oversell prevention;
- PHPStan level 5+ report or documented static analysis result;
- ADRs for repository boundaries and stock concurrency;
- initial and as-built architecture diagrams;
- refactor log, SRP audit, tech debt register, critique, and AI usage log;
- Docker clean-start verification.

## 5. Data, Validation, and Reporting Rules

Database constraints must reinforce service validation. Required integrity includes unique email, unique SKU, unique order numbers, foreign keys, non-negative prices and reorder points, positive item quantities, non-negative stock, and unique `(product_id, warehouse_id)` stock rows.

All major lists must support bounded pagination. Sort columns must use server-side allow-lists. Dashboard values and CSV reports must be generated from real queries or service aggregation, never hardcoded.

CSV output must escape values correctly and should neutralize formula injection for text beginning with `=`, `+`, `-`, or `@`.

## 6. Optional Scope Gate

Optional features may start only after mandatory requirements are implemented, tests are green, static analysis is acceptable, Docker clean boot is verified, and mandatory docs/evidence are substantially complete.

Preferred optional order:

1. audit trail;
2. idempotent receipt/issue;
3. structured logging and CSRF hardening;
4. inventory adjustment approval;
5. warehouse transfer;
6. low-stock PO recommendation;
7. dashboard chart;
8. CSV import;
9. MinIO adapter;
10. stock reservation only if time permits.

Redis, queues, cloud deployment, Kubernetes, mobile applications, production cron infrastructure, and real-time notification systems are deferred unless an ADR proves they are necessary and compatible with assessment readiness.

## 7. Amendment Process

This constitution may be amended when the official brief, trainer decision, ADR, or implementation evidence justifies a change.

Every amendment must:

- preserve the authority order from `AGENTS.md`;
- cite the reason for the change;
- avoid weakening stock, authorization, testing, or evidence requirements without an explicit trainer decision;
- update the version and last-amended date;
- keep known ambiguities documented rather than treated as official policy.

Manual edits are allowed, but they must not contradict the official final project brief.
