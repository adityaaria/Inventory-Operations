# Technical Debt Register

## Open Items

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
