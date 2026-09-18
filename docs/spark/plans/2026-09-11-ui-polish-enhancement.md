# UI Polish Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use spark:subagent-driven-development (recommended) or spark:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand the CSS token system for consistency, add a fade/scale transition to the modal and confirm dialogs, and fix 14 "Cancel" links that are structurally `<a href>` elements but function as modal actions.

**Architecture:** Pure presentation-layer change on top of the existing custom CSS + Vanilla JS stack (no framework, no build step). Token additions live in the existing `:root` block in `public/assets/js/../css/app.css`; the modal transition is CSS + a small `modal.js` change using a shared open/close helper; the Cancel-button fix adds one pure decision function to `ui-helpers.js` (unit-tested), one delegated listener in `app.js`, and a markup change in 14 PHP view files.

**Tech Stack:** PHP 8.2+ views (no template engine), vanilla CSS custom properties, vanilla JavaScript (`node:test` for the one unit-testable piece), Docker Compose for the running app (image is built, not volume-mounted — changes require a rebuild to be visible at `http://localhost:8081`).

## Global Constraints

- No new frontend dependency, framework, or build step (`AGENTS.md` constraint; also `.docs/PROJECT_PROFILE.md` confirms Vanilla JS only).
- `prefers-reduced-motion: reduce` must collapse the new transitions to near-zero duration, matching the existing project convention noted in `docs/quality/ai-insight-html-css.md`.
- No change to layout structure (sidebar, toolbar, dashboard grid) or to the core color hues (`--primary`, `--accent`, semantic status colors) — approved design explicitly excludes this.
- The running container (`tugasakhir-app-1`) builds the app image at `docker compose build` time; it does **not** bind-mount `public/` or `views/`. Every task that changes CSS/JS/PHP view files must rebuild with `APP_PORT=8081 docker compose up -d --build app` before it can be verified over HTTP.
- Demo login for manual verification: `admin@example.test` / `password` (from `README.md`, already verified working this session).
- **Line numbers drift after Task 1:** every `app.css` line number cited in Tasks 2 and 3 reflects the file *before* Task 1's edit. Task 1 inserts ~12 lines into `:root`, so every line after it shifts down by that amount. The verbatim "current" code blocks shown in each step are the authoritative anchor for locating the right selector — search for that text, don't trust the cited line number once Task 1 is done. This note exists so a fresh implementer isn't confused when `app.css:213` no longer points at `.button` after Task 1.
- **Plan-time correction to the approved design:** the design doc (`docs/spark/specs/2026-09-11-ui-polish-enhancement-design.md`) used illustrative radius values (`--radius-sm: 6px`, `--radius-md: 10px`, `--radius-lg: 16px`). Grepping `app.css` during planning found the *actual* radii already in use are `8px` (buttons), `10px` (metric cards, tables), `12px` (modal/confirm panels), and `999px` (badges, loading pill) — not 6/10/16. Task 1 below tokenizes the real values instead, since the goal is consistency with what already exists, not introducing new numbers nothing currently matches. This does not change the approved scope (a tiered radius system replacing ad hoc literals) — only the specific pixel values, which were never meant to be pinned exactly at brainstorming time.

---

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

### Task 3: Modal and confirm dialog open/close transition

**Files:**
- Modify: `public/assets/css/app.css:867-984` (backdrop, panel, and a new reduced-motion block)
- Modify: `public/assets/js/modal.js` (add two shared helpers; update `openFormModal`, `closeModal`, `askConfirmation`)

**Interfaces:**
- Produces (in `modal.js`, module-private, not exported — no change to `InventoryModal.create()`'s returned public API): `openWithTransition(backdrop)`, `closeWithTransition(backdrop, cleanup)`.

- [ ] **Step 1: CSS — backdrop opacity transition**

`app.css:867-876`, current:

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

Leave this block as-is, and insert a new block immediately after it (before the existing `.page-loading { ... }` block that starts at line 878):

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

- [ ] **Step 2: CSS — panel scale/opacity transition**

`app.css:921-930` (already edited in Task 2 Step 6 to use `var(--radius-lg)`), add `transform`, `opacity`, and `transition`:

```css
.modal-panel,
.confirm-panel {
    width: min(40rem, calc(100% - 2rem));
    max-height: min(86vh, 52rem);
    overflow: auto;
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    background: #ffffff;
    box-shadow: var(--shadow);
    transform: scale(0.96);
    opacity: 0;
    transition: transform 0.18s ease, opacity 0.18s ease;
}

.is-open .modal-panel,
.is-open .confirm-panel {
    transform: scale(1);
    opacity: 1;
}
```

- [ ] **Step 3: CSS — respect reduced motion**

Insert this new block right before `@keyframes spin {` (currently at `app.css:986`):

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

- [ ] **Step 4: JS — add shared open/close helpers to `modal.js`**

In `public/assets/js/modal.js`, insert these two functions right after `setPageLoading` (currently ends at line 35, before `function createModal() {` at line 37):

```js
            function openWithTransition(backdrop) {
                backdrop.hidden = false;
                requestAnimationFrame(() => backdrop.classList.add('is-open'));
            }

            function closeWithTransition(backdrop, cleanup) {
                if (!backdrop.classList.contains('is-open')) {
                    cleanup();
                    return;
                }
                backdrop.classList.remove('is-open');
                // 220ms = the 180ms CSS transition above plus a small safety margin,
                // in case transitionend never fires (e.g. the element was removed).
                const timeoutId = setTimeout(cleanup, 220);
                backdrop.addEventListener('transitionend', function onEnd(event) {
                    if (event.target !== backdrop) {
                        return;
                    }
                    clearTimeout(timeoutId);
                    backdrop.removeEventListener('transitionend', onEnd);
                    cleanup();
                });
            }

```

- [ ] **Step 5: JS — use `openWithTransition` in `openFormModal`**

`modal.js:105-119`, current:

```js
            async function openFormModal(href) {
                const modal = document.querySelector('.modal-backdrop');
                const body = modal?.querySelector('.modal-body');
                const title = modal?.querySelector('#modal-title');
                if (!modal || !body || !title) {
                    location.href = href;
                    return;
                }

                state.modalTrigger = document.activeElement;
                modal.hidden = false;
                document.body.classList.add('has-modal');
                modal.setAttribute('aria-busy', 'true');
                body.innerHTML = '<div class="loading-card"><span></span><strong>Loading form</strong></div>';
                modal.querySelector('.modal-panel')?.focus();
```

Change the `modal.hidden = false;` line only:

```js
                state.modalTrigger = document.activeElement;
                openWithTransition(modal);
                document.body.classList.add('has-modal');
                modal.setAttribute('aria-busy', 'true');
                body.innerHTML = '<div class="loading-card"><span></span><strong>Loading form</strong></div>';
                modal.querySelector('.modal-panel')?.focus();
```

- [ ] **Step 6: JS — use `closeWithTransition` in `closeModal`**

`modal.js:157-174`, current:

```js
            function closeModal() {
                requestCoordinator.cancel();
                state.activeFormRequest = null;
                const modal = document.querySelector('.modal-backdrop');
                if (!modal) {
                    return;
                }
                modal.hidden = true;
                document.body.classList.remove('has-modal');
                const body = modal.querySelector('.modal-body');
                if (body) {
                    body.innerHTML = '';
                }
                if (state.modalTrigger && typeof state.modalTrigger.focus === 'function') {
                    state.modalTrigger.focus();
                }
                state.modalTrigger = null;
            }
```

Replace with:

```js
            function closeModal() {
                requestCoordinator.cancel();
                state.activeFormRequest = null;
                const modal = document.querySelector('.modal-backdrop');
                if (!modal) {
                    return;
                }
                closeWithTransition(modal, () => {
                    modal.hidden = true;
                    document.body.classList.remove('has-modal');
                    const body = modal.querySelector('.modal-body');
                    if (body) {
                        body.innerHTML = '';
                    }
                    if (state.modalTrigger && typeof state.modalTrigger.focus === 'function') {
                        state.modalTrigger.focus();
                    }
                    state.modalTrigger = null;
                });
            }
```

- [ ] **Step 7: JS — use both helpers in `askConfirmation`**

`modal.js:176-199`, current:

```js
            function askConfirmation(message) {
                const dialog = document.querySelector('.confirm-backdrop');
                if (!dialog) {
                    return Promise.resolve(window.confirm(message));
                }
                dialog.querySelector('.confirm-message').textContent = message;
                dialog.hidden = false;

                return new Promise((resolve) => {
                    const cancel = dialog.querySelector('.confirm-cancel');
                    const submit = dialog.querySelector('.confirm-submit');
                    const finish = (answer) => {
                        dialog.hidden = true;
                        cancel.removeEventListener('click', onCancel);
                        submit.removeEventListener('click', onSubmit);
                        resolve(answer);
                    };
                    const onCancel = () => finish(false);
                    const onSubmit = () => finish(true);
                    cancel.addEventListener('click', onCancel);
                    submit.addEventListener('click', onSubmit);
                    submit.focus();
                });
            }
```

Replace with:

```js
            function askConfirmation(message) {
                const dialog = document.querySelector('.confirm-backdrop');
                if (!dialog) {
                    return Promise.resolve(window.confirm(message));
                }
                dialog.querySelector('.confirm-message').textContent = message;
                openWithTransition(dialog);

                return new Promise((resolve) => {
                    const cancel = dialog.querySelector('.confirm-cancel');
                    const submit = dialog.querySelector('.confirm-submit');
                    const finish = (answer) => {
                        closeWithTransition(dialog, () => {
                            dialog.hidden = true;
                        });
                        cancel.removeEventListener('click', onCancel);
                        submit.removeEventListener('click', onSubmit);
                        resolve(answer);
                    };
                    const onCancel = () => finish(false);
                    const onSubmit = () => finish(true);
                    cancel.addEventListener('click', onCancel);
                    submit.addEventListener('click', onSubmit);
                    submit.focus();
                });
            }
```

- [ ] **Step 8: Syntax-check the changed JS file**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --check public/assets/js/modal.js
```

Expected: no output, exit code 0.

- [ ] **Step 9: Run the existing JS test suite (regression guard — `modal.js` itself has no existing tests, but this confirms the change didn't break sibling modules)**

```bash
node --test tests/JavaScript/*.test.js
```

Expected: `pass 15`, `fail 0` (same count as before this task — `modal.js` is not under test, so the count should not change).

- [ ] **Step 10: Rebuild and manual smoke test**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
APP_PORT=8081 docker compose up -d --build app
```

Then in a browser: log in, go to `/products`, click "Create Product" — the modal should visibly fade and scale in (not pop instantly). Click the "Close" (✕) button — it should fade and scale out before disappearing. Repeat for a state-changing action that triggers `askConfirmation` (e.g. deactivating a user at `/users`, if the logged-in demo admin has one to act on) to confirm the confirm dialog also transitions.

- [ ] **Step 11: Commit**

```bash
git add public/assets/css/app.css public/assets/js/modal.js
git commit -m "feat: add fade/scale transition to modal and confirm dialogs"
```

---

### Task 4: `resolveCancelTarget` pure function (TDD)

**Files:**
- Modify: `public/assets/js/ui-helpers.js`
- Test: `tests/JavaScript/ui-helpers.test.js`

**Interfaces:**
- Produces: `InventoryUi.resolveCancelTarget(cancelHref: string, isInsideModal: boolean): {action: 'close-modal'} | {action: 'navigate', href: string}`, consumed by Task 5.

- [ ] **Step 1: Write the failing test**

Append to `tests/JavaScript/ui-helpers.test.js` (after the existing `debounce` test, before the final `});` of the file — the file currently ends at line 55 with the closing of the debounce test, so add after that closing `});` at line 55):

```js

test('resolveCancelTarget closes the modal when the cancel button is inside one', () => {
    assert.deepEqual(UiHelpers.resolveCancelTarget('/products', true), {action: 'close-modal'});
});

test('resolveCancelTarget navigates to the cancel href when not inside a modal', () => {
    assert.deepEqual(UiHelpers.resolveCancelTarget('/products', false), {action: 'navigate', href: '/products'});
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --test tests/JavaScript/ui-helpers.test.js
```

Expected: FAIL — `TypeError: UiHelpers.resolveCancelTarget is not a function` (or similar), because the function does not exist yet.

- [ ] **Step 3: Implement the function**

In `public/assets/js/ui-helpers.js`, add the function after `debounce` (which currently ends at line 61, right before the final `return {compareTableValues, debounce, escapeCsvCell, escapeHtml, matchesFilter, needsConfirmation};` line):

```js
    function resolveCancelTarget(cancelHref, isInsideModal) {
        return isInsideModal ? {action: 'close-modal'} : {action: 'navigate', href: cancelHref};
    }

```

Then update the final `return` statement from:

```js
    return {compareTableValues, debounce, escapeCsvCell, escapeHtml, matchesFilter, needsConfirmation};
```

to:

```js
    return {compareTableValues, debounce, escapeCsvCell, escapeHtml, matchesFilter, needsConfirmation, resolveCancelTarget};
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
node --test tests/JavaScript/ui-helpers.test.js
```

Expected: PASS, `tests 7`, `pass 7`, `fail 0` (5 existing + 2 new).

- [ ] **Step 5: Run the full JS suite to confirm no regression**

```bash
node --test tests/JavaScript/*.test.js
```

Expected: `tests 17`, `pass 17`, `fail 0` (15 existing + 2 new).

- [ ] **Step 6: Commit**

```bash
git add public/assets/js/ui-helpers.js tests/JavaScript/ui-helpers.test.js
git commit -m "feat: add resolveCancelTarget for modal-aware cancel buttons"
```

---

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

### Task 6: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: JavaScript suite**

```bash
cd "/Users/syillaeltaniadaffa/Documents/Neuron/Tugas Akhir"
node --test tests/JavaScript/*.test.js
```

Expected: `tests 17`, `pass 17`, `fail 0`.

- [ ] **Step 2: PHP suite and static analysis**

```bash
docker compose exec -T app composer test
docker compose exec -T app composer analyse
```

Expected: PHPUnit all-green (this plan touched only PHP *view* markup, not `app/`, so the existing test count should be unchanged from the last recorded run — 103 tests/423 assertions per `ai-usage-log.md`'s 2026-09-07 entry, unless later sessions added more); PHPStan level 5, 0 errors.

- [ ] **Step 3: HTTP smoke test across all six master-data create/edit pairs**

```bash
cd /tmp
for feature in categories customers products suppliers users warehouses; do
  code=$(curl -s -o /dev/null -w "%{http_code}" -b cookies.txt "http://localhost:8081/$feature/create")
  echo "$feature/create -> $code"
done
```

Expected: every line prints `200`.

- [ ] **Step 4: Update `ai-usage-log.md`**

Append one row documenting this session's enhancement, following the existing table format in `ai-usage-log.md` (Date | Tool/Agent | Purpose | Human Review | Verification), with `Tool/Agent` = `Claude Code`, and `Verification` listing the exact commands and results from Steps 1–3 above.

- [ ] **Step 5: Final commit**

```bash
git add ai-usage-log.md
git commit -m "docs: log UI polish enhancement in AI usage log"
```
