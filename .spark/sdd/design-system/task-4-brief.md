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
