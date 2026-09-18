### Task 2: Apply tokens to existing selectors

**Files:**
- Modify: `public/assets/css/app.css` (nine selector blocks, listed below)

**Interfaces:**
- Consumes: `--space-3`, `--space-4`, `--radius-sm`, `--radius-md`, `--radius-lg`, `--radius-pill` from Task 1.
- Produces: no new interface — this is a literal-value cleanup with no behavior change (every replaced value is numerically identical to what it replaces).

Each edit below only changes a value that is an *exact* match for a token — this task does not touch `.metric-card`'s `padding: 1.05rem` or `.button`'s `padding: 0.5rem 0.85rem` (neither cleanly maps to a single token tier, per the design's own "don't force it" rule), and does not touch `.field-error` (it has no spacing/radius properties at all — only color, font-size, and font-weight — so there is nothing to tokenize there).

- [ ] **Step 1: `.button`, `.button-primary` border-radius**

`app.css:213-223`, current:

```css
button,
.button,
.button-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 2.45rem;
    max-width: 100%;
    padding: 0.5rem 0.85rem;
    border: 1px solid var(--line-strong);
    border-radius: 8px;
```

Change only the `border-radius` line:

```css
    border-radius: var(--radius-sm);
```

- [ ] **Step 2: `.metric-card` border-radius**

`app.css:359-366`, current line `border-radius: 10px;` inside `.metric-card { ... }` becomes:

```css
    border-radius: var(--radius-md);
```

- [ ] **Step 3: `table`, `.data-table` border-radius, and th/td padding**

`app.css:386-398`, current:

```css
table,
.data-table {
    width: 100%;
    min-width: 44rem;
    border-collapse: separate;
    border-spacing: 0;
    margin: 1rem 0 1.25rem;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--surface);
    box-shadow: var(--shadow-soft);
}

th,
td,
.data-table th,
.data-table td {
    padding: 0.75rem;
    border: 0;
    border-bottom: 1px solid var(--line);
    text-align: left;
    vertical-align: top;
}
```

Change the two matching lines (`border-radius` in the first block, `padding` in the second):

```css
table,
.data-table {
    width: 100%;
    min-width: 44rem;
    border-collapse: separate;
    border-spacing: 0;
    margin: 1rem 0 1.25rem;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    background: var(--surface);
    box-shadow: var(--shadow-soft);
}

th,
td,
.data-table th,
.data-table td {
    padding: var(--space-3);
    border: 0;
    border-bottom: 1px solid var(--line);
    text-align: left;
    vertical-align: top;
}
```

- [ ] **Step 4: `.status-badge` border-radius**

`app.css:445-460`, current:

```css
.status-badge,
.stock-low,
.status-normal {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 1.6rem;
    max-width: 100%;
    padding: 0.24rem 0.62rem;
    border: 1px solid transparent;
    border-radius: 999px;
```

Change only the `border-radius` line:

```css
    border-radius: var(--radius-pill);
```

- [ ] **Step 5: `.page-loading > *`, `.loading-card` border-radius**

`app.css:889-899`, current:

```css
.page-loading > *,
.loading-card {
    display: inline-flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.85rem 1rem;
    border-radius: 999px;
    background: #ffffff;
    box-shadow: var(--shadow);
    font-weight: 560;
}
```

Change only the `border-radius` line:

```css
    border-radius: var(--radius-pill);
```

- [ ] **Step 6: `.modal-panel`, `.confirm-panel` border-radius**

`app.css:921-930`, current:

```css
.modal-panel,
.confirm-panel {
    width: min(40rem, calc(100% - 2rem));
    max-height: min(86vh, 52rem);
    overflow: auto;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: #ffffff;
    box-shadow: var(--shadow);
}
```

Change only the `border-radius` line:

```css
    border-radius: var(--radius-lg);
```

(Task 3 will add more properties to this same block — do not add the transition properties here, that happens in Task 3 to keep this task's diff purely "literal → token".)

- [ ] **Step 7: `.modal-header` padding and gap**

`app.css:932-942`, current:

```css
.modal-header {
    position: sticky;
    top: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem;
    border-bottom: 1px solid var(--line);
    background: #ffffff;
}
```

Change `gap` and `padding`:

```css
    gap: var(--space-4);
    padding: var(--space-4);
```

- [ ] **Step 8: `.modal-body` padding**

`app.css:949-951`, current:

```css
.modal-body {
    padding: 1rem;
}
```

Change to:

```css
.modal-body {
    padding: var(--space-4);
}
```

- [ ] **Step 9: Rebuild and verify no literal values remain where tokens were applied**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
APP_PORT=8081 docker compose up -d --build app
curl -s http://localhost:8081/assets/css/app.css > /tmp/app.css.check
grep -c "border-radius: 999px;" /tmp/app.css.check
grep -c "border-radius: var(--radius-pill);" /tmp/app.css.check
```

Expected: first command prints `0` (no more literal `999px` radius left), second prints `2` (badge + loading pill).

- [ ] **Step 10: Manual visual check**

Log in at `http://localhost:8081/login` with `admin@example.test` / `password`, open `/products` (table + buttons render unchanged), open `/dashboard` (metric cards render unchanged), and open the "Create Product" modal (panel corners and header spacing render unchanged — this task must produce *zero visible difference*, since every value replaced is numerically identical to the literal it replaced).

- [ ] **Step 11: Commit**

```bash
git add public/assets/css/app.css
git commit -m "style: consume spacing/radius tokens in button, table, badge, and modal selectors"
```

---

