# Phase 7 Design Quality Evidence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the assessment evidence package and prove architecture, tests, static analysis, refactoring, SRP review, critique, and AI usage traceability.

**Architecture:** Evidence must describe the actual codebase. Diagrams and ADRs are updated from source inspection and command output, not copied blindly from earlier plans.

**Tech Stack:** Markdown evidence, Mermaid diagrams, PHPUnit, PHPStan, Docker Compose, Git when available.

## Global Constraints

- Evidence must cite real commands, files, screenshots, diagrams, or code behavior.
- Do not fabricate screenshots, reports, test output, or evidence.
- Do not claim a command passed if it was not run.
- As-built diagram must reflect actual implementation.
- Unit tests must be meaningful and integration tests must use real MySQL Docker.
- Git-based evidence is blocked unless Git is initialized.
- No new business features in Phase 7.

---

## File Structure Map

- Create/modify `docs/architecture/class-diagram-as-built.md`.
- Modify ADRs and add `docs/architecture/adr-003-stock-ledger-source-of-truth.md`.
- Create `docs/quality/refactor-log.md`, `docs/quality/srp-audit.md`, `docs/quality/critique.md`.
- Modify `docs/quality/tech-debt.md`, `docs/quality/phpstan-report.txt`.
- Create/modify `docs/testing/test-results.md`, `docs/testing/test-scenarios.md`.
- Modify `ai-usage-log.md`.

---

### Task 1: Architecture Evidence Audit

**Files:**
- Modify: `docs/architecture/class-diagram-as-built.md`, existing ADRs

- [x] Inspect `app/Controller`, `app/Service`, `app/Repository`, `app/Security`, and `app/Entity`.
- [x] Generate Mermaid class diagram containing actual implemented classes and dependencies.
- [x] Check ADR-001 and ADR-002 against code; update consequences if implementation differs.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 2: Stock Ledger ADR

**Files:**
- Create: `docs/architecture/adr-003-stock-ledger-source-of-truth.md`

- [x] Document context: stock movements need auditable source-of-truth history.
- [x] Decide: `product_stocks` is current balance; `stock_ledger` is append-only movement history; all stock mutations write both in one transaction.
- [x] Document consequences for reports, rollback, and future audit trail separation.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 3: Test Evidence Package

**Files:**
- Create/modify: `docs/testing/test-results.md`, `docs/testing/test-scenarios.md`

- [x] Run `composer test`.
- [x] Record targeted unit suites for auth, master data, PO, SO, dashboard/report/API/job from current suite.
- [x] Run integration tests against real MySQL Docker through full PHPUnit suite.
- [x] Capture exact command, date, result, and failure text if any.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 4: Static Analysis Evidence

**Files:**
- Modify: `docs/quality/phpstan-report.txt`, `docs/quality/tech-debt.md`

- [x] Run `composer analyse`.
- [x] Verify PHPStan level 5+ from `phpstan.neon`.
- [x] Record PHPStan version warning context and no-error result in evidence.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 5: Refactor and SRP Evidence

**Files:**
- Create: `docs/quality/refactor-log.md`, `docs/quality/srp-audit.md`

- [x] Inspect real refactoring work from Phases 1-6 because Git commit history is unavailable.
- [x] Record smell, technique, before/after, files changed, and verification for at least three entries.
- [x] Audit key controllers/services/repositories for single responsibility.
- [x] Record accepted tradeoffs and follow-up debt.
- [x] Record that Git does not exist, so a `refactor:` commit cannot be verified in this workspace.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 6: Critique and AI Usage

**Files:**
- Create: `docs/quality/critique.md`
- Modify: `ai-usage-log.md`

- [x] Write critique covering architecture strengths, weaknesses, stock safety, security, testing, UX, and maintainability.
- [x] Update AI log with prompts/tasks, human review, verification, and limitations.
- [x] Confirm AI log contains task-level summaries only, without client PII or credentials.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

### Task 7: Final Evidence Verification

**Files:**
- Modify: evidence docs as needed

- [x] Run `composer test`.
- [x] Run `composer analyse`.
- [x] Run `APP_PORT=8082 docker compose up -d --build app`.
- [x] Run `git log --oneline --grep='^refactor:'` if Git exists; blocked because current workspace is not a Git repository.
- [x] Cross-check SDD requirement matrix against evidence files.
- [x] Commit checkpoint noted; actual commit unavailable because current workspace is not a Git repository.

## Self-Review

This plan covers all Phase 7 evidence files, diagrams, ADRs, test output, PHPStan report, refactor log, SRP audit, critique, AI usage log, and Git limitations.

## Execution Handoff

Plan complete and saved to `docs/spark/plans/2026-08-31-phase-7-design-quality-evidence.md`. Use Subagent-Driven or Inline Execution before implementation.
