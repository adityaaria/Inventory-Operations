# JavaScript Decision Records

Companion to `docs/quality/ai-insight-javascript.md`. Each entry follows the format
requested by Bab 11/12 of the training material: context, alternatives considered,
trade-off, and consequence.

## DR-01 — Node built-in test runner instead of Jest

**Date:** 2026-09-10
**Status:** Accepted

**Context.** Bab 6 of the JavaScript training material demonstrates Jest for unit
tests. This project has no `package.json`, no bundler, and no build pipeline
separate from PHP — `public/assets/js/*.js` is served to the browser unmodified.

**Alternatives considered.**

| Option | Why not chosen |
|---|---|
| Jest | Requires a `package.json`, a dependency tree, and a config file for a project that otherwise has zero JS build tooling. Adds a maintenance surface (version upgrades, config drift) disproportionate to nine small modules. |
| Cypress component test | Same dependency-tree concern, plus it assumes a component framework (React/Vue) to mount — this project has none. |
| No automated JS test | Rejected — `docs/quality/javascript-runtime-evidence.md` and the training material's Bab 6 both treat an automated test suite as minimum evidence for Intermediate level. |

**Decision.** Use Node's built-in `node:test` + `node:assert/strict` (available
without any dependency since Node 18). Testability is achieved through manual
dependency injection instead of a mocking framework: `fetchHtml` accepts
`fetchImpl`, `createRequestCoordinator` accepts `controllerFactory`, and
`debounce` accepts `timerApi`.

**Consequence.** No `jest.useFakeTimers()`-style ergonomics — timer control is
one extra constructor parameter per function instead of a global mock. In
exchange, `tests/JavaScript/*.test.js` runs with zero installed dependencies
(`node --test tests/JavaScript/*.test.js`), which matches the project's
constraint of staying dependency-free on the frontend. Verified 2026-09-10:
15 tests, 0 failures.

## DR-02 — Plain JavaScript instead of TypeScript

**Date:** 2026-09-10
**Status:** Accepted

**Context.** Bab 7 of the training material is CORE and dedicates an entire
chapter to TypeScript as a compile-time contract. This project's interaction
layer is written in plain JavaScript.

**Alternatives considered.**

| Option | Why not chosen |
|---|---|
| TypeScript with a build step | Would require a compiler/transpiler pipeline (`tsc`, or a bundler) that does not otherwise exist in this PHP-served project — every `.js` file is currently loaded by the browser as-is, with no build step in between. |
| JSDoc type annotations (no compiler) | Considered as a lighter middle ground; not adopted for this iteration because the module surface is small (9 files, ~700 lines total) and the existing runtime validation (`form-validation.js`) plus the Node test suite already catch the input-shape mistakes JSDoc typing would have caught. |
| Plain JavaScript (current) | Chosen. |

**Decision.** Keep the JavaScript layer untyped. Data-shape correctness is
enforced at three points instead of compile-time: (1) PHP server-side
validation remains the authority for business rules, per
`docs/quality/ai-insight-javascript.md`'s Main Insight; (2) `form-validation.js`
enforces required/numeric/min/max on the client before submit; (3) the Node
test suite pins the exact shape `http.js` and `ui-helpers.js` expect and return.

**Consequence.** Nothing in the JS layer prevents, at authoring time, passing
the wrong shape into `fetchHtml(url, options)` — a mistake here would surface
at runtime or in a failing test, not at compile time. This is the direct
trade-off named in the training material's own framing of `unknown` vs `any`:
this project accepts that trade-off at the JS layer because the actual
authority for data validity (server-side PHP) already sits one level below it.
If the JS module surface grows meaningfully beyond its current ~700 lines, or
gains contributors unfamiliar with the codebase, this decision should be
revisited — TypeScript's main benefit (catching shape drift across files) gets
more valuable as file count and team size grow.

## DR-03 — Dynamic `aria-describedby` wiring, audited per page

**Date:** 2026-09-10
**Status:** Informational (audit, not a new decision)

**Context.** `docs/quality/ai-insight-html-css.md` (2026-09-04) reported "3 of
16 label-bearing view files use `aria-describedby`/`aria-live`/`aria-label`
statically." That number is now stale — the view layer has grown since — and
it also did not account for `forms.js` wiring those attributes in dynamically
on validation failure. This entry replaces it with a fresh, direct audit.

**Method.** `grep -rl "<label" views/` (2026-09-10) found **22** view files
with at least one `<label>`, not 16. For each file: counted `<form>` elements,
counted fields with a `required` attribute, and counted pre-written
`aria-describedby`/`aria-live`/`aria-label` in the markup itself.

**Findings.**

- All 22 files contain at least one `<form>` element, so all of them are in
  scope for `InventoryForms.enhanceForms()`, which runs globally on
  `DOMContentLoaded` via `app.js` (confirmed identical script include order —
  `http.js` → … → `app.js` — across all 28 view files, not just the 22 with
  labels).
- **14 of 22** are create/edit forms with one or more `required` fields
  (1–7 each — `products/create.php` and `products/edit.php` have 7,
  `purchase-orders/create.php` and `sales-orders/create.php` have 6). On these,
  `InventoryForms.validateForm()` genuinely fires, injecting
  `aria-invalid="true"` and a field-scoped `aria-describedby` pointing at a
  `role="alert"` error span when validation fails at submit.
- **8 of 22** are index/filter pages (`customers/index.php`,
  `warehouses/index.php`, `products/index.php`, `suppliers/index.php`,
  `users/index.php`, `categories/index.php`, `reports/index.php`, plus the
  filter portion of a few list views) with `<label>` but **zero** `required`
  fields — these are search/status filters, not validated forms, so there is
  no error state for `aria-describedby` to describe. The earlier report's gap
  does not meaningfully apply to this group.
- Only **1 of 22** (`products/index.php`) has any `aria-describedby` written
  statically in the markup itself — everywhere else, accessible error wiring
  exists only as long as JavaScript loads and runs.

**Consequence.** The real, still-open gap is narrower than previously stated:
it is not "13 of 16 forms lack error wiring," it is "22 of 22 forms have zero
build-time/static guarantee of accessible error wiring — it depends entirely
on `forms.js` loading successfully." A user on a page where JS fails to load
(blocked script, JS error earlier in the page) gets a `required` field with no
programmatic error association at all. Recommended fix: add static
`aria-describedby="{field}-error"` scaffolding (pointing at an initially-empty
`role="alert"` span) directly in the 14 create/edit templates, so the
association exists even before JS attaches, and `forms.js` only needs to fill
the span's text rather than create the association from scratch.
