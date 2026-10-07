# Technical Debt Register

## Current status — 6 October 2026

Audit findings and remediation are tracked in `docs/quality/project-audit-2026-10-06.md` and `docs/testing/audit-remediation-2026-10-06.md`. The historical register below is retained as evidence. TD-001/002/009 are resolved: Git exists and the official PDF was read. TD-005 is no longer observed: the app runs on 8080. TD-006 is mitigated by Docker quality tooling (host PHP remains 7.4). TD-010 is resolved by PHPStan 2.3.0. TD-011 is resolved for critical stock movements through transactional audit; request/auth telemetry remains explicitly best-effort. TD-007/008/012 remain maintenance items. Physical-device verification remains pending.

## Historical Open Items

| ID | Area | Debt | Impact | Planned Action |
|---|---|---|---|---|
| TD-001 | Repository setup | Git repository is not initialized in the current workspace. | Commit-based evidence cannot be produced here. | Initialize Git or move project into a Git repository before release evidence is finalized. |
| TD-002 | Brief verification | Official PDF text extraction was unavailable during initial planning. | The SDD is used as the implementation-ready translation. | Re-check against the official brief when PDF tooling or extracted text is available. |
| TD-005 | Local port allocation | Host port `8080` is already occupied by `httpd` on the current workstation. | Default `docker compose up --build` cannot bind port `8080` locally, although the app runs successfully with `APP_PORT=8082`. | Stop or reconfigure the local `httpd` listener before final release demo if port `8080` is required. |
| TD-006 | Host PHP link stability | `/opt/homebrew/bin/php` reverted once to the old `php@7.4` symlink during continued work. | Local Composer commands fail if Homebrew relinks PHP 7.4 again. | Re-run `brew link --overwrite --force php` if `php -v` is not PHP 8.2+ before verification. |
| TD-007 | Composition root size | `public/index.php` now wires all controllers, services, and repositories manually. | More optional workflows will make route/dependency setup harder to scan. | Keep mandatory scope stable; introduce a small native factory only if optional scope creates repeated wiring. |
| TD-008 | Validation consolidation | Phase 6 added `InputValidator`, but older workflow services still contain direct validation guards. | Validation behavior is correct but not fully uniform across all services. | Gradually migrate repeated primitive validation to `InputValidator` during future feature work. |
| TD-009 | Git evidence | Phase 7 requires a genuine `refactor:` commit if Git is available, but this workspace is not a Git repository. | Commit history evidence and `git log --grep='^refactor:'` cannot be produced here. | Initialize Git before final submission or repeat work in a repository-backed workspace. |
| TD-010 | PHPStan dependency age | PHPStan 1.12 reports no code errors but warns that the 1.12 line is old. | Static analysis is acceptable at level 5, but future compatibility improvements are missed. | Upgrade `phpstan/phpstan` to `^2.2` after assessment constraints allow dependency updates. |
| TD-011 | Audit durability | Audit writes are best-effort and do not fail the original business action if the audit repository errors. | Assessment demo remains resilient, but regulated production systems often require stricter failure handling for critical mutations. | Define an ADR for critical-action audit failure policy before production deployment. |
| TD-012 | Observability | Structured logs are local JSON-lines files only. | Local logs help debugging, but there is no retention policy, search, alerting, or centralized monitoring. | Add production log shipping/retention and operational alerts after assessment scope. |

## Resolved Items

| ID | Area | Former Debt | Resolution |
|---|---|---|---|
| TD-003 | Local PHP tooling | Local `php` pointed to a broken PHP 7.4 installation and `composer` was unavailable. | Homebrew PHP was relinked to PHP 8.5.10, Composer 2.10.3 was installed, `composer install` completed, PHPUnit passed, and PHPStan reported no errors on 2026-08-31. |
| TD-004 | Docker Desktop | Docker build failed with BuildKit/containerd metadata I/O errors and classic builder read-only filesystem errors. | Docker Desktop was restarted, the corrupt app/base image path was isolated, Dockerfile was moved to PHP 8.3 CLI, `unzip` and `composer.lock` handling were added, Docker build passed, containers started, and Docker-hosted HTTP smoke test passed on 2026-08-31. |
| TD-013 | Login brute-force protection | Login had no per-subject throttling in the mandatory baseline. | Added `login_attempts`, `LoginRateLimiter`, auth audit events, and runtime smoke evidence on 2026-09-01. |

## Session deployment boundary — 7 October 2026

Session hardening is implemented and verified; ADR-005 records remaining deployment scope. Native files use a dedicated persistent volume and per-session PHP locks for this single-host application. Multi-host shared storage, active-session inventory/remote logout-all, production HTTP worker/TLS setup and storage-cleanup monitoring are separate operational work, not claims of this enhancement. Previously authenticated sessions require one fresh login after rollout. At ID rotation boundaries queued old-ID requests fail authentication; no grace-period credential alias or automatic mutation replay is provided.

## End-to-end closure — 7 October 2026

Product-picker truncation, invalid-state 500s, failed-form retention and missing zero stock pairs are fixed. Actual concurrent stock and catalog operations now have passing integration coverage. Sustained load benchmarking and production deployment boundaries remain open; see [verification evidence](../testing/end-to-end-verification-2026-10-07.md).
