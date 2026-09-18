# Task 4 Report: Convert the six import forms into dialogs

## Summary

Applied Steps 1-9 of the brief to all six view files. Each file's inline "Current"
block was verified by reading the actual file content first (not trusting cited
line numbers), confirmed to match the brief verbatim, then replaced with the
"Replace with" block exactly as specified. No file content deviated from the
brief's expectations, so no BLOCKED condition was hit.

## Per-file changes

### 1. `views/categories/index.php`
- Added `<?php if ($canWrite): ?><button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button><?php endif; ?>` to the toolbar `<nav>`.
- Wrapped the existing `<form method="post" action="/categories/import" ...>` (placeholder `name,description`) inside `<div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>` → `<section class="modal-panel" role="dialog" ...>` → `<div class="modal-body">`, both still gated by the pre-existing `<?php if ($canWrite): ?> ... <?php endif; ?>`.

### 2. `views/customers/index.php`
- Same pattern as categories. Toolbar button added inside `$canWrite` guard. Form (placeholder `name,email,phone,address`, action `/customers/import`) wrapped in the dialog markup, guard preserved.

### 3. `views/products/index.php`
- Two separate edits, as specified:
  - Toolbar: added the Import CSV trigger button (guarded by `$canWrite`) after the existing "Create Product" link, inside the same `<nav class="toolbar">`.
  - Further down, the import form (after the untouched `<form method="get" action="/products" class="filters">` search/filter form) — placeholder `sku,name,unit,purchase_price,selling_price,reorder_point,category_id`, action `/products/import` — wrapped in the dialog markup, guard preserved. The filters form was left completely untouched.

### 4. `views/suppliers/index.php`
- Same pattern. Form placeholder `name,email,phone,address`, action `/suppliers/import`.

### 5. `views/warehouses/index.php`
- Same pattern. Form placeholder `name,location`, action `/warehouses/import`.

### 6. `views/users/index.php`
- **No `$canWrite` guard added**, matching this file's pre-existing unguarded pattern (its toolbar and import form had no guard before this change either).
- Toolbar: added `<button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button>` between "Create User" link and the logout `<form>`, unguarded.
- Import form (placeholder `name,email,password,role`, action `/users/import`) wrapped in the same dialog markup (`.import-dialog` / `.modal-panel` / `.modal-header` / `.modal-body`), unguarded, using the same `<?= $error !== '' ? '' : 'hidden' ?>` hidden-attribute logic as the other five files.

## Step 7: PHP lint (all 6 files)

```
No syntax errors detected in views/categories/index.php
No syntax errors detected in views/customers/index.php
No syntax errors detected in views/products/index.php
No syntax errors detected in views/suppliers/index.php
No syntax errors detected in views/warehouses/index.php
No syntax errors detected in views/users/index.php
```

All 6 pass.

## Step 8: grep verification (form appears exactly once per file)

```
$ grep -c 'action="/categories/import"' views/categories/index.php  -> 1
$ grep -c 'action="/customers/import"' views/customers/index.php    -> 1
$ grep -c 'action="/products/import"' views/products/index.php      -> 1
$ grep -c 'action="/suppliers/import"' views/suppliers/index.php    -> 1
$ grep -c 'action="/warehouses/import"' views/warehouses/index.php  -> 1
$ grep -c 'action="/users/import"' views/users/index.php            -> 1
```

All 6 print `1` — no forms duplicated or dropped.

## Step 9: Rebuild + curl check

- `APP_PORT=8081 docker compose up -d --build app` — build succeeded, `tugasakhir-app-1` recreated and started, `tugasakhir-db-1` healthy.
- Logged in via curl: scraped `csrf_token` from `GET /login`, then `POST /login` with `admin@example.test` / `password` — received `HTTP/1.1 302 Found` (successful login, session cookie saved).
- `curl -s -b cookies.txt http://localhost:8081/products | grep -o 'data-dialog-open="import-dialog"\|id="import-dialog"[^>]*hidden'` returned:
  ```
  data-dialog-open="import-dialog"
  id="import-dialog" hidden
  ```
  Both expected substrings present — the trigger button exists and the dialog defaults to `hidden` on a normal `GET /products` with no prior import error in this session ($error is empty).

**Browser click-through (fade/scale open, Close button, error-reopen behavior) was NOT performed — this environment has no browser. A human must verify this part manually per Step 9's instructions.**

## Self-review

- Every one of the 6 files has exactly one "Import CSV" trigger button in its toolbar (verified via the toolbar edits above) and exactly one `.import-dialog` wrapper around its single, non-duplicated import form (confirmed by the Step 8 grep counts of `1` each).
- `views/users/index.php` ended up WITHOUT a `$canWrite` guard around its new button/dialog, matching its pre-existing unguarded pattern; the other 5 files (`categories`, `customers`, `products`, `suppliers`, `warehouses`) kept their existing `<?php if ($canWrite): ?> ... <?php endif; ?>` guards around both the new trigger button and the new dialog wrapper.
- Every CSV placeholder string was preserved exactly as it existed before the edit (copied verbatim from the file content read prior to editing, not retyped from memory):
  - categories: `name,description`
  - customers: `name,email,phone,address`
  - products: `sku,name,unit,purchase_price,selling_price,reorder_point,category_id`
  - suppliers: `name,email,phone,address`
  - warehouses: `name,location`
  - users: `name,email,password,role`
- Every dialog uses `<?= $error !== '' ? '' : 'hidden' ?>` for its `hidden` attribute, so the dialog auto-opens when there's an import error to display.
- `id="import-dialog"` on the dialog wrapper and `data-dialog-open="import-dialog"` on the trigger button are consistently paired in all 6 files.
- No other files were touched (`dialog.js`, `app.js`, `app.css` were already done in Tasks 1-3 and were not modified here). The `products/index.php` filters `<form method="get" action="/products" class="filters">` was left untouched, as required.

## Outstanding

- Live browser click-through (open/close transition parity with Create/Edit modal, and confirming the dialog reopens automatically after a validation error round-trip) requires a human with a real browser — not verifiable from this non-browser environment.
