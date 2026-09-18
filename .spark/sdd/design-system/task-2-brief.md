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

