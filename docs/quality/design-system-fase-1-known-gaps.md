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
- Global focus ring (`app.css:130`) — hardcoded blue `outline: 3px solid rgba(29, 78, 216, 0.28)` is not derived from any token; every focused control still rings blue against the new near-black `--primary` palette.
- Button hover/focus drop shadows (`app.css:222`, `:253`, `:262`) — hardcoded blue-tinted `box-shadow` values (`rgba(37, 99, 235, ...)`) now sit under buttons whose background is near-black `#16181D`, creating stark contrast mismatches.
- Loading spinner track border (`app.css:932`) — hardcoded pale blue `border: 2px solid #bfdbfe` clashes with the new palette.
- Page background radial gradients (`app.css:62-63`, `:574-575`) — hardcoded old blue (`rgba(37, 99, 235, ...)`) and old teal (`rgba(15, 118, 110, ...)`) tints remain in the background gradients on every page, still presenting old-palette interference.

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
