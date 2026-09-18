# JavaScript Runtime Evidence

Date: 2026-09-10

## Scope

This evidence covers the Vanilla JavaScript interaction layer used by the PHP views.
Business validation, authorization, and stock invariants remain server-side.

## Measured workspace evidence

| Asset | Lines | Bytes | Responsibility |
|---|---:|---:|---|
| `public/assets/js/app.js` | 36 | 1,243 | bootstrap/orchestration |
| `public/assets/js/modal.js` | 201 | 9,086 | modal lifecycle, focus trap, confirmation, Fetch form loading |
| `public/assets/js/navigation.js` | 49 | 1,919 | application shell and navigation |
| `public/assets/js/charts.js` | 33 | 1,458 | dashboard chart rendering |
| `public/assets/js/http.js` | 64 | 1,803 | Fetch response/error boundary and abort signal |
| `public/assets/js/ui-helpers.js` | 49 | 1,616 | confirmation, filtering, sorting, CSV/HTML pure helpers |
| `public/assets/js/form-validation.js` | 58 | 1,767 | browser-side required/numeric feedback |
| `public/assets/js/forms.js` | 137 | 6,636 | form validation, confirmation, submission, error recovery |
| `public/assets/js/tables.js` | 113 | 5,821 | filtering, sorting, persistence, CSV export |

The measurements were produced with `wc -c -l public/assets/js/*.js` after the complete module split.

## Automated behavior evidence

Command:

```text
node --test tests/JavaScript/*.test.js
```

Result on 2026-09-10: 15 tests passed, 0 failed.

Covered behaviors:

- Fetch success and HTTP error handling;
- network failure classification;
- allowed `422` validation response;
- abort signal forwarding;
- request coordination that aborts stale work and marks older results as non-current;
- confirmation matching;
- debounced table filtering with cancellation of pending timer work;
- case-insensitive filtering;
- numeric and text sorting;
- CSV escaping;
- required, numeric, minimum, and maximum form validation.

## Browser evidence boundary

No DevTools waterfall, browser profiler, or full DOM-level E2E report is claimed here.
The browser-control session was checked on 2026-09-10, but no browser instance was
available, so the current evidence remains limited to syntax checks, browser-neutral
behavior tests, HTTP smoke tests, and static asset measurements.
