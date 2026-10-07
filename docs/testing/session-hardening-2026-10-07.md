# Session hardening verification — 7 October 2026

Requirement IDs: AUTH-01/02, USR-01, API-01, ERR-01, UI-01, ARCH-01/02, TEST-01/03. Official brief p. 5 is authoritative for authentication/logout; the added timeout defaults are engineering decisions.

Mandatory/Optional: hardening of mandatory authentication, authorization, errors and native architecture. No framework, Redis, new role/status or optional infrastructure was introduced.

Files changed:

- Runtime: `app/Security/SessionPolicy.php`, `NativeSessionManager.php`, `SessionManager.php`, `AuthGuard.php`; `app/Service/AuthService.php`; `app/Http/Response.php`; `public/index.php`; `config/config.php`.
- Environment/storage: `.env.example`, `compose.yaml`, `Dockerfile`.
- Fetch feedback: `public/assets/js/http.js`, `forms.js`, `modal.js`.
- Tests: `tests/Unit/SessionPolicyTest.php`, `SessionLifecycleTest.php`; `tests/Integration/NativeSessionLifecycleIntegrationTest.php`; `tests/JavaScript/http.test.js`, `modal-validation.test.js`; `tests/HTTP/session-smoke.py`.
- Documentation: README, SDD, KNOWLEDGE, ai-usage-log, tech-debt, ADR-005, training-module-alignment, this evidence and `docs/testing/session-hardening/` artifacts. Previous user-authorized uncommitted changes remain intact.

Behavior implemented:

- Inclusive idle=1800s, absolute=28800s and rotation=900s defaults, validated/configurable; deterministic pure clock-driven policy tests. Activity/rotation never extends original login origin.
- Strict cookie-only session identifiers; browser-lifetime host-only Path=/ HttpOnly SameSite=Lax cookie. Secure automatically required for production, explicit TLS-termination setting, arbitrary forwarded headers ignored.
- ID/CSRF rotation on login/logout/role changes; preserve CSRF on periodic rotation. Login/logout remove all previous data, old IDs contain no authentication. Unsupported/malformed auth/lifecycle fails closed.
- Current account status/role/credential version checked by server guard. Password-hash change/deactivation/missing account revokes the session on next request. Obsolete browser role cookie is no longer created and is expired when encountered.
- Protected expired POST authenticates before CSRF validation; page navigation redirects, API responds JSON 401, Fetch receives 401 and displays a sign-in action. No automatic mutation retry; dialog input remains visible on the failed submission.
- All dynamic responses use no-store/private headers while preserving existing content types/redirects/CSV stream. Native session writes finish and unlock before response streaming.
- Dedicated non-root-writable Docker session volume; session persists across app recreation.

Security/authorization: server guard and service role/ownership checks remain authoritative. CSRF is separate from authorization. No credentials/token values are logged in new evidence or browser storage. No session-security decision trusts a browser role or forwarded header.

Transaction/invariants: no SQL schema, stock, ledger or order state mutation changed. PHP per-session file locking remains through application rendering and ends before CSV output; it is independent of StockService's SQL transaction. Old-ID waiting requests are rejected, not granted a forwarding/grace-period alias. Previously authorized SQL work is not retrospectively cancelled by another request's logout.

Tests added/updated: 17 additional PHP cases (including provider expansions) and 3 JavaScript cases. Pure unit cases cover deadlines/configuration/malformed metadata, password revocation and streaming headers; separate-process integration cases exercise actual native storage, strict-mode fixation defense, rotation/replay, logout cleanup, expiry and privilege refresh. Existing real MySQL regression suite also ran in the isolated `_test` database. HTTP script changes only login/logout and read requests; the expired create POST is rejected before persistence.

Commands executed + result:

| Command / environment | Actual result |
|---|---|
| Python pypdf extraction of five training PDFs and official brief | 248 training pages + 19 brief pages extracted locally; relevant chapters reviewed and mapped |
| `docker compose --profile quality run --build --rm test` (final PHP 8.3 / MySQL 8 / Node 22 image) | PHPUnit **306 tests / 1236 assertions**, PHPStan level 5 **no errors**, JavaScript **50 pass / 0 fail**; exit 0 |
| `node --test tests/JavaScript/http.test.js tests/JavaScript/modal-validation.test.js` | 10 focused checks passed |
| `python3 tests/HTTP/session-smoke.py` against main localhost:8080 | **16 checks passed**, recorded in `session-hardening/http-smoke.json` |
| Temporary app container on :18084, idle=4 / absolute=10 / rotation=1 / save_path=/tmp; `session-smoke.py --short-timeouts` | **30 checks passed**, recorded in `session-hardening/http-timeouts.json`; storage isolated from main app |
| `session-smoke.py --pause-for-recreate`, then `docker compose up -d --build --force-recreate app`, then resume | **17 checks passed** in tool stdout, including authenticated dashboard after app recreation; the already-established browser session survived via session-data volume |
| PHP 8.3 recursive `php -l` in running app for app/config/public/scripts/views | **153 files passed** |
| `git diff --check` | Passed |
| Cleanup temporary smoke container and disposable quality DB container | Main app/db retained; session/MySQL volumes not removed |

Initial HTTP fixtures were corrected to inspect Set-Cookie on a new session, use the actual SKU API route, and avoid making the rotation test coincide with the idle deadline at PHP's integer-second resolution. Final outputs above are from passing executions. The application defaults were not shortened for the temporary tests.

Docs/evidence updated: raw final Docker quality output in `session-hardening/quality-output.txt`, two HTTP result files, module mapping, ADR-005 and runtime/setup/knowledge disclosures. No screenshots, hardware checks, multi-host tests or production HTTPS deployment are claimed.

Known gaps/risks: sessions existing before rollout require a fresh login. Strict invalidation means an already-queued old-ID request at a rotation boundary can receive 401; mutations are never replayed automatically. Single-host native storage is not a distributed session store. Local app remains HTTP for development; production requires HTTPS and a suitable PHP HTTP runtime plus storage retention monitoring. Logout ends the current session; active-device inventory/remote logout-all was not added. Configuring a shorter absolute lifetime also shortens PHP file retention for that storage directory, so timeout tests must use separate storage.

Recommended next task: validate the target production TLS/PHP runtime and session-volume cleanup/retention policy; select a shared session repository only if deployment actually needs multiple hosts.
