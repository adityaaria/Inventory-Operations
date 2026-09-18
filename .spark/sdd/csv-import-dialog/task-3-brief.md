### Task 3: `.import-dialog` CSS

**Files:**
- Modify: `public/assets/css/app.css`

**Interfaces:**
- Produces: `.import-dialog` selector matching the visual contract of `.modal-backdrop`/`.confirm-backdrop`, consumed by Task 4's markup.

- [ ] **Step 1: Add `.import-dialog` to the position/background rule**

Current (`app.css:879-888`):

```css
.page-loading,
.modal-backdrop,
.confirm-backdrop {
    position: fixed;
    inset: 0;
    z-index: 40;
    display: grid;
    place-items: center;
    background: rgba(15, 23, 42, 0.32);
}
```

Change to:

```css
.page-loading,
.modal-backdrop,
.confirm-backdrop,
.import-dialog {
    position: fixed;
    inset: 0;
    z-index: 40;
    display: grid;
    place-items: center;
    background: rgba(15, 23, 42, 0.32);
}
```

- [ ] **Step 2: Add `.import-dialog` to the opacity-transition and `is-open` rules**

Current (`app.css:890-899`):

```css
.modal-backdrop,
.confirm-backdrop {
    opacity: 0;
    transition: opacity 0.18s ease;
}

.modal-backdrop.is-open,
.confirm-backdrop.is-open {
    opacity: 1;
}
```

Change to:

```css
.modal-backdrop,
.confirm-backdrop,
.import-dialog {
    opacity: 0;
    transition: opacity 0.18s ease;
}

.modal-backdrop.is-open,
.confirm-backdrop.is-open,
.import-dialog.is-open {
    opacity: 1;
}
```

- [ ] **Step 3: Add `.import-dialog` to the reduced-motion block**

Current (`app.css:1018-1024`):

```css
@media (prefers-reduced-motion: reduce) {
    .modal-backdrop,
    .confirm-backdrop,
    .modal-panel,
    .confirm-panel {
        transition-duration: 0.01ms;
    }
}
```

Change to:

```css
@media (prefers-reduced-motion: reduce) {
    .modal-backdrop,
    .confirm-backdrop,
    .import-dialog,
    .modal-panel,
    .confirm-panel {
        transition-duration: 0.01ms;
    }
}
```

Do not add any rule for `.import-dialog .modal-panel` or similar — the panel inside the import dialog reuses `.modal-panel`/`.modal-header`/`.modal-body` verbatim and already has every property it needs from the existing rules.

- [ ] **Step 4: Rebuild and verify**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
APP_PORT=8081 docker compose up -d --build app
curl -s http://localhost:8081/assets/css/app.css | grep -c "import-dialog"
```

Expected: `4` (one occurrence in each of the three edits above, plus the reduced-motion block counts as one line containing it — Step 1 adds it once, Step 2 adds it twice — opacity rule and `is-open` rule — and Step 3 adds it once: 1+2+1 = 4).

- [ ] **Step 5: Manual visual check**

Confirm `/products`, `/dashboard`, and the existing Create/Edit modal on any page still render exactly as before — this task adds a new selector everywhere but should not change any existing element's appearance (nothing in the DOM has `class="import-dialog"` yet — that's Task 4).

- [ ] **Step 6: Skip commit** — no git in this repository.

---

