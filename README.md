# Inventory & Order Management System

Native PHP 8.2+ inventory and order management system for the final project assessment.

## Stack

- PHP 8.2+ native OOP.
- MySQL 8 with PDO prepared statements.
- HTML, custom CSS, Vanilla JavaScript.
- Docker Compose.
- PHPUnit and PHPStan level 5.

No Laravel, CodeIgniter, Symfony, Slim, ORM, frontend framework, jQuery, CSS framework, or admin template is used.

## Setup

Start the application and database:

```bash
docker compose up -d --build app
```

Open:

```text
http://localhost:8080
```

If host port `8080` is already used locally, override the app port:

```bash
APP_PORT=8082 docker compose up -d --build app
```

Open:

```text
http://localhost:8082
```

If host MySQL port `3306` is already used and you need a second clean Compose project, override the DB host port too:

```bash
COMPOSE_PROJECT_NAME=rudis_phase8 APP_PORT=8083 DB_HOST_PORT=3307 docker compose up -d --build app
```

The `db` service loads `database/schema-and-seed.sql` on first volume initialization.

## Demo Credentials

These are fake local assessment credentials from the seed file:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@example.test` | `password` |
| Sales | `sales1@example.test` | `password` |
| Warehouse Staff | `warehouse1@example.test` | `password` |

## Quality Checks

```bash
docker compose --profile quality run --build --rm test
```

This builds a PHP 8.3/Node test image, runs PHPUnit, PHPStan level 5 and JavaScript tests, and resets a dedicated MySQL test database before and after the checks. The `test-db` service has no host port and uses disposable storage. Integration connections refuse database names that do not end in `_test`; application data is not a test fixture. Run one quality job at a time per Compose project.

For native development, PHP 8.2+ and Node are required. Install dependencies with `composer install`. `composer test:unit` and `composer analyse` do not need MySQL; full/integration suites require a separately seeded `_test` database and its environment variables. Use the Docker command above on workstations running older PHP.

Low-stock command:

```bash
docker compose exec -T app php scripts/check-low-stock.php
```

Static analysis evidence is stored in `docs/quality/phpstan-report.txt`. Test evidence is stored in `docs/testing/test-results.md` and `docs/testing/test-scenarios.md`.

## Environment

Copy `.env.example` to `.env` for local development. Keep `.env` out of source control.

The checked-in `.env.example` uses placeholder-only local values:

```bash
cp .env.example .env
```

## Evidence

- Architecture diagrams and ADRs: `docs/architecture/`.
- Test scenarios and results: `docs/testing/`.
- Quality evidence: `docs/quality/`.
- Planning and release readiness: `docs/planning/`.
- AI usage log: `ai-usage-log.md`.

## Current behavior and evidence

- Product detail is readable by authenticated roles; edit remains Admin-only.
- PO/SO transitions lock the source order before stock rows. Stock, ledger, final status and required movement audit commit or roll back together.
- Authentication guards re-read current role/active state. Deactivated users lose access on their next authenticated request.
- CSV imports are atomic; a failed row rolls back the entire file. List exports are labeled **Export Current Page**; Reports exports all authorized filtered rows through streaming CSV. Both paths neutralize formula-leading cells.
- Sort links for supported Product/PO/SO columns apply to the full filtered result and retain filter state. Other table headings explicitly sort the current page.
- Audit/remediation evidence: `docs/quality/project-audit-2026-10-06.md` and `docs/testing/audit-remediation-2026-10-06.md`.

Git is initialized and history is available. Existing evidence about unavailable Git is historical. Physical iOS/Android/Safari and soft-keyboard checks still need actual devices; Chrome emulation is not equivalent. Host port overrides remain available as documented above.

### Session lifecycle

Default limits: idle 30 minutes, absolute 8 hours, ID rotation every 15 minutes. Configure `SESSION_IDLE_SECONDS`, `SESSION_ABSOLUTE_SECONDS`, `SESSION_ROTATION_SECONDS` and `SESSION_COOKIE_SECURE` using `.env.example`. Limits must be positive, idle ≤ absolute, rotation ≤ absolute; invalid configuration fails closed. Local HTTP uses `auto`; production uses `APP_ENV=production` and requires HTTPS/Secure cookies. For trusted TLS termination set `SESSION_COOKIE_SECURE=true`; forwarded headers are not trusted automatically.

The Compose `session-data` volume preserves sessions across app container recreation. Existing sessions from the previous implementation require a fresh login once. Logout clears the current session; deactivation/password-hash changes revoke affected sessions on their next request. Concurrent requests carrying an ID just rotated receive authentication failure; failed mutations are never replayed automatically. See [ADR-005](docs/architecture/ADR-005-session-lifecycle.md) and [module alignment](docs/quality/training-module-alignment.md) for decisions and deployment limits.

HTTP smoke checks (Python standard library): `python3 tests/HTTP/session-smoke.py --url http://localhost:8080`. `--short-timeouts` requires a separate target configured with idle=4, absolute=10, rotation=1 seconds and separate session storage; never shorten the normal app settings for testing. Native adapter lifecycle tests belong to the integration suite; SessionPolicy and guard/response tests run without native sessions or SQL.

### End-to-end checks and opening balances

Product and warehouse creation now initializes all product/warehouse stock pairs at zero, in the same transaction. Opening zero balances create no receipt/issue ledger movement. Product/warehouse CSV imports retain that atomic boundary. To fill historical missing pairs safely, run `docker compose exec app php scripts/initialize-stock-balances.php`; it preserves existing quantities and ledger. Do not replay schema/seed against an existing application database.

Repeatable full HTTP workflows require a disposable Compose project named `inventory-e2e-*` and its own app/DB ports. Example: `APP_PORT=18085 DB_HOST_PORT=13308 docker compose -p inventory-e2e-verify up -d --build app`, then `python3 tests/HTTP/end-to-end.py --project inventory-e2e-verify --url http://localhost:18085 --output /tmp/inventory-e2e-results.json`. The script checks project labels/port mappings before writes and uses read-only SQL to verify states, balances, ledger/audits and rollback. Never point it at a project containing real data.

Browser regression sources are `tests/Browser/end-to-end-mobile.cjs` and `end-to-end-dialog.cjs`; they require a local Chrome CDP endpoint on 9223 and the disposable app (default :18085, override E2E_URL). Each script closes its browser when finished. Current evidence and exact offline-build limitations are in `docs/testing/end-to-end-verification-2026-10-07.md`.

Offline verification support: `tests/Support/Dockerfile.offline-verification` and `Dockerfile.offline-runtime` reuse existing locally verified images. Use this fallback only with matching `composer.lock`; it does not establish a fresh internet dependency build. Results and limitations: [end-to-end verification](docs/testing/end-to-end-verification-2026-10-07.md).
