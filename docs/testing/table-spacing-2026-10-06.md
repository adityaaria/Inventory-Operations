# Filter, datatable and pagination spacing

Requirements: UI-01, FIND-01. User requested no spacing between filters/pagination and datatables on every applicable page.

Changed only shared CSS:

- Filter bottom margin is zero; top outside corners retain rounding.
- Datatable top/bottom margins are zero.
- Generated table toolbar margins are zero, including desktop and mobile.
- Pagination top margin is zero; only bottom outside corners are rounded.
- Table corners adjoining toolbar/pagination are square; toolbar below server filters is square.

Coverage from repository inspection: Products, Purchase Orders and Sales Orders use server filters and pagination; all nine datatable pages (those three, Users, Categories, Warehouses, Suppliers, Customers and Dashboard) use the generated search/export toolbar. Shared selectors also apply to future pages using the same classes. Product import dialog markup can sit between filters and the toolbar, so a general sibling selector handles its hidden intervening element. No authorization, query, pagination behavior, schema, or stock workflow changes.

Verification on 2026-10-06:

- `node --test tests/JavaScript/*.test.js`: 41 passed.
- `git diff --check`: passed.
- Docker app rebuild/start: passed.
- Container `composer test:unit`: 218 tests, 617 assertions, passed.
- Container PHPStan level 5 over app/config/public: 109 files, no errors; existing dependency age advisory.
- Attempted a temporary Chrome headless measurement script using authenticated HTML, current inline CSS and table enhancement JavaScript at requested widths 360/1440. First browser invocation timed out after 30 seconds, before measurements were returned. Browser spacing verification is therefore not claimed.

No tests added for this reversible CSS change. No integration tests run because persistence did not change.
