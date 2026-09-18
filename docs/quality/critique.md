# Project Critique

Date: 2026-08-31

## Strengths

- The implementation follows the required native PHP layered architecture without framework or ORM shortcuts.
- Stock safety is explicit: receipt and issue operations go through `StockService`, row locks, transaction boundaries, balance updates, ledger writes, and rollback paths.
- The repository interface boundary makes business services testable with in-memory repositories while MySQL integration tests still prove persistence behavior.
- Server-side authorization is present for protected pages, user management, PO creation/receipt, SO ownership, approval, and issue.
- Assessment evidence is traceable to real files and commands instead of screenshots or fabricated output.

## Weaknesses

- The manual composition root in `public/index.php` is verbose and will become harder to maintain if many optional features are added.
- Validation is partly centralized through `InputValidator`, but older service validation still uses direct `InvalidArgumentException` checks.
- The UI has been upgraded into a custom operational SaaS shell with dashboard charts, datatable tools, modal forms, confirmations, loading states, and empty states.
- Git evidence is unavailable in this workspace because the folder is not a Git repository.

## Stock Safety

The most important business invariant is well protected. `StockService` locks rows through `StockRepositoryInterface::lockByProductWarehouse()`, validates quantities after lock for issue, updates balances, appends ledger rows, updates source state through callbacks, and rolls back on exception. Integration tests cover receipt rollback and oversell prevention.

## Security

Passwords use PHP hashing APIs, sessions regenerate on login/logout, protected routes use `AuthGuard`, POST routes require CSRF tokens, and error responses are safe by default. The project now has a baseline audit trail, structured JSON file logging for global exceptions, and login rate limiting by email/IP. Remaining production security work includes immutable audit storage, external observability, stricter audit-failure policy for critical mutations, TLS-backed cookie enforcement, and a broader central authorization policy.

## Testing

The suite covers unit behavior across auth, services, repositories, validation, search, reporting, API, and stock workflows. MySQL integration tests cover seeded users, master data constraints, PO receipt transactions, schema presence, SO oversell prevention, and seed volume. Static analysis runs at PHPStan level 5.

## Maintainability

The codebase is maintainable for the mandatory scope because dependencies are explicit and each layer has a clear owner. The main risks are composition-root growth, cross-cutting audit concerns, and optional feature creep. Future work should preserve vertical slices and add abstractions only when repeated code creates real maintenance cost.
