# Export action in filters

Requirements: UI-01, FIND-01. User requested removal of duplicate Search table and moving Export CSV to the filter section.

Shared table enhancement now appends an Export CSV button to the existing filter section on Products, Purchase Orders and Sales Orders. Pages without a filter section get a labeled Table actions section with the export button. The separate table toolbar, table search, saved client-search restoration and search-only empty-row code were removed. Server search/filter/pagination, client sorting, selection and current-table CSV export remain available. Export uses type=button so it cannot submit the GET filter form. It still exports rendered table rows, not the full database report.

CSS removes obsolete toolbar/search styles and preserves zero spacing between filters, tables and pagination. No persistence, stock, authorization or API changes.

Verification:

- Updated obsolete search test to a behavior test that executes enhancement with/without an existing filter section, checks no search input is created, checks export button type/target/listener, and checks repeated enhancement does not duplicate the button.
- Updated design-system expectations to the new table actions component.
- Node JavaScript suite: 41 tests passed; JS syntax check passed.
- Container unit suite: 218 tests, 617 assertions passed.
- PHPStan level 5: 109 files, no errors; existing dependency age advisory.
- Docker app rebuilt and started successfully.
- Authenticated HTTP smoke: Products search/page 2, PO/SO date sort/page 2 returned 200 with filter and pagination components. Served tables.js contains the new export placement and no Search table markup.
- git diff --check passed.

Browser visual/download verification not performed. No integration suite run because persistence was unchanged.
