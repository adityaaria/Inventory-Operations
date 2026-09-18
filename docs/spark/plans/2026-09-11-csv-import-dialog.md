# CSV Import As A Dialog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the always-visible CSV import form on six list pages behind an "Import CSV" toolbar button that opens it as a dialog, matching the existing modal's fade/scale transition.

**Architecture:** A new standalone JS module (`dialog.js`) mirrors `modal.js`'s open/close-transition behavior (including its race-condition guard) for a statically-rendered, non-AJAX dialog. Six view files wrap their existing import `<form>` in that dialog's markup and add a trigger button; the dialog defaults open only when the page has an import error to show, computed server-side from the same `$error` variable each page already uses.

**Tech Stack:** PHP views (no template engine), vanilla CSS custom properties (existing token system), vanilla JavaScript (`node:test` for anything unit-testable — nothing in this plan is, same as `modal.js`).

## Global Constraints

- No AJAX, no change to any Controller/Service/Repository. The import form's `method="post" action="/{feature}/import" enctype="multipart/form-data"` and every field inside it stay byte-for-byte identical to today — only what wraps around them changes.
- `modal.js` is not modified in this plan. `dialog.js` is a new, separate module that deliberately duplicates a small amount of `modal.js`'s open/close logic rather than extracting a shared module out of already-reviewed, already-fixed code (see the design spec's "Why A New Module" section).
- `prefers-reduced-motion: reduce` must collapse `.import-dialog`'s transition to near-zero duration, same as the existing `.modal-backdrop`/`.confirm-backdrop`/`.modal-panel`/`.confirm-panel` rule.
- The `users/index.php` toolbar and import form have no `$canWrite` guard today (confirmed by reading the file — every other page's import form is wrapped in `<?php if ($canWrite): ?> ... <?php endif; ?>`, `users/index.php`'s is not). The new trigger button and dialog on that one page must match that existing unguarded pattern — do not add a guard that isn't there today.
- No git in this repository. Do not run any git command at any step; skip any "commit" step. This was already established and worked around in the prior enhancement (see `.spark/sdd/progress.md`).
- The demo login for manual verification is `admin@example.test` / `password`. The running container (`tugasakhir-app-1`) builds the image at `docker compose build` time — it does not bind-mount source — so every task that changes a PHP/CSS/JS file must rebuild with `APP_PORT=8081 docker compose up -d --build app` before it's visible over HTTP.

---

### Task 1: `dialog.js` module

**Files:**
- Create: `public/assets/js/dialog.js`

**Interfaces:**
- Produces: global `InventoryDialog.enhance(scope = document)` returning `{open(backdrop), close(backdrop)}`, consumed by Task 2.

- [ ] **Step 1: Create the file with this exact content**

```js
'use strict';

(function exposeDialog(root) {
    root.InventoryDialog = {
        enhance(scope = document) {
            function open(backdrop) {
                if (backdrop._pendingCloseCancel) {
                    backdrop._pendingCloseCancel();
                    delete backdrop._pendingCloseCancel;
                }
                wire(backdrop);
                backdrop.hidden = false;
                requestAnimationFrame(() => backdrop.classList.add('is-open'));
                backdrop.querySelector('.modal-panel')?.focus();
            }

            function close(backdrop) {
                if (!backdrop.classList.contains('is-open')) {
                    backdrop.hidden = true;
                    return;
                }
                backdrop.classList.remove('is-open');
                const finish = () => {
                    clearTimeout(timeoutId);
                    backdrop.removeEventListener('transitionend', onEnd);
                    delete backdrop._pendingCloseCancel;
                    backdrop.hidden = true;
                };
                // 220ms = the 180ms CSS transition (app.css) plus a small safety
                // margin, in case transitionend never fires.
                const timeoutId = setTimeout(finish, 220);
                function onEnd(event) {
                    if (event.target !== backdrop) {
                        return;
                    }
                    finish();
                }
                backdrop.addEventListener('transitionend', onEnd);
                backdrop._pendingCloseCancel = () => {
                    clearTimeout(timeoutId);
                    backdrop.removeEventListener('transitionend', onEnd);
                };
            }

            function wire(backdrop) {
                if (backdrop.dataset.dialogWired === 'true') {
                    return;
                }
                backdrop.dataset.dialogWired = 'true';
                backdrop.addEventListener('click', (event) => {
                    if (event.target === backdrop || event.target.closest('.modal-close')) {
                        close(backdrop);
                    }
                });
                document.addEventListener('keydown', (event) => {
                    if (backdrop.hidden) {
                        return;
                    }
                    if (event.key === 'Escape') {
                        close(backdrop);
                        return;
                    }
                    if (event.key === 'Tab') {
                        trapFocus(backdrop, event);
                    }
                });
            }

            function trapFocus(container, event) {
                const focusable = [...container.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')]
                    .filter((element) => !element.disabled && !element.hidden);
                if (focusable.length === 0) {
                    event.preventDefault();
                    return;
                }
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }

            scope.querySelectorAll('[data-dialog-open]').forEach((trigger) => {
                if (trigger.dataset.dialogEnhanced === 'true') {
                    return;
                }
                trigger.dataset.dialogEnhanced = 'true';
                trigger.addEventListener('click', () => {
                    const target = document.getElementById(trigger.dataset.dialogOpen);
                    if (target) {
                        open(target);
                    }
                });
            });

            return {open, close};
        },
    };
})(typeof globalThis === 'object' ? globalThis : this);
```

- [ ] **Step 2: Syntax-check the file**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --check public/assets/js/dialog.js
```

Expected: no output, exit code 0.

- [ ] **Step 3: Confirm the existing suite is unaffected**

```bash
node --test tests/JavaScript/*.test.js
```

Expected: `pass 17, fail 0` (unchanged — nothing imports `dialog.js` yet).

- [ ] **Step 4: Skip commit** — no git in this repository.

---

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

### Task 4: Convert the six import forms into dialogs

**Files:**
- Modify: `views/categories/index.php`
- Modify: `views/customers/index.php`
- Modify: `views/products/index.php`
- Modify: `views/suppliers/index.php`
- Modify: `views/users/index.php`
- Modify: `views/warehouses/index.php`

**Interfaces:**
- Consumes: `.import-dialog` CSS (Task 3), `InventoryDialog.enhance()`'s `[data-dialog-open]` discovery (Task 1/2).

Each of the six edits below has two parts: (a) add a trigger button to the toolbar, (b) wrap the existing import form in dialog markup. The import form's own contents (field names, CSV placeholder text, the `action` URL) are copied verbatim from the current file — do not alter them.

- [ ] **Step 1: `views/categories/index.php`**

Current (lines 27-40):

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/categories/create">Create Category</a><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <form method="post" action="/categories/import" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,description"></textarea></label>
                <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                <button type="submit">Import CSV</button>
            </form>
        <?php endif; ?>
```

Replace with:

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/categories/create">Create Category</a><?php endif; ?>
                <?php if ($canWrite): ?><button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
                <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                    <header class="modal-header">
                        <h2 id="import-dialog-title">Import CSV</h2>
                        <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                    </header>
                    <div class="modal-body">
                        <form method="post" action="/categories/import" enctype="multipart/form-data" class="form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,description"></textarea></label>
                            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                            <button type="submit">Import CSV</button>
                        </form>
                    </div>
                </section>
            </div>
        <?php endif; ?>
```

- [ ] **Step 2: `views/customers/index.php`**

Current (lines 27-40):

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/customers/create">Create Customer</a><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <form method="post" action="/customers/import" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,email,phone,address"></textarea></label>
                <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                <button type="submit">Import CSV</button>
            </form>
        <?php endif; ?>
```

Replace with:

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/customers/create">Create Customer</a><?php endif; ?>
                <?php if ($canWrite): ?><button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
                <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                    <header class="modal-header">
                        <h2 id="import-dialog-title">Import CSV</h2>
                        <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                    </header>
                    <div class="modal-body">
                        <form method="post" action="/customers/import" enctype="multipart/form-data" class="form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,email,phone,address"></textarea></label>
                            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                            <button type="submit">Import CSV</button>
                        </form>
                    </div>
                </section>
            </div>
        <?php endif; ?>
```

- [ ] **Step 3: `views/products/index.php`**

This file's import form comes after a `<form method="get" action="/products" class="filters">` block that must NOT be touched. Current (lines 27-31 for the toolbar, and lines 50-57 for the import block):

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a href="/products/create">Create Product</a><?php endif; ?>
            </nav>
```

Replace with:

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a href="/products/create">Create Product</a><?php endif; ?>
                <?php if ($canWrite): ?><button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button><?php endif; ?>
            </nav>
```

Then, further down, current:

```php
        <?php if ($canWrite): ?>
            <form method="post" action="/products/import" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <label>Import CSV <textarea name="csv_data" rows="3" placeholder="sku,name,unit,purchase_price,selling_price,reorder_point,category_id"></textarea></label>
                <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                <button type="submit">Import CSV</button>
            </form>
        <?php endif; ?>
```

Replace with:

```php
        <?php if ($canWrite): ?>
            <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
                <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                    <header class="modal-header">
                        <h2 id="import-dialog-title">Import CSV</h2>
                        <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                    </header>
                    <div class="modal-body">
                        <form method="post" action="/products/import" enctype="multipart/form-data" class="form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="sku,name,unit,purchase_price,selling_price,reorder_point,category_id"></textarea></label>
                            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                            <button type="submit">Import CSV</button>
                        </form>
                    </div>
                </section>
            </div>
        <?php endif; ?>
```

- [ ] **Step 4: `views/suppliers/index.php`**

Current (lines 27-40):

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/suppliers/create">Create Supplier</a><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <form method="post" action="/suppliers/import" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,email,phone,address"></textarea></label>
                <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                <button type="submit">Import CSV</button>
            </form>
        <?php endif; ?>
```

Replace with:

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/suppliers/create">Create Supplier</a><?php endif; ?>
                <?php if ($canWrite): ?><button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
                <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                    <header class="modal-header">
                        <h2 id="import-dialog-title">Import CSV</h2>
                        <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                    </header>
                    <div class="modal-body">
                        <form method="post" action="/suppliers/import" enctype="multipart/form-data" class="form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,email,phone,address"></textarea></label>
                            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                            <button type="submit">Import CSV</button>
                        </form>
                    </div>
                </section>
            </div>
        <?php endif; ?>
```

- [ ] **Step 5: `views/warehouses/index.php`**

Current (lines 27-40):

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/warehouses/create">Create Warehouse</a><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <form method="post" action="/warehouses/import" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,location"></textarea></label>
                <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                <button type="submit">Import CSV</button>
            </form>
        <?php endif; ?>
```

Replace with:

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a class="button-primary" href="/warehouses/create">Create Warehouse</a><?php endif; ?>
                <?php if ($canWrite): ?><button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($canWrite): ?>
            <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
                <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                    <header class="modal-header">
                        <h2 id="import-dialog-title">Import CSV</h2>
                        <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                    </header>
                    <div class="modal-body">
                        <form method="post" action="/warehouses/import" enctype="multipart/form-data" class="form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,location"></textarea></label>
                            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                            <button type="submit">Import CSV</button>
                        </form>
                    </div>
                </section>
            </div>
        <?php endif; ?>
```

- [ ] **Step 6: `views/users/index.php`**

This page has **no `$canWrite` guard** on its toolbar or import form today — do not add one. Current (lines 27-42):

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <a class="button-primary" href="/users/create">Create User</a>
                <form method="post" action="/logout">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Logout</button>
                </form>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post" action="/users/import" enctype="multipart/form-data" class="form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,email,password,role"></textarea></label>
            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
            <button type="submit">Import CSV</button>
        </form>
```

Replace with:

```php
            <nav class="toolbar">
                <a href="/">Home</a>
                <a class="button-primary" href="/users/create">Create User</a>
                <button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button>
                <form method="post" action="/logout">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit">Logout</button>
                </form>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
            <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                <header class="modal-header">
                    <h2 id="import-dialog-title">Import CSV</h2>
                    <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                </header>
                <div class="modal-body">
                    <form method="post" action="/users/import" enctype="multipart/form-data" class="form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <label>Import CSV <textarea name="csv_data" rows="3" placeholder="name,email,password,role"></textarea></label>
                        <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                        <button type="submit">Import CSV</button>
                    </form>
                </div>
            </section>
        </div>
```

- [ ] **Step 7: PHP-lint all six changed files**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
for f in views/categories/index.php views/customers/index.php views/products/index.php views/suppliers/index.php views/warehouses/index.php views/users/index.php; do
  docker compose exec -T app php -l "$f"
done
```

Expected: each line prints `No syntax errors detected in <file>`.

- [ ] **Step 8: Confirm no bare `<form method="post" action="/{feature}/import"` remains outside the new dialog wrapper, and no import form was accidentally duplicated or dropped**

```bash
grep -c 'action="/categories/import"' views/categories/index.php
grep -c 'action="/customers/import"' views/customers/index.php
grep -c 'action="/products/import"' views/products/index.php
grep -c 'action="/suppliers/import"' views/suppliers/index.php
grep -c 'action="/warehouses/import"' views/warehouses/index.php
grep -c 'action="/users/import"' views/users/index.php
```

Expected: every command prints `1` (the form still exists exactly once per file, now inside `.modal-body`).

- [ ] **Step 9: Rebuild and run the manual smoke test from the design spec**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
APP_PORT=8081 docker compose up -d --build app
```

Then, via curl (log in first the same way as the prior enhancement: scrape `csrf_token` from `GET /login`, `POST` credentials `admin@example.test`/`password`):

```bash
cd /tmp
curl -s -b cookies.txt http://localhost:8081/products | grep -o 'data-dialog-open="import-dialog"\|id="import-dialog"[^>]*hidden' 
```

Expected: `data-dialog-open="import-dialog"` is present, and `id="import-dialog"` is followed by `hidden` (the dialog defaults closed when there's no error — confirm this by checking `$error` is empty on a normal `GET /products` with no prior failed import in this session).

Then, in a real browser (this environment has no browser — a human must do this step): log in, visit `/products`, click "Import CSV" — the dialog should open with the same fade/scale transition as the Create/Edit modal. Click "Close" — it should close the same way. Submit the import form with an empty textarea and no file to trigger the controller's validation error, and confirm the dialog is already open on the reloaded page (not requiring another click).

- [ ] **Step 10: Skip commit** — no git in this repository.

---

### Task 5: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: JavaScript suite**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --test tests/JavaScript/*.test.js
```

Expected: `pass 17, fail 0` (unchanged — `dialog.js` has no unit tests, same as `modal.js`).

- [ ] **Step 2: PHP suite and static analysis**

```bash
docker compose exec -T app composer test
docker compose exec -T app composer analyse
```

Expected: PHPUnit all-green (103 tests/423 assertions per the last recorded run, unless a later session changed that count); PHPStan level 5, 0 errors.

- [ ] **Step 3: HTTP smoke test across all six pages that now have an import dialog**

```bash
cd /tmp
for feature in categories customers products suppliers users warehouses; do
  code=$(curl -s -o /dev/null -w "%{http_code}" -b cookies.txt "http://localhost:8081/$feature")
  echo "$feature -> $code"
done
```

Expected: every line prints `200`.

- [ ] **Step 4: Update `ai-usage-log.md`**

Append one row (Date | Tool/Agent | Purpose | Human Review | Verification format, matching every existing row) with `Tool/Agent` = `Claude Code`, summarizing this enhancement (import CSV moved from an always-visible inline form to a dialog behind an "Import CSV" button on six list pages, opening automatically when there's an import error), and listing the real Step 1-3 results.

- [ ] **Step 5: Skip commit** — no git in this repository.
