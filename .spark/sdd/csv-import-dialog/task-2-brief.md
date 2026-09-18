### Task 2: Wire `dialog.js` into every page and into `app.js`

**Files:**
- Modify: `public/assets/js/app.js`
- Modify: 28 view files + `app/Controller/HomeController.php` (29 files total — every file that currently loads `/assets/js/modal.js`)

**Interfaces:**
- Consumes: `InventoryDialog.enhance()` from Task 1.

- [ ] **Step 1: Add the script tag to all 29 files**

In every one of these files, find the line `<script defer src="/assets/js/modal.js"></script>` and insert a new line directly after it, before `<script defer src="/assets/js/charts.js"></script>`:

```html
    <script defer src="/assets/js/dialog.js"></script>
```

Files (confirmed by `grep -rl "assets/js/app.js" views app`):
`views/auth/login.php`, `views/categories/create.php`, `views/categories/edit.php`, `views/categories/index.php`, `views/customers/create.php`, `views/customers/edit.php`, `views/customers/index.php`, `views/dashboard/index.php`, `views/errors/403.php`, `views/products/create.php`, `views/products/edit.php`, `views/products/index.php`, `views/purchase-orders/create.php`, `views/purchase-orders/index.php`, `views/purchase-orders/show.php`, `views/reports/index.php`, `views/sales-orders/create.php`, `views/sales-orders/index.php`, `views/sales-orders/show.php`, `views/suppliers/create.php`, `views/suppliers/edit.php`, `views/suppliers/index.php`, `views/users/create.php`, `views/users/edit.php`, `views/users/index.php`, `views/warehouses/create.php`, `views/warehouses/edit.php`, `views/warehouses/index.php`, `app/Controller/HomeController.php`.

Every one of these files currently has the identical two-line sequence:
```
    <script defer src="/assets/js/modal.js"></script>
    <script defer src="/assets/js/charts.js"></script>
```
becoming:
```
    <script defer src="/assets/js/modal.js"></script>
    <script defer src="/assets/js/dialog.js"></script>
    <script defer src="/assets/js/charts.js"></script>
```

- [ ] **Step 2: Wire `InventoryDialog.enhance()` into `app.js`**

Current `public/assets/js/app.js:13-22`:

```js
    document.addEventListener('DOMContentLoaded', () => {
        modal.ensureOverlays();
        InventoryNavigation.buildShell();
        tables.enhance();
        InventoryCharts.render();
        enhanceLinks();
        enhanceCancelButtons();
        forms.enhanceForms();
        modal.setEnhanceForms(forms.enhanceForms);
    });
```

Add one line after `enhanceCancelButtons();`:

```js
    document.addEventListener('DOMContentLoaded', () => {
        modal.ensureOverlays();
        InventoryNavigation.buildShell();
        tables.enhance();
        InventoryCharts.render();
        enhanceLinks();
        enhanceCancelButtons();
        InventoryDialog.enhance();
        forms.enhanceForms();
        modal.setEnhanceForms(forms.enhanceForms);
    });
```

- [ ] **Step 3: Syntax-check**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --check public/assets/js/app.js
```

Expected: no output, exit code 0.

- [ ] **Step 4: PHP-lint the one changed PHP file**

```bash
docker compose exec -T app php -l app/Controller/HomeController.php
```

Expected: `No syntax errors detected in app/Controller/HomeController.php`.

- [ ] **Step 5: Confirm every one of the 29 files now loads `dialog.js`, and no file was missed or duplicated**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
grep -rl 'assets/js/dialog.js' views app | wc -l
grep -rc 'assets/js/dialog.js' views app | grep -v ':1$'
```

Expected: first command prints `29`; second command prints nothing (every matching file has the tag exactly once — `grep -v ':1$'` filters out the normal `:1` count, so any remaining line would mean a file got 0 or 2+ copies).

- [ ] **Step 6: Regression + rebuild**

```bash
node --test tests/JavaScript/*.test.js
APP_PORT=8081 docker compose up -d --build app
```

Expected: `pass 17, fail 0`.

- [ ] **Step 7: Skip commit** — no git in this repository.

---

