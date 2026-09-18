# Task 4: Regression Pass and Known-Gap Report

## Step 1: Regression Tests

### JavaScript Test Suite
```
✔ required fields report an error when empty and clear it when filled (0.685125ms)
✔ number fields enforce valid values and min/max constraints (0.083584ms)
✔ optional empty fields are accepted (0.0695ms)
✔ validateFields returns field errors keyed by field name (0.432875ms)
✔ fetchHtml returns response and body for successful responses (0.652708ms)
✔ fetchHtml rejects unexpected HTTP responses with body context (0.319666ms)
✔ fetchHtml permits explicitly allowed validation responses (0.090709ms)
✔ fetchHtml forwards an abort signal to fetch (0.104292ms)
✔ fetchHtml classifies non-abort transport failures (0.511042ms)
✔ request coordinator aborts the previous request and marks it stale (0.145458ms)
✔ confirmation is required only for state-changing action paths (0.52825ms)
✔ filter matching ignores surrounding whitespace and letter case (0.093833ms)
✔ table values sort numeric values numerically and text values alphabetically (16.361542ms)
✔ CSV cells escape quotes and normalize whitespace (0.138208ms)
✔ debounce keeps only the latest call and can cancel pending work (0.513292ms)
✔ resolveCancelTarget closes the modal when the cancel button is inside one (0.434709ms)
✔ resolveCancelTarget navigates to the cancel href when not inside a modal (0.057041ms)
ℹ tests 17
ℹ suites 0
ℹ pass 17
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 69.328333
```

**Result:** PASS (17 tests, 0 failures — as expected)

### PHPUnit Test Suite
```
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.33
Configuration: /var/www/html/phpunit.xml

...............................................................  63 / 103 ( 61%)
........................................                        103 / 103 (100%)

Time: 00:00.862, Memory: 10.00 MB

OK (103 tests, 423 assertions)
```

**Result:** PASS (103 tests all passing — as expected)

### PHPStan Static Analysis
```
[OK] No errors
```

**Result:** PASS (0 errors — as expected)

## Step 2: File Modification Check

```bash
find views app public/assets/js -newer docs/spark/plans/2026-09-11-design-system-fase-0-1-tokens.md 2>/dev/null
```

**Output:** (empty)

**Result:** PASS — No HTML/JS/PHP files were touched since the plan started. CSS-only refactor confirmed.

## Step 3: Known-Gaps Document

Created: `/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir/docs/quality/design-system-fase-1-known-gaps.md`

**Status:** File created with exact content from task brief:
- Header with date (2026-09-11) and scope
- Three visible mismatches documented
- Deferred work identified
- Blocking requirements listed

## Summary

- **JavaScript regression:** PASS (17/17, no changes)
- **PHPUnit regression:** PASS (103/103, no changes)
- **PHPStan regression:** PASS (0 errors)
- **File modification check:** PASS (no view/JS/PHP touched)
- **Known-gaps document:** CREATED with exact brief content

**Overall Status:** DONE
