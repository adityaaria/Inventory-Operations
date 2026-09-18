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

