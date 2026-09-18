# Phase 8 Specification: Release Hardening

Status: Approved for planning  
Date: 2026-08-31  
References: `AGENTS.md`, `SDD.md`, `PLAN.md`, `docs/planning/CONSTITUTION.md`

## 1. Purpose

Phase 8 performs final release verification for mandatory scope. It validates clean startup, database initialization, tests, static analysis, secrets safety, traceability, screenshots/evidence, diagrams, and technical-defense readiness.

Exit gate: the project is ready for final assessment submission or has explicit documented limitations.

## 2. Requirement Trace

- All mandatory requirement IDs from `SDD.md` section 25.
- `TEST-01..03`: final test/static-analysis evidence.
- `DESIGN-01..04`: final documentation evidence.
- `DB-01`: clean schema/seed and transaction/index explanation.
- `UI-01`: final responsive evidence.
- `ERR-01` and security design: safe failure paths and no secret leakage.

## 3. In Scope

- Clean-folder or clean-clone Docker run.
- Schema/seed verification from empty MySQL database.
- Full unit and integration test run.
- Final PHPStan/static analysis report.
- No secrets in repository files.
- Requirement traceability matrix review.
- Screenshots/evidence completeness check.
- Final as-built diagram review.
- Technical-defense rehearsal notes.
- Safe-refactor rehearsal notes.
- Final tag/release if Git is available.

## 4. Out of Scope

- New mandatory business features unless a release blocker proves one missing.
- Optional enterprise additions.
- Cosmetic rewrites not needed for assessment readiness.
- Hiding limitations instead of documenting them.

## 5. Release Verification Contract

Release must prove:

- `docker compose up --build` works from clean state.
- MySQL schema and seed load from empty DB.
- Demo data satisfies seed contract.
- Unit tests pass.
- Integration tests pass against real MySQL Docker.
- PHPStan level 5+ is acceptable.
- No committed `.env` or production secrets exist.
- README setup instructions match actual commands.
- Requirement traceability matrix is complete.
- Evidence files are current and truthful.

## 6. Security and Data Integrity Checks

- Secret scan must include `.env`, passwords, API keys, stack traces, and production-like credentials.
- Prepared-statement audit must find no user-input SQL concatenation.
- Stock mutation audit must confirm all receipt/issue paths use transaction + ledger.
- Authorization audit must confirm protected operations are enforced server-side.
- Dashboard audit must confirm values are query/service-derived, not hardcoded.

## 7. Documentation Checks

Required final docs:

- README with setup, demo credentials if safe, test commands, and known limitations.
- Scope/backlog/trainer decisions.
- Initial and as-built architecture diagrams.
- ADRs.
- Refactor log.
- SRP audit.
- Tech debt.
- Critique.
- Test scenarios/results.
- Static analysis report.
- AI usage log.

## 8. Acceptance Criteria

1. Clean Docker startup succeeds.
2. Empty DB initializes from schema/seed.
3. Unit and integration tests pass.
4. PHPStan/static analysis is acceptable.
5. No secrets are committed.
6. Required screenshots/evidence exist.
7. Requirement traceability is complete.
8. As-built docs match code.
9. Technical defense can explain architecture, stock safety, security, validation, testing, and tradeoffs.
10. Any remaining limitation is explicit and not hidden.

## 9. Verification Commands

```bash
docker compose down -v
docker compose up --build
composer test
composer analyse
git status --short
git log --oneline --grep='^refactor:'
```

Additional grep/audit commands should be recorded in `docs/testing/test-results.md` or release notes.

## 10. Risks and Decisions

- Release hardening cannot compensate for missing mandatory behavior; missing behavior returns to the relevant phase.
- Clean Docker startup is a release gate.
- If Git is unavailable, final tag/release and commit-history evidence are blocked and must be documented.

## 11. Handoff to Implementation Plan

Plan tasks should run clean-state verification, fix release blockers only, refresh evidence, perform security/stock/dashboard audits, rehearse defense, and create final release/tag when Git is available.
