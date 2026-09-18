# Design System Refactor — Fase 0 & Fase 1 (Tokens) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Swap the `:root` color palette to the new orange/near-black design system and finish two token-migration jobs left incomplete by the prior UI-polish enhancement (13 literal `#ffffff` → `var(--surface)`, 20 literal `border-radius` values → the existing `--radius-*` tokens), with zero HTML/class changes.

**Architecture:** Everything in this plan touches exactly one file, `public/assets/css/app.css`. No selector is renamed, no markup changes, no JS changes — this is purely `:root` value swaps plus finishing an already-started literal→token migration. Component-level restyling (card, button, badge, table, sidebar, modal — Fase 2 onward from `refactor-instructions-specific.md`) is explicitly out of scope for this plan.

**Tech Stack:** Vanilla CSS custom properties (existing token system from the prior two enhancements). No build step.

## Global Constraints

- Source of truth for every value change in this plan is `refactor-instructions-specific.md` (pasted by the user) — do not invent any color not explicitly given there.
- No git in this repository (confirmed again this session — `git status` still returns "fatal: not a git repository"). Skip any "commit"/"branch" step; there is no way to create `refactor/design-system` as an actual git branch. Track phase completion via the `.spark/sdd/design-system/progress.md` ledger instead, same pattern as the two prior enhancements this session.
- **Known, deliberately unaddressed gap** (must be called out in the final report to the user, not silently left implicit): `--primary-dark` (`#1d4ed8`, a blue) and `--accent-soft` (`#ccfbf1`, a mint), plus the hardcoded `rgba(15, 118, 110, 0.2)` border and `--surface-tint` (`#eef6f4`, mint-tinted) used by `.app-title`'s chip (`app.css:155-163`), are companions to the *old* blue/teal palette. `refactor-instructions-specific.md` gives no replacement values for any of these. After this plan, hover states on `.button-primary`/`button[type="submit"]` will transition from near-black to blue (`--primary-dark` unchanged), and `.app-title`'s pill chip will still render with a teal-tinted border/background against the new orange accent elsewhere on the page — a visible, known mismatch. This plan does not guess replacement values for these; it flags them as blocked on `design-system.md` (which the user has not yet provided), consistent with the "Mulai dari Fase 1 dulu" decision.
- Only values explicitly listed in this plan's tasks change. `--bg`, `--surface`, `--surface-muted`, `--surface-tint`, `--text`, `--muted`, `--line`, `--line-strong`, `--info`, `--info-bg`, `--draft`, `--draft-bg`, `--shadow`, `--shadow-soft`, `--radius`, `--primary-dark`, `--accent-soft` are NOT touched by this plan (not given new values in the source instructions).
- Font: no change. `refactor-instructions-specific.md` confirms Inter is already correct and defers the font-size scale audit (`--fs-*` tokens don't exist yet, and the source instructions only gave 4 example sizes without a complete scale or token names) — out of scope for this plan, blocked on the same missing `design-system.md`.
- The running container does not bind-mount source; every verification rebuild uses `APP_PORT=8081 docker compose up -d --build app`.

---

### Task 1: Update the `:root` color palette

**Files:**
- Modify: `public/assets/css/app.css:1-27` (the `:root` block)

**Interfaces:**
- Produces: new values for `--primary`, `--accent`, `--success`, `--success-bg`, `--warning`, `--warning-bg`, `--danger`, `--danger-bg`, consumed implicitly by every existing selector already using `var(--primary)` etc. (no selector needs editing — that's the point of a token system).

- [ ] **Step 1: Apply the exact palette swap**

Current `app.css:1-27`:

```css
:root {
    --bg: #f6f8fb;
    --surface: #ffffff;
    --surface-muted: #f8fafc;
    --surface-tint: #eef6f4;
    --text: #111827;
    --muted: #667085;
    --line: #dde3ea;
    --line-strong: #b6c2d0;
    --primary: #2563eb;
    --primary-dark: #1d4ed8;
    --accent: #0f766e;
    --accent-soft: #ccfbf1;
    --success: #166534;
    --success-bg: #dcfce7;
    --warning: #92400e;
    --warning-bg: #fef3c7;
    --danger: #991b1b;
    --danger-bg: #fee2e2;
    --info: #075985;
    --info-bg: #e0f2fe;
    --draft: #475569;
    --draft-bg: #e2e8f0;
    --shadow: 0 22px 70px rgba(15, 23, 42, 0.10);
    --shadow-soft: 0 10px 28px rgba(15, 23, 42, 0.06);
    --radius: 8px;
    ...
}
```

Replace ONLY these 8 lines (leave every other line in the block — `--bg` through `--line-strong`, `--primary-dark`, `--accent-soft`, `--info`/`--info-bg`, `--draft`/`--draft-bg`, `--shadow`/`--shadow-soft`, `--radius`, and the `--space-*`/`--radius-*` tokens further down — completely untouched):

```css
    --primary: #16181D;
    --accent: #FF5A1F;
    --success: #17A34A;
    --success-bg: #E7F7ED;
    --warning: #D69411;
    --warning-bg: #FDF3DC;
    --danger: #E23D3D;
    --danger-bg: #FDECEC;
```

- [ ] **Step 2: Rebuild and verify the new values are served**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
APP_PORT=8081 docker compose up -d --build app
curl -s http://localhost:8081/assets/css/app.css | grep -- "--primary: #16181D;"
curl -s http://localhost:8081/assets/css/app.css | grep -- "--accent: #FF5A1F;"
curl -s http://localhost:8081/assets/css/app.css | grep -- "--primary-dark: #1d4ed8;"
```

Expected: the first two commands each print one matching line; the third also prints a match — confirming `--primary-dark` was deliberately left unchanged (the known gap), not silently dropped.

- [ ] **Step 3: Manual visual check**

Log in at `http://localhost:8081/login`, open `/dashboard` and `/products`. Buttons, focus rings, and the "In stock"/"Low stock"/"Draft" badges should now render in the new palette. The `.app-title` pill chip at the top of most pages (and hover states on primary buttons) will look visually inconsistent against the new accent — this is the documented known gap from Global Constraints, not a bug to fix in this task.

- [ ] **Step 4: Skip commit** — no git in this repository.

---

### Task 2: Finish the `#ffffff` → `var(--surface)` token migration

**Files:**
- Modify: `public/assets/css/app.css` (13 selectors, listed below)

**Interfaces:**
- Produces: no new interface — a literal-value cleanup with no behavior/visual change (every replaced value is numerically identical to what it replaced, since `--surface: #ffffff` is unchanged by Task 1).

This task is independent of Task 1's color values — it only reduces duplication of a value that isn't changing. Do NOT touch `app.css:3` (`--surface: #ffffff;` itself — that's the token definition, not a consumer of it).

For each line below, change only the literal `#ffffff` to `var(--surface)`, leaving every surrounding property untouched.

- [ ] **Step 1: `button[type="submit"]:hover, button[type="submit"]:focus` — hover text color**

Locate (the selector block containing both a `:hover` and `:focus` rule for `button[type="submit"]`, each with `color: #ffffff;`):

```css
    color: #ffffff;
```

There are two separate occurrences of this exact line in that general area of the file (one in the `:hover` rule, one in the adjacent `:focus` rule for `.button-primary`). Change **both** to:

```css
    color: var(--surface);
```

- [ ] **Step 2: `.filters` — gradient background**

Locate (a rule with `padding: 1.05rem; border: 1px solid var(--line); border-radius: 10px;` immediately followed by a `linear-gradient` background — this is the `.filters` container, the search/filter bar above data tables):

```css
    background: linear-gradient(180deg, #ffffff, var(--surface-muted));
```

Change to:

```css
    background: linear-gradient(180deg, var(--surface), var(--surface-muted));
```

- [ ] **Step 3: `.metric-card` — gradient background**

Locate (`.metric-card { ... border-radius: var(--radius-md); background: linear-gradient(180deg, #ffffff, #f8fafc); ... }`):

```css
    background: linear-gradient(180deg, #ffffff, #f8fafc);
```

Change to:

```css
    background: linear-gradient(180deg, var(--surface), #f8fafc);
```

(Only the first stop changes — `#f8fafc` is `--surface-muted`'s literal value used as a second gradient stop elsewhere too; that literal is not in scope for this plan, only exact `#ffffff` occurrences are.)

- [ ] **Step 4: `.report-panel` — gradient background**

Locate (`.report-panel { padding: 1.05rem; border: 1px solid var(--line); border-radius: 10px; background: linear-gradient(180deg, #ffffff, var(--surface-muted)); }`):

```css
    background: linear-gradient(180deg, #ffffff, var(--surface-muted));
```

Change to:

```css
    background: linear-gradient(180deg, var(--surface), var(--surface-muted));
```

(This is a second, separate occurrence of the same literal gradient pattern as Step 2, in a different selector — `.report-panel`, not `.filters`. Confirm you're editing the correct block by checking the selector name directly above it.)

- [ ] **Step 5: `.pagination` — background**

Locate (`.pagination { display: flex; ... border-radius: 10px; background: #ffffff; box-shadow: var(--shadow-soft); }`):

```css
    background: #ffffff;
```

Change to:

```css
    background: var(--surface);
```

- [ ] **Step 6: `.pagination a, .pagination span[aria-disabled="true"]` — background**

Locate (the pagination link/disabled-span rule, with `border-radius: 8px; background: #ffffff;`):

```css
    background: #ffffff;
```

Change to:

```css
    background: var(--surface);
```

- [ ] **Step 7: `.brand-mark` — text color**

Locate (`.brand-mark { display: inline-grid; place-items: center; width: 2.65rem; height: 2.65rem; border-radius: 8px; background: var(--primary); color: #ffffff; }` — the square logo mark in the sidebar brand):

```css
    color: #ffffff;
```

Change to:

```css
    color: var(--surface);
```

- [ ] **Step 8: `.table-toolbar` — background**

Locate (`.table-toolbar { ... border-radius: 10px 10px 0 0; background: #ffffff; }`):

```css
    background: #ffffff;
```

Change to:

```css
    background: var(--surface);
```

- [ ] **Step 9: `.dashboard-panel, .quick-panel` — background**

Locate (`.dashboard-panel, .quick-panel { padding: 1.05rem; border: 1px solid var(--line); border-radius: 10px; background: #ffffff; }`):

```css
    background: #ffffff;
```

Change to:

```css
    background: var(--surface);
```

- [ ] **Step 10: `.page-loading > *, .loading-card` — background**

Locate (`.page-loading > *, .loading-card { ... border-radius: var(--radius-pill); background: #ffffff; box-shadow: var(--shadow); ... }`):

```css
    background: #ffffff;
```

Change to:

```css
    background: var(--surface);
```

- [ ] **Step 11: `.modal-panel, .confirm-panel` — background**

Locate (`.modal-panel, .confirm-panel { ... border-radius: var(--radius-lg); background: #ffffff; box-shadow: var(--shadow); ... }`):

```css
    background: #ffffff;
```

Change to:

```css
    background: var(--surface);
```

- [ ] **Step 12: `.modal-header` — background**

Locate (`.modal-header { position: sticky; top: 0; ... gap: var(--space-4); padding: var(--space-4); border-bottom: 1px solid var(--line); background: #ffffff; }`):

```css
    background: #ffffff;
```

Change to:

```css
    background: var(--surface);
```

- [ ] **Step 13: Verify all 13 are done and the token definition itself is untouched**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
grep -c "#ffffff" public/assets/css/app.css
grep -n "#ffffff" public/assets/css/app.css
```

Expected: count is `1`, and the single remaining line is `app.css:3` (`--surface: #ffffff;` — the definition itself, correctly left alone).

- [ ] **Step 14: Rebuild and confirm zero visual change**

```bash
APP_PORT=8081 docker compose up -d --build app
```

Every value replaced was numerically identical to what it replaced (`var(--surface)` still resolves to `#ffffff` after Task 1, since Task 1 didn't touch `--surface`). Visit `/dashboard`, `/products`, and open the Create Product modal — nothing should look different from before this task (only from Task 1's palette change, which is a separate, expected difference).

- [ ] **Step 15: Skip commit** — no git in this repository.

---

### Task 3: Finish the literal `border-radius` → token migration

**Files:**
- Modify: `public/assets/css/app.css` (18 selectors get tokenized; 2 legitimate `border-radius: 0` resets are explicitly left alone — see Step 19)

**Interfaces:**
- Consumes: `--radius-sm` (8px), `--radius-md` (10px), `--radius-lg` (12px), `--radius-pill` (999px) — all already defined in `:root` from the prior UI-polish enhancement, untouched by Task 1.
- Produces: no new interface — every replacement is numerically identical to the literal it replaces.

For each step, change only the `border-radius` line shown, leaving every surrounding property untouched. Locate each by its selector name and surrounding properties (line numbers may have shifted slightly from Tasks 1-2's edits — always confirm by the selector name and neighboring properties, not by trusting an exact line number).

- [ ] **Step 1: `.page`**

Locate (`.page { width: min(1180px, calc(100% - 36px)); margin: 34px auto; padding: 30px; background: rgba(255, 255, 255, 0.96); border: 1px solid var(--line); border-radius: 10px; }`):

```css
    border-radius: 10px;
```
→
```css
    border-radius: var(--radius-md);
```

- [ ] **Step 2: `.app-title`**

Locate (`.app-title { display: inline-flex; align-items: center; width: fit-content; margin-bottom: 0.7rem; padding: 0.25rem 0.6rem; border: 1px solid rgba(15, 118, 110, 0.2); border-radius: 999px; background: var(--surface-tint); }`):

```css
    border-radius: 999px;
```
→
```css
    border-radius: var(--radius-pill);
```

(Do not touch the `rgba(15, 118, 110, 0.2)` border color or `var(--surface-tint)` background on this same selector — those are part of the documented known gap in Global Constraints, out of scope here.)

- [ ] **Step 3: `.toolbar a, .action-link`**

Locate (`.toolbar a, .action-link { display: inline-flex; align-items: center; justify-content: center; min-height: 2.45rem; padding: 0.55rem 0.9rem; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); ... }`):

```css
    border-radius: 8px;
```
→
```css
    border-radius: var(--radius-sm);
```

(This is a distinct selector from `.button`/`.button-primary`, which already uses `var(--radius-sm)` from the prior enhancement — do not confuse the two blocks.)

- [ ] **Step 4: `.form textarea, .filters input, .filters select`**

Locate (`.form textarea, .filters input, .filters select { width: 100%; min-height: 2.45rem; border: 1px solid var(--line-strong); border-radius: 8px; }`):

```css
    border-radius: 8px;
```
→
```css
    border-radius: var(--radius-sm);
```

- [ ] **Step 5: `.filters`**

Locate (the filter-bar container: `grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); align-items: end; gap: 0.75rem; margin: 1rem 0 1.4rem; padding: 1.05rem; border: 1px solid var(--line); border-radius: 10px;` — this is the block Task 2 Step 2 already edited for its `background`):

```css
    border-radius: 10px;
```
→
```css
    border-radius: var(--radius-md);
```

- [ ] **Step 6: `td input[type="number"]`**

Locate (`td input[type="number"] { width: 6.5rem; min-height: 2.2rem; border: 1px solid var(--line-strong); border-radius: 8px; }`):

```css
    border-radius: 8px;
```
→
```css
    border-radius: var(--radius-sm);
```

- [ ] **Step 7: `.report-panel`**

Locate (the block Task 2 Step 4 already edited for its `background`: `padding: 1.05rem; border: 1px solid var(--line); border-radius: 10px;`):

```css
    border-radius: 10px;
```
→
```css
    border-radius: var(--radius-md);
```

- [ ] **Step 8: `.alert`**

Locate (`.alert { margin: 0 0 1rem; padding: 0.85rem 1rem; border: 1px solid #fca5a5; border-radius: 8px; }`):

```css
    border-radius: 8px;
```
→
```css
    border-radius: var(--radius-sm);
```

(Leave `border: 1px solid #fca5a5;` as-is — not in scope, no instruction covers it.)

- [ ] **Step 9: `.empty`**

Locate (`.empty { margin: 1rem 0; padding: 1rem; border: 1px dashed var(--line-strong); border-radius: 10px; }`):

```css
    border-radius: 10px;
```
→
```css
    border-radius: var(--radius-md);
```

- [ ] **Step 10: `.pagination`**

Locate (the block Task 2 Step 5 already edited for its `background`):

```css
    border-radius: 10px;
```
→
```css
    border-radius: var(--radius-md);
```

- [ ] **Step 11: `.pagination a, .pagination span[aria-disabled="true"]`**

Locate (the block Task 2 Step 6 already edited for its `background`):

```css
    border-radius: 8px;
```
→
```css
    border-radius: var(--radius-sm);
```

- [ ] **Step 12: `.brand-mark`**

Locate (the block Task 2 Step 7 already edited for its `color`):

```css
    border-radius: 8px;
```
→
```css
    border-radius: var(--radius-sm);
```

- [ ] **Step 13: `.side-nav a`**

Locate (`.side-nav a { display: flex; align-items: center; min-height: 2.55rem; padding: 0.6rem 0.72rem; border-radius: 8px; }`):

```css
    border-radius: 8px;
```
→
```css
    border-radius: var(--radius-sm);
```

- [ ] **Step 14: `.table-toolbar` (top corners only)**

Locate (`.table-toolbar { ... margin: 1rem 0 -0.15rem; padding: 0.8rem; border: 1px solid var(--line); border-radius: 10px 10px 0 0; }` — the block Task 2 Step 8 already edited for its `background`):

```css
    border-radius: 10px 10px 0 0;
```
→
```css
    border-radius: var(--radius-md) var(--radius-md) 0 0;
```

(This one is a compound value — top-left and top-right corners rounded, bottom corners square, since this toolbar sits directly above a table. `var()` is valid inside a multi-value `border-radius` shorthand.)

- [ ] **Step 15: `.table-search input`**

Locate (`.table-search input { min-height: 2.45rem; border: 1px solid var(--line-strong); border-radius: 8px; }`):

```css
    border-radius: 8px;
```
→
```css
    border-radius: var(--radius-sm);
```

- [ ] **Step 16: `.dashboard-panel, .quick-panel`**

Locate (the block Task 2 Step 9 already edited for its `background`):

```css
    border-radius: 10px;
```
→
```css
    border-radius: var(--radius-md);
```

- [ ] **Step 17: `.chart-track`**

Locate (`.chart-track { height: 0.7rem; overflow: hidden; border-radius: 999px; }`):

```css
    border-radius: 999px;
```
→
```css
    border-radius: var(--radius-pill);
```

- [ ] **Step 18: `.quick-list a`**

Locate (`.quick-list a { display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.7rem 0.75rem; border: 1px solid var(--line); border-radius: 8px; }`):

```css
    border-radius: 8px;
```
→
```css
    border-radius: var(--radius-sm);
```

- [ ] **Step 19: spinner (`.page-loading span, .loading-card span, button.is-loading::before`)**

Locate (`.page-loading span, .loading-card span, button.is-loading::before { width: 1rem; height: 1rem; border: 2px solid #bfdbfe; border-top-color: var(--primary); border-radius: 999px; animation: spin 0.75s linear infinite; }`):

```css
    border-radius: 999px;
```
→
```css
    border-radius: var(--radius-pill);
```

(Leave `border: 2px solid #bfdbfe;` and `border-top-color: var(--primary);` as-is — not in scope.)

- [ ] **Step 20: `.table-toolbar` inside the mobile media query**

Locate, inside the `@media` block near the end of the file, the mobile-responsive override for `.table-toolbar`:

```css
    .table-toolbar {
        display: grid;
        border-radius: 10px;
```

Change only the `border-radius` line:

```css
        border-radius: var(--radius-md);
```

- [ ] **Step 21: Do NOT touch the two legitimate `border-radius: 0` resets**

Two lines — `.modal-body .page` (`border: 0; border-radius: 0;`, a reset for the page content rendered inside a modal) and the mobile media-query override for `.page` (`border: 0; border-radius: 0;`, same reset for full-width mobile) — set `border-radius: 0` deliberately, meaning "no rounding at all" (the element is meant to look flush with its container, not "use the smallest token"). `0` is not a token candidate; leave both of these exactly as they are. Confirm you have not changed either by checking:

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
grep -c "border-radius: 0;" public/assets/css/app.css
```

Expected: `2` (both resets still present, unchanged).

- [ ] **Step 22: Verify no literal `border-radius` values remain except the two intentional resets**

```bash
grep -n "border-radius: [0-9]" public/assets/css/app.css
```

Expected: exactly 2 lines printed, both reading `border-radius: 0;` (the two resets from Step 21). No other numeric literal should remain.

- [ ] **Step 23: Rebuild and manual visual check**

```bash
APP_PORT=8081 docker compose up -d --build app
```

Visit `/products` (filters bar, pagination, table toolbar), `/dashboard` (dashboard panels, chart track, quick list), and open a Create/Edit modal — every value replaced is numerically identical to its literal, so nothing should look different from before this task.

- [ ] **Step 24: Skip commit** — no git in this repository.

---

### Task 4: Regression pass and known-gap report

**Files:** none (verification + one new short doc)

- [ ] **Step 1: Full regression**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --test tests/JavaScript/*.test.js
docker compose exec -T app composer test
docker compose exec -T app composer analyse
```

Expected: JS suite unchanged (`pass 17, fail 0` — this plan touches no JS file); PHPUnit all-green (this plan touches no PHP file, so the count should be unchanged from the last recorded run); PHPStan 0 errors.

- [ ] **Step 2: Confirm zero HTML/JS/PHP files were touched by this plan**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
find views app public/assets/js -newer docs/spark/plans/2026-09-11-design-system-fase-0-1-tokens.md 2>/dev/null
```

Expected: no output — confirming this plan really did stay CSS-only as scoped, before it's handed off as the foundation for Fase 2 onward.

- [ ] **Step 3: Write a short known-gaps note**

Create `docs/quality/design-system-fase-1-known-gaps.md`:

```markdown
# Design System Fase 1 — Known Gaps

Date: 2026-09-11
Scope: `:root` palette swap + border-radius/#ffffff token-migration cleanup only.
Blocked on: `design-system.md`, `components.css`, `js/table-select.js` (referenced by
`refactor-instructions-specific.md` but not present in this repository).

## Visible mismatches introduced by this phase

- `--primary-dark` (#1d4ed8, blue) is unchanged — hover/focus states on
  `.button-primary` and `button[type="submit"]` transition from the new
  near-black `--primary` to a blue that no longer matches the palette.
- `--accent-soft` (#ccfbf1, mint) is unchanged — nothing currently consumes
  it directly in a way that visibly clashes yet, but it no longer pairs with
  the new orange `--accent`.
- `.app-title`'s pill chip (`app.css:155-163`) uses a hardcoded
  `rgba(15, 118, 110, 0.2)` border (teal-based) and `var(--surface-tint)`
  (`#eef6f4`, mint-tinted background) — both visibly mismatched against the
  new orange accent used elsewhere on the same page.

## Deferred entirely (not started)

- Font-size/font-weight token scale (`--fs-*`/`--fw-*`) — none exist yet;
  `refactor-instructions-specific.md` gave 4 example sizes (badge 11px,
  table 13px, page title 20px, big number 24px) without a complete scale or
  token names.
- Fase 2 onward (card, button variants, badge color mapping, table, sidebar,
  modal, and the two genuinely new components — dropdown/toggle and
  bulk-action-bar) — all blocked on the same three missing files.

## What unblocks the rest

The user needs to provide `design-system.md` (full token scale including
the above), `components.css` (toggle switch spec), and `js/table-select.js`
(bulk-action-bar event-delegation reference pattern) before Fase 2 can be
planned with the same precision as this phase.
```

- [ ] **Step 4: Skip commit** — no git in this repository.
