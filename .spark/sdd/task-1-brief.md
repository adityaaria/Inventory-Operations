### Task 1: Add spacing and radius design tokens

**Files:**
- Modify: `public/assets/css/app.css:1-26` (the `:root` block)

**Interfaces:**
- Produces: CSS custom properties `--space-1` through `--space-6`, and `--radius-sm`, `--radius-md`, `--radius-lg`, `--radius-pill`, consumed by Task 2 and Task 3.

- [ ] **Step 1: Add the new tokens to `:root`**

Current end of the `:root` block (`app.css:24-26`):

```css
    --shadow: 0 22px 70px rgba(15, 23, 42, 0.10);
    --shadow-soft: 0 10px 28px rgba(15, 23, 42, 0.06);
    --radius: 8px;
}
```

Replace with:

```css
    --shadow: 0 22px 70px rgba(15, 23, 42, 0.10);
    --shadow-soft: 0 10px 28px rgba(15, 23, 42, 0.06);
    --radius: 8px;

    --space-1: 0.25rem;
    --space-2: 0.5rem;
    --space-3: 0.75rem;
    --space-4: 1rem;
    --space-5: 1.5rem;
    --space-6: 2rem;

    --radius-sm: 8px;
    --radius-md: 10px;
    --radius-lg: 12px;
    --radius-pill: 999px;
}
```

`--radius: 8px` is kept as-is (other selectors not touched in this plan may still reference it); `--radius-sm` duplicates its value on purpose so button-radius has its own named token independent of the legacy one.

- [ ] **Step 2: Rebuild and verify the tokens are served**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
APP_PORT=8081 docker compose up -d --build app
curl -s http://localhost:8081/assets/css/app.css | grep -c -- "--space-4: 1rem;"
curl -s http://localhost:8081/assets/css/app.css | grep -c -- "--radius-pill: 999px;"
```

Expected: both commands print `1`.

- [ ] **Step 3: Commit**

```bash
git add public/assets/css/app.css
git commit -m "style: add spacing and radius design tokens"
```

---

