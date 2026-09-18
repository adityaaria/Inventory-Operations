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

```bash
composer install
```

Start the application and database:

```bash
docker compose up --build
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
composer test
composer test:unit
composer test:integration
composer analyse
```

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

## Known Limitations

- This workspace is not currently a Git repository, so commit history, `refactor:` commit evidence, and release tags cannot be produced here.
- Host port `8080` may be occupied on this workstation; verified fallback port is `8082`.
