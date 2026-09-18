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

