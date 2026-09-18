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

