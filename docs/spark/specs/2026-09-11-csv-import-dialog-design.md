# CSV Import As A Dialog

Date: 2026-09-11
Status: Approved by user
Owner: Frontend (PHP views + Vanilla JS)

## Purpose

The CSV import form on six list pages (Products, Categories, Warehouses, Suppliers, Users, Customers) is currently always rendered inline on the page, between the filters/header and the data table, with no trigger button. This makes those pages more cluttered than they need to be. This change adds an "Import CSV" button to each page's toolbar that opens the existing import form inside a dialog, matching the visual style (fade+scale transition) already used by the Create/Edit modal and the confirm dialog.

No backend change: the import form still POSTs synchronously to `/{feature}/import` exactly as it does today. No controller, service, or repository is touched.

## Scope Boundaries

**In scope:**
- A new generic dialog module, `public/assets/js/dialog.js`, that can open/close any backdrop element with the same fade+scale transition, focus trap, `Escape`-to-close, and backdrop-click-to-close behavior already used by `modal.js` — but as a standalone, statically-rendered dialog (not the AJAX-populated singleton `modal.js` manages).
- Markup changes to 6 view files: `views/{categories,customers,products,suppliers,users,warehouses}/index.php` — wrap the existing import `<form>` in dialog markup, add a trigger button to the toolbar.
- One new CSS rule group in `app.css` for `.import-dialog` (the backdrop-level properties only — the panel styling is reused as-is from `.modal-panel`/`.modal-header`/`.modal-body`, zero new panel CSS needed).
- Adding the `dialog.js` `<script>` tag to all 28 view files, in the same position for every file, following the project's existing convention of loading the full script bundle everywhere regardless of whether a given page uses every module.
- Default open/closed state driven server-side: the dialog renders already-open (no `hidden` attribute) when the page has an import error to show; closed otherwise.

**Out of scope (explicitly not touched):**
- `modal.js` itself is not modified. It was reviewed, and its `openWithTransition`/`closeWithTransition`/race-condition-fix internals are already correct and load-bearing for the Create/Edit modal and confirm dialog. `dialog.js` duplicates a small amount of open/close-transition logic rather than extracting a shared module out of `modal.js` — deliberately, to avoid re-touching already-reviewed, already-fixed code for an unrelated feature. (See "Why a new module, not a shared refactor" below.)
- No AJAX. The import form keeps its existing synchronous `POST` to `/{feature}/import` with a full-page reload/redirect on success, exactly as today.
- No change to `CsvImport`, any Controller's `import()` method, or any test currently covering import business logic.
- No Cancel button is added inside the dialog — the existing header Close (✕) button, `Escape`, and backdrop click are the only dismiss affordances, matching how the confirm dialog already behaves (no separate Cancel button distinct from its close mechanisms beyond the two action buttons it already has for its own purpose).

## Why A New Module, Not A Shared Refactor

`modal.js`'s `.modal-backdrop` is a *singleton*: exactly one instance exists per page, created dynamically by `ensureOverlays()`, and its content is replaced via AJAX for whichever create/edit form the user opens. The import dialog is different in every one of those respects: it's server-rendered once, already contains its final content, and there's exactly one per page (no AJAX swap). Reusing `.modal-backdrop` as the class name would make `modal.js`'s `document.querySelector('.modal-backdrop')` calls ambiguous (which one does it mean?) the moment both exist on the same page — which they will, since `ensureOverlays()` still runs on every page to inject the AJAX modal shell. Giving the import dialog its own class name (`.import-dialog`) avoids that collision entirely, at the cost of a small, deliberate amount of duplicated open/close logic in a new file instead of a deeper refactor of `modal.js`'s already-reviewed internals.

## Architecture & Components

### 1. `public/assets/js/dialog.js` (new)

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

This is a deliberate near-mirror of `modal.js`'s `createModal()`/`trapFocus()`/`openWithTransition()`/`closeWithTransition()` (including the same `_pendingCloseCancel` race-condition guard that was added to `modal.js` in the prior enhancement) — same behavior contract, applied generically to any backdrop element passed to `open()`, discovered via `[data-dialog-open]` triggers rather than a single hardcoded selector.

### 2. `app.js` (modified)

Add one line to the `DOMContentLoaded` handler and nothing else:

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

### 3. `app.css` (modified)

Add `.import-dialog` alongside the existing backdrop selectors — three edits, each adding one selector to an existing rule:

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

And the reduced-motion block:

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

No new rule is needed for the panel itself — the import dialog's inner panel reuses the existing `.modal-panel`, `.modal-header`, and `.modal-body` classes verbatim, which already have the fade+scale transition, radius, shadow, and spacing tokens from the prior enhancement.

### 4. View files (6 modified: `categories`, `customers`, `products`, `suppliers`, `users`, `warehouses`)

Toolbar: add a trigger button next to the existing "Create X" link (same `if ($canWrite)` guard the surrounding code already uses per file — `users/index.php` has no such guard today and none is added, matching its existing unguarded pattern):

```php
<button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button>
```

Import form: wrap the existing form (unchanged internals — field names, CSV column placeholders, and the `/{feature}/import` action all stay exactly as they are today) in dialog markup:

```php
<div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
    <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
        <header class="modal-header">
            <h2 id="import-dialog-title">Import CSV</h2>
            <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
        </header>
        <div class="modal-body">
            <!-- existing <form method="post" action="/{feature}/import" ...>...</form>, byte-for-byte unchanged -->
        </div>
    </section>
</div>
```

The `hidden` attribute is computed from the same `$error` variable the page already uses for its top-of-page alert — no new controller output is required. Every one of the six controllers' `import()` methods already re-renders its index view with `'error' => $exception->getMessage()` on failure (confirmed by reading `ProductController::import()`; the other five follow the same pattern established in the same 2026-09-07 session).

### 5. Script include (28 view files)

Add `<script defer src="/assets/js/dialog.js"></script>` to the existing script block, immediately after `modal.js` and before `charts.js` (matching the existing fixed order used identically across all 28 view files today).

## Data Flow

Unchanged from today for the actual import operation: browser submits a normal multipart `POST` to `/{feature}/import`; on success the controller redirects (302) back to the index page; on failure it re-renders the same index view with `error` set, at 422. The only new client-side behavior is *when the form is visible* — everything about what happens when it's submitted is untouched.

## Error Handling

- If `document.getElementById(trigger.dataset.dialogOpen)` finds nothing (e.g. a future page adds a trigger button without its matching dialog), the click handler is a no-op — no error thrown, just nothing opens. This mirrors `modal.js`'s existing defensive `if (!modal || !body || !title)` fallback style.
- `close()`'s `transitionend` fallback timeout (220ms) prevents the dialog from being stuck "closing but still visible" if the transition event never fires, identical to the guard already proven in `modal.js`.
- `open()`'s `_pendingCloseCancel` guard prevents the same close/reopen race condition that was found and fixed in `modal.js` during the prior enhancement, applied here from the start rather than discovered later.

## Testing

- `dialog.js` touches `document` directly (like `modal.js`), so it is not unit-testable under Node without a real DOM — consistent with the project's existing testing strategy for this class of module. Verification is `node --check public/assets/js/dialog.js` plus a manual smoke test.
- No PHP test currently asserts on the *markup* of these six index pages (only on the `import()` controller behavior, which is unchanged), so no existing PHPUnit test is expected to fail; `composer test`/`composer analyse` are run as a regression guard, not because logic changed.
- Manual smoke test (to be run against the live app in a real browser, since this environment has no browser — same limitation noted in the prior enhancement):
  1. Visit `/products` as an authenticated Admin. Click "Import CSV" in the toolbar. The dialog should fade+scale in, matching the Create/Edit modal's visual style.
  2. Click the "Close" (✕) button. The dialog should fade+scale out and disappear; the page underneath (filters, table) must be unaffected.
  3. Submit an intentionally invalid CSV (e.g. empty `csv_data` and no file) to trigger the controller's error path. After the page reloads, the dialog must already be open (not requiring another click on "Import CSV"), showing the error banner above it.
  4. Repeat step 1 on at least one other page (e.g. `/users`, which has no `$canWrite` guard) to confirm the pattern generalizes.
  5. Toggle OS-level "reduce motion" and confirm the dialog still opens/closes correctly, just without the animated transition.

## Rollout Note

Single cohesive frontend change (one new JS module, one CSS extension, six near-identical view edits, one repeated script-tag addition). Not split into parallel sub-tasks — the six view edits are mechanical repeats of the same pattern and easiest to keep coherent as one implementation plan, consistent with how the prior UI-polish enhancement was structured.
