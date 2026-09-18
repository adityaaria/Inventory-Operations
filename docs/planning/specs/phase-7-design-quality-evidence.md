# Phase 7 Specification: Design and Quality Evidence

Status: Approved for planning  
Date: 2026-08-31  
References: `AGENTS.md`, `SDD.md`, `PLAN.md`, `docs/planning/CONSTITUTION.md`

## 1. Purpose

Phase 7 completes the assessment evidence package: architecture diagrams, ADRs, refactor log, SRP audit, tech debt register, critique, tests, static analysis report, and AI usage log.

Exit gate: engineering evidence can be traced to code and test output.

## 2. Requirement Trace

- `ARCH-01`: repository interfaces and fake repositories demonstrated by unit tests.
- `ARCH-02`: stock transaction/locking proven by ADR and integration scenario.
- `DESIGN-01`: initial and as-built diagrams.
- `DESIGN-02`: ADRs.
- `DESIGN-03`: refactor log, SRP audit, tech debt, git history.
- `DESIGN-04`: critique document.
- `TEST-01`: unit test output.
- `TEST-02`: integration test output.
- `TEST-03`: PHPStan/static analysis report.

## 3. In Scope

- Preserve initial diagram.
- Generate/update as-built class diagram from actual code.
- Finalize ADR-001 layered/repository boundary.
- Finalize ADR-002 stock concurrency mechanism.
- Add ADR-003 stock ledger/source-of-truth decision if not already present.
- Refactor log with at least three entries: smell, technique, before/after.
- SRP audit.
- Tech debt register.
- Critique exercise.
- Meaningful unit test evidence across at least three areas.
- MySQL integration test evidence for at least three persistence/transaction cases.
- PHPStan level 5+ report.
- AI usage log maintenance.
- A genuine `refactor:` commit if Git is available.

## 4. Out of Scope

- New business features.
- Fabricated screenshots, reports, or test output.
- Rewriting architecture only for document aesthetics.
- Optional enterprise features unless mandatory work is already green.

## 5. Evidence Contract

Required files:

- `docs/architecture/class-diagram-as-built.md`
- `docs/architecture/adr-001-layered-repository.md`
- `docs/architecture/adr-002-stock-concurrency.md`
- `docs/architecture/adr-003-stock-ledger-source-of-truth.md`
- `docs/quality/refactor-log.md`
- `docs/quality/srp-audit.md`
- `docs/quality/tech-debt.md`
- `docs/quality/critique.md`
- `docs/quality/phpstan-report.txt`
- `docs/testing/test-scenarios.md`
- `docs/testing/test-results.md`
- `ai-usage-log.md`

Evidence must cite real commands, files, screenshots, diagrams, or code behavior.

## 6. Architecture Review Rules

- As-built diagram must reflect actual implementation, not copied baseline.
- ADRs must explain context, decision, and consequences.
- SRP audit must identify class responsibilities and any accepted tradeoffs.
- Tech debt must record real limitations and planned actions.
- Refactor log entries must correspond to real code changes.

## 7. Testing Evidence Rules

- Unit tests must be meaningful business/security/validation tests, not trivial getters/setters.
- Integration tests must use real MySQL Docker for persistence and stock transaction behavior.
- Test result output must be captured honestly.
- Skipped/deleted tests must not be used to force a green result.

## 8. Acceptance Criteria

1. Initial diagram is preserved.
2. As-built diagram matches current code.
3. Required ADRs exist and match implementation.
4. Refactor log has at least three real entries.
5. SRP audit and critique are complete.
6. Tech debt register is current.
7. Unit, integration, and static analysis evidence exists.
8. AI usage log is current.
9. Evidence does not contradict code or test output.

## 9. Verification Commands

```bash
composer test
composer analyse
docker compose up --build
git log --oneline --grep='^refactor:'
```

If Git is not initialized, record the missing Git limitation in `docs/quality/tech-debt.md` and release evidence.

## 10. Risks and Decisions

- Evidence quality is assessed as much as feature presence; incomplete evidence is a release blocker.
- Do not claim generated diagrams are as-built unless checked against code.
- Git-based requirements need Git initialized before final submission.

## 11. Handoff to Implementation Plan

Plan tasks should audit docs, generate diagrams, refresh ADRs, create quality docs, capture command output, verify traceability, and update AI usage.
