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

