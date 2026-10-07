# Local runtime verification — 2026-10-06

Requirement support: ARCH-01, DB-01, AUTH-01, API-01, TEST-01, TEST-03.

Dockerfile now copies app, config, public, scripts, and views into their respective directories. The previous multi-source COPY merged directory contents into the working directory.

Executed with Docker Desktop's CLI directory added to PATH:

- `docker compose up -d --build`: passed; application on http://localhost:8080, MySQL healthy on host port 3306.
- HTTP GET `/login`: 200, CSRF token and login fields present.
- Demo Admin login with cookie session and CSRF: successful, redirected to `/`, final response 200.
- Authenticated GET `/dashboard`: 200.
- Authenticated GET `/api/products/SKU-DEMO-001/availability`: 200 JSON.
- Copied `tests` and `phpunit.xml` into the running app container, then `docker compose exec -T app composer test:unit`: passed, 218 tests, 617 assertions, PHP 8.3.35.
- `docker compose exec -T app vendor/bin/phpstan analyse --level=5 --memory-limit=256M app config public`: passed, 109 files, no errors; dependency age advisory emitted.

No application authorization, schema, or stock workflow code changed. Integration suite was not run in this task. Tests and PHPUnit config were copied only into the running container; rebuilding the image removes those copied files. Both services remain running.
