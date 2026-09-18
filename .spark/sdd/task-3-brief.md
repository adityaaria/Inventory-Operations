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

