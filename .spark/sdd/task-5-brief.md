### Task 5: Wire cancel buttons in `app.js` and convert the 14 view files

**Files:**
- Modify: `public/assets/js/app.js`
- Modify: 14 view files (listed in Step 2)

**Interfaces:**
- Consumes: `InventoryUi.resolveCancelTarget` (Task 4), `modal.closeModal()` (already public on the `modal` object constructed in `app.js:9`).

- [ ] **Step 1: Add `enhanceCancelButtons` to `app.js`**

Current `public/assets/js/app.js` (full file, 36 lines):

```js
'use strict';

(() => {
    const state = {
        confirmedForms: new WeakSet(),
        activeFormRequest: null,
        modalTrigger: null,
    };
    const modal = InventoryModal.create({state});
    const forms = InventoryForms.create({modal, state});
    const tables = InventoryTables.create();

    document.addEventListener('DOMContentLoaded', () => {
        modal.ensureOverlays();
        InventoryNavigation.buildShell();
        tables.enhance();
        InventoryCharts.render();
        enhanceLinks();
        forms.enhanceForms();
        modal.setEnhanceForms(forms.enhanceForms);
    });

    function enhanceLinks() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || link.target || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const href = link.getAttribute('href') || '';
            if (/\/(create|edit)(\?|$)/.test(href)) {
                event.preventDefault();
                modal.openFormModal(href);
                return;
            }
            if (href.startsWith('/') && !href.endsWith('.csv')) modal.setPageLoading(true);
        });
    }
})();
```

Replace it in full with:

```js
'use strict';

(() => {
    const state = {
        confirmedForms: new WeakSet(),
        activeFormRequest: null,
        modalTrigger: null,
    };
    const modal = InventoryModal.create({state});
    const forms = InventoryForms.create({modal, state});
    const tables = InventoryTables.create();

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

    function enhanceLinks() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || link.target || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const href = link.getAttribute('href') || '';
            if (/\/(create|edit)(\?|$)/.test(href)) {
                event.preventDefault();
                modal.openFormModal(href);
                return;
            }
            if (href.startsWith('/') && !href.endsWith('.csv')) modal.setPageLoading(true);
        });
    }

    function enhanceCancelButtons() {
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-cancel-href]');
            if (!button) return;
            const isInsideModal = Boolean(button.closest('.modal-panel'));
            const target = InventoryUi.resolveCancelTarget(button.dataset.cancelHref, isInsideModal);
            if (target.action === 'close-modal') {
                modal.closeModal();
                return;
            }
            window.location.href = target.href;
        });
    }
})();
```

- [ ] **Step 2: Convert the 14 Cancel links to buttons**

Each edit below is `Modify: <exact path>:<line>`. The old and new lines differ only in tag/attributes — the visible text ("Cancel") and destination are unchanged.

`views/categories/create.php:28`
```
- <a href="/categories">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/categories">Cancel</button>
```

`views/customers/create.php:30`
```
- <a href="/customers">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/customers">Cancel</button>
```

`views/products/create.php:40`
```
- <a href="/products">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/products">Cancel</button>
```

`views/purchase-orders/create.php:57`
```
- <a href="/purchase-orders">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/purchase-orders">Cancel</button>
```

`views/sales-orders/create.php:57`
```
- <a href="/sales-orders">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/sales-orders">Cancel</button>
```

`views/suppliers/create.php:30`
```
- <a href="/suppliers">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/suppliers">Cancel</button>
```

`views/users/create.php:39`
```
- <a href="/users">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/users">Cancel</button>
```

`views/warehouses/create.php:28`
```
- <a href="/warehouses">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/warehouses">Cancel</button>
```

`views/categories/edit.php:30`
```
- <a href="/categories">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/categories">Cancel</button>
```

`views/customers/edit.php:32`
```
- <a href="/customers">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/customers">Cancel</button>
```

`views/products/edit.php:44`
```
- <a href="/products">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/products">Cancel</button>
```

`views/suppliers/edit.php:32`
```
- <a href="/suppliers">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/suppliers">Cancel</button>
```

`views/users/edit.php:44`
```
- <a href="/users">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/users">Cancel</button>
```

`views/warehouses/edit.php:30`
```
- <a href="/warehouses">Cancel</a>
+ <button type="button" class="button" data-cancel-href="/warehouses">Cancel</button>
```

When making each edit, preserve the original line's leading indentation exactly (12 spaces for the eight `create.php` files, 16 spaces for the six `edit.php` files) — only the tag and attributes change, not the whitespace.

- [ ] **Step 3: Syntax-check the changed JS file**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --check public/assets/js/app.js
```

Expected: no output, exit code 0.

- [ ] **Step 4: Confirm no PHP syntax errors in the 14 changed views**

```bash
for f in views/categories/create.php views/customers/create.php views/products/create.php views/purchase-orders/create.php views/sales-orders/create.php views/suppliers/create.php views/users/create.php views/warehouses/create.php views/categories/edit.php views/customers/edit.php views/products/edit.php views/suppliers/edit.php views/users/edit.php views/warehouses/edit.php; do
  docker compose exec -T app php -l "$f"
done
```

Expected: each line prints `No syntax errors detected in <file>`.

- [ ] **Step 5: Confirm no `<a href>` Cancel links remain in any of the 14 files**

```bash
grep -rn 'href="/[a-z-]*">Cancel</a>' views/categories views/customers views/products views/purchase-orders views/sales-orders views/suppliers views/users views/warehouses
```

Expected: no output (exit code 1 / empty match).

- [ ] **Step 6: Rebuild and run the full JS suite**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --test tests/JavaScript/*.test.js
APP_PORT=8081 docker compose up -d --build app
```

Expected: `tests 17`, `pass 17`, `fail 0`.

- [ ] **Step 7: Manual smoke test — the actual bug this task fixes**

```bash
cd /tmp
rm -f cookies.txt
CSRF=$(curl -s -c cookies.txt http://localhost:8081/login | grep -oE 'name="csrf_token" value="[A-Za-z0-9._-]+"' | sed -E 's/.*value="([^"]+)"/\1/')
curl -s -b cookies.txt -c cookies.txt -o /dev/null -w "login -> %{http_code}\n" \
  -X POST http://localhost:8081/login \
  --data-urlencode "email=admin@example.test" \
  --data-urlencode "password=password" \
  --data-urlencode "csrf_token=$CSRF"
curl -s -b cookies.txt http://localhost:8081/products/create | grep -o 'data-cancel-href="[^"]*"'
```

Expected: last command prints `data-cancel-href="/products"`, confirming the server now renders the button form, not the old anchor.

Then in a browser: log in, go to `/products`, click "Create Product" (opens modal), click "Cancel" inside the modal — the modal should close (with the Task 3 transition) and the URL must stay `/products` (no navigation, no full-page reload). Separately, visit `/products/create` directly (not through the modal) and click "Cancel" there — it must navigate to `/products` as before.

- [ ] **Step 8: Commit**

```bash
git add public/assets/js/app.js views/categories/create.php views/customers/create.php views/products/create.php views/purchase-orders/create.php views/sales-orders/create.php views/suppliers/create.php views/users/create.php views/warehouses/create.php views/categories/edit.php views/customers/edit.php views/products/edit.php views/suppliers/edit.php views/users/edit.php views/warehouses/edit.php
git commit -m "fix: cancel buttons close the modal instead of navigating when inside one"
```

---

