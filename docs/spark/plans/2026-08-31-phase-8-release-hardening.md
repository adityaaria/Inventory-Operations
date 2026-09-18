# Phase 8 Release Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Verify the complete mandatory application from clean Docker state and prepare final assessment submission evidence.

**Architecture:** Release hardening fixes blockers only. Missing mandatory behavior returns to its owning phase; optional features remain deferred unless all mandatory gates are already green.

**Tech Stack:** Docker Compose, MySQL 8, PHP 8.2+, Composer, PHPUnit, PHPStan, Markdown evidence, Git when available.

## Global Constraints

- Clean Docker startup is a release gate.
- Unit and integration tests must pass before release is called ready.
- PHPStan level 5+ must be acceptable or limitations documented.
- No secrets may be committed.
- Requirement traceability must be complete.
- Evidence must be truthful and current.
- No optional feature work may delay mandatory release readiness.

---

## File Structure Map

- Modify `README.md`.
- Modify `docs/testing/test-results.md`, `docs/testing/test-scenarios.md`.
- Modify `docs/quality/phpstan-report.txt`, `docs/quality/tech-debt.md`, `docs/quality/critique.md`.
- Modify architecture docs if release audit finds mismatches.
- Create release notes file if useful: `docs/planning/release-readiness.md`.
- Modify `ai-usage-log.md`.

---

### Task 1: Clean Docker Verification

**Files:**
- Modify: `docs/testing/test-results.md`, `docs/quality/tech-debt.md`

- [x] Avoided destructive `docker compose down -v` on the existing project volume; used isolated Compose project for clean-volume verification.
- [x] Run `COMPOSE_PROJECT_NAME=rudis_phase8 APP_PORT=8083 DB_HOST_PORT=3307 docker compose up -d --build app`.
- [x] Verify app route, login route, product/PO/SO/dashboard/report/API routes, and low-stock script evidence.
- [x] Record exact failures and fixes: product search placeholder bug and dotted CSV route Docker router bug.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 2: Empty Database and Seed Verification

**Files:**
- Modify: `docs/testing/test-results.md`

- [x] Started DB from an empty isolated Compose volume.
- [x] Query user count, product count, warehouse count, PO/SO counts, required statuses, low-stock examples.
- [x] Confirm seed contract: 1 Admin, at least 2 Sales, at least 2 Warehouse Staff, at least 2 warehouses, at least 30 products, at least 25 combined PO/SO.
- [x] Record query commands and results.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 3: Final Tests and Static Analysis

**Files:**
- Modify: `docs/testing/test-results.md`, `docs/quality/phpstan-report.txt`

- [x] Run `DB_PORT=3307 composer test`.
- [x] Run `DB_PORT=3307 composer test:unit`.
- [x] Run `DB_PORT=3307 composer test:integration`.
- [x] Run `composer analyse`.
- [x] Record pass/fail results with exact command outputs.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 4: Secret and Forbidden Technology Audit

**Files:**
- Modify: `docs/planning/release-readiness.md`, `docs/quality/tech-debt.md`

- [x] Run grep audit for `.env`, API keys, passwords, stack traces, and production-like credentials.
- [x] Run grep audit for forbidden frameworks and libraries.
- [x] Confirm `.env` is ignored and `.env.example` contains placeholders only.
- [x] Record commands and results.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 5: Stock, Authorization, Dashboard Audit

**Files:**
- Modify: `docs/planning/release-readiness.md`, architecture/quality docs if needed

- [x] Audit receipt and issue code paths for `StockService`, transaction, `FOR UPDATE`, ledger, and rollback.
- [x] Audit protected actions for server-side auth checks.
- [x] Audit dashboard/report code for query-backed values and no hardcoded totals.
- [x] Record findings and fix blockers in owning phase files.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 6: README and Traceability Review

**Files:**
- Modify: `README.md`, `docs/planning/release-readiness.md`

- [x] Ensure README setup commands match actual Docker/Composer commands.
- [x] Document safe demo credentials if present.
- [x] Cross-check every SDD requirement ID against implementation area and evidence.
- [x] Record remaining explicit limitations.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 7: Screenshots and Defense Notes

**Files:**
- Modify/create: `docs/testing/test-results.md`, `docs/planning/release-readiness.md`

- [x] Document representative auth, master data, PO, SO, dashboard, report/API runtime surfaces through HTTP smoke and responsive CSS evidence.
- [x] Write technical-defense notes for architecture, stock safety, auth/security, validation, tests, and tradeoffs.
- [x] Write safe-refactor rehearsal notes.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 8: Final Release Gate

**Files:**
- Modify: `docs/planning/release-readiness.md`, `ai-usage-log.md`

- [x] Run `git rev-parse --show-toplevel`; workspace is not a Git repository, so `git status --short` is unavailable.
- [x] Run `git log --oneline --grep='^refactor:'` equivalent check; blocked because workspace is not a Git repository.
- [x] Final tag cannot be created because Git is unavailable.
- [x] Mark release readiness ready for assessment review with explicit Git limitation.
- [x] Document inability to commit when Git is not initialized.

## Self-Review

This plan covers clean Docker, empty DB seed, tests, PHPStan, secret audit, forbidden technology audit, stock/auth/dashboard invariants, README, traceability, screenshots, defense notes, and final release gate.

## Execution Handoff

Plan complete and saved to `docs/spark/plans/2026-08-31-phase-8-release-hardening.md`. Use Subagent-Driven or Inline Execution before implementation.
