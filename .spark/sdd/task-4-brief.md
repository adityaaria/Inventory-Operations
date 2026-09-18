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

