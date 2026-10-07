# Sidebar Profile logout verification

Requirements: AUTH-02, UI-01; server security preserved under AUTH-01.

Logout moved from the Users toolbar to a shared Profile disclosure menu included by all 26 authenticated templates. Navigation moves the rendered menu into the sidebar, including its mobile drawer. Native details/summary supports keyboard interaction; the form remains usable without JavaScript. No new profile editing workflow is introduced.

The shared PHP partial escapes the session CSRF token and submits POST /logout. Existing server CSRF validation and session logout behavior are retained. There are no persistence/schema or stock changes.

Executed on 2026-10-06:

- `node --test tests/JavaScript/*.test.js`: 41 passed.
- `git diff --check`: passed.
- Docker app rebuild/start: passed; final CSS token correction copied to running app container.
- Container `composer test:unit`: 218 tests, 617 assertions, passed.
- Container PHPStan over app/config/public at level 5: 109 files, no errors; existing dependency age advisory.
- HTTP smoke for Admin, Sales, Warehouse Staff: dashboard contains Profile and exactly one logout form; invalid logout CSRF returns 403; valid logout redirects to login; subsequent dashboard access redirects to login. All passed.

No integration suite run because persistence did not change. Browser visual verification at desktop/360px was not performed; the existing responsive sidebar is reused.

## Profile placement follow-up

User requested Profile at the bottom of the sidebar without a separator or arrow. Navigation now appends the shared menu after the main navigation; the flex column sidebar pushes Profile down with an auto margin. The top border was removed and native disclosure markers are hidden while keyboard disclosure remains available. The mobile drawer uses dynamic viewport height.

Verification: 41 JavaScript tests passed; git diff --check passed. No new tests or persistence changes. Browser visual verification remains pending.

Follow-up container verification: app rebuild/start passed; composer test:unit passed (218 tests, 617 assertions); PHPStan level 5 passed (109 files, no errors, existing dependency age advisory).
