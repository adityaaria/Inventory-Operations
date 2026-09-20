# Design System Fase 1 — Known Gaps

Date: 2026-09-20
Scope: Core interaction enhancement: semantic tokens, buttons, forms, alerts,
status badges, and modal/dialog states.
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
- Global focus ring is now derived from `--focus-ring`; browser-level contrast and keyboard smoke verification remain manual.
- Some legacy blue-tinted shadows and background gradients remain outside the core interaction slice and should be reviewed in a later visual polish pass.
- Loading spinner track border (`app.css:932`) still uses a legacy pale-blue literal and remains outside this slice.
- Page background radial gradients (`app.css:62-63`, `:574-575`) — hardcoded old blue (`rgba(37, 99, 235, ...)`) and old teal (`rgba(15, 118, 110, ...)`) tints remain in the background gradients on every page, still presenting old-palette interference.

## Implemented in this slice

- Added `--primary-hover`, `--focus-ring`, `--motion-fast`, and `--motion-normal`.
- Added reduced-motion behavior for all transitions/animations.
- Added `.button-danger`, `.button-quiet`, `.field`, `.field-label`, `.field-hint`, and semantic alert variants.
- Added canonical status modifiers while preserving legacy order/stock aliases.
- Added `.modal-footer` and modal close-button focus treatment without changing dialog lifecycle behavior.
- Added `tests/JavaScript/design-system.test.js`; full JavaScript suite passed with 23 tests.

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
