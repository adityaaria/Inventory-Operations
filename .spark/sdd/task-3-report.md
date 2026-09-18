# Task 3 Report: Modal and confirm dialog open/close transition

## What was implemented

### CSS (`public/assets/css/app.css`)

**Step 1 — backdrop opacity transition** (inserted after the `.page-loading, .modal-backdrop, .confirm-backdrop` shared block, before `.page-loading { ... }`):

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

**Step 2 — panel scale/opacity transition** (added to the existing `.modal-panel, .confirm-panel` rule, which already used `border-radius: var(--radius-lg)` from Task 2):

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

**Step 3 — reduced motion** (inserted immediately before `@keyframes spin`):

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

Actual file locations differed from the brief's cited line numbers (Tasks 1-2 shifted things), so each insertion point was located by searching for the verbatim "current" code rather than trusting line numbers. Final locations: backdrop opacity block after line 888, panel additions inside the rule at ~934-946, reduced-motion block before `@keyframes spin` (now at line ~1026).

### JS (`public/assets/js/modal.js`)

**Step 4 — new module-private helpers**, inserted right after `setPageLoading` and before `createModal`:

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

**Step 5 — `openFormModal`**: replaced `modal.hidden = false;` with `openWithTransition(modal);`.

**Step 6 — `closeModal`**: wrapped the cleanup logic (`modal.hidden = true; ...; state.modalTrigger = null;`) inside `closeWithTransition(modal, () => { ... });`.

**Step 7 — `askConfirmation`**: replaced `dialog.hidden = false;` with `openWithTransition(dialog);`, and wrapped `dialog.hidden = true;` inside `closeWithTransition(dialog, () => { dialog.hidden = true; });` within the `finish` callback.

The public API return statement is unchanged:
```js
return {askConfirmation, closeModal, ensureOverlays, openFormModal, setEnhanceForms, setPageLoading};
```
`openWithTransition` and `closeWithTransition` are module-private, declared inside the `create({state})` closure like every other helper, and not exported.

## Verification run and output

**Step 8 — syntax check:**
```
$ node --check public/assets/js/modal.js
```
No output, exit code 0 (confirmed OK).

**Step 9 — regression test suite:**
```
$ node --test tests/JavaScript/*.test.js
```
```
ℹ tests 15
ℹ suites 0
ℹ pass 15
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
```
Same count as before this task (`modal.js` has no unit tests of its own; this only confirms sibling modules — form-validation.js, http.js, ui-helpers.js — still behave correctly).

**Step 10 — Docker rebuild and smoke test:**
```
$ APP_PORT=8081 docker compose up -d --build app
```
Build succeeded, `tugasakhir-app-1` and `tugasakhir-db-1` both started/healthy.

Programmatic smoke test via curl (no browser available in this environment):
- `GET /login` → scraped CSRF token successfully.
- `POST /login` with `admin@example.test` / `password` + CSRF → `302 Found`, redirected to `/users`, session cookie set. Login succeeded.
- `GET /products` (authenticated) → `HTTP 200`.
- `GET /assets/css/app.css` → confirmed served CSS contains the new selectors: `.modal-backdrop.is-open`, `.confirm-backdrop.is-open`, `.is-open .modal-panel`, `.is-open .confirm-panel`, and the `@media (prefers-reduced-motion: reduce)` block.
- `GET /assets/js/modal.js` → confirmed served JS contains `openWithTransition`, `closeWithTransition`, and their call sites (`openWithTransition(modal)`, `closeWithTransition(modal, ...)`, `openWithTransition(dialog)`, `closeWithTransition(dialog, ...)`).
- Note: `.modal-backdrop`/`.confirm-backdrop` elements are not present in the static `/products` HTML — they are created client-side at runtime by `ensureOverlays()` in `modal.js`/`app.js`, so their absence from server-rendered HTML is expected, not a defect.

**What could NOT be verified from this environment:** the actual visual fade/scale animation. Curl and node can confirm the CSS/JS shipped correctly and the app still functions, but cannot render a browser to observe whether the transition looks smooth, whether timing feels right, or whether the reduced-motion override behaves visually correctly. **A human must open the app in a real browser** (e.g. `http://localhost:8081`, log in as `admin@example.test` / `password`), navigate to `/products`, click "Create Product" and confirm the modal visibly fades/scales in rather than popping instantly, click the close (✕) button and confirm it fades/scales out before disappearing, and repeat for a `askConfirmation` flow (e.g. a state-changing action at `/users`) to confirm the confirm dialog also transitions. Also worth eyeballing with the OS "reduce motion" accessibility setting enabled to confirm the transition duration collapses to near-instant.

## Files changed

- `public/assets/css/app.css`
- `public/assets/js/modal.js`

No git commit was made — this repository is not a git repository (per task instructions, Step 11 was skipped).

## Self-review

- `openFormModal`, `closeModal`, `askConfirmation` each call the right helper at the right point, matching the brief's before/after blocks exactly — verified by diffing against the brief's exact quoted blocks.
- The 220ms `setTimeout` fallback comment is preserved verbatim, explaining the 180ms CSS transition + safety margin rationale.
- `closeWithTransition`'s `transitionend` listener checks `event.target !== backdrop` before proceeding, so it won't fire prematurely from the panel's own `transform`/`opacity` transition (the panel is a child of the backdrop and has its own `transitionend` events that bubble).
- The reduced-motion `@media` block covers all four selectors named in the brief: `.modal-backdrop`, `.confirm-backdrop`, `.modal-panel`, `.confirm-panel`.
- The public API of `InventoryModal.create()` is unchanged: `return {askConfirmation, closeModal, ensureOverlays, openFormModal, setEnhanceForms, setPageLoading};` — untouched, and the two new helpers are declared inside the closure without being added to this return object.

No discrepancies found between the brief's "current" code blocks and what was actually in the files (CSS line numbers were stale as expected and were located by content search instead; JS line numbers matched exactly).

## Issues or concerns

- None on the implementation side. The only outstanding item is the visual/manual browser check noted above, which requires human eyes — a real browser is not available in this execution environment.

## Fix: race condition on rapid close/reopen

### Finding addressed

Reviewer finding: "Rapid close-then-reopen race can hide/wipe a modal that was just reopened." When `closeWithTransition` is invoked (via `closeModal()` or `askConfirmation`'s `finish`), it removes `is-open` and arms a `transitionend` listener plus a 220ms fallback timeout. If the same backdrop is reopened via `openWithTransition` before either fires, nothing previously cancelled the stale listener/timeout. The reopen's own opacity transition then satisfies `event.target === backdrop`, firing the *old* `onEnd`, which ran the *stale* `cleanup` — hiding the modal, wiping `.modal-body`, and refocusing the trigger even though the modal was supposed to be open.

### Fix applied

In `public/assets/js/modal.js`:

- `closeWithTransition(backdrop, cleanup)` now builds a `finish()` closure that clears the timeout, removes the `transitionend` listener, deletes `backdrop._pendingCloseCancel`, and calls `cleanup()`. Both the timeout and the `onEnd` listener call `finish()` instead of `cleanup()` directly. Before arming, it stashes a canceller on the backdrop: `backdrop._pendingCloseCancel = () => { clearTimeout(timeoutId); backdrop.removeEventListener('transitionend', onEnd); }`. Critically, this canceller does **not** call `cleanup()` — it only tears down the pending listener/timeout.
- `openWithTransition(backdrop)` now checks for `backdrop._pendingCloseCancel` first; if present, it invokes it and deletes the property, before setting `backdrop.hidden = false` and scheduling the `is-open` class add on the next animation frame.

This means: any reopen of a backdrop that has an in-flight close always cancels that close's listener/timeout first, so the stale `cleanup` can never fire after a reopen.

### Trace 1 — the race from the finding (close, then reopen before it settles)

1. User clicks close → `closeModal()` runs `requestCoordinator.cancel()`, clears `state.activeFormRequest`, then calls `closeWithTransition(modal, cleanupA)`. This ordering (coordinator/state cleanup happens in `closeModal` before the helper is invoked) is unchanged by the fix.
2. `closeWithTransition` sees `is-open` present, removes it, arms `onEndA`/`timeoutA`, and sets `modal._pendingCloseCancel = cancelA` (which only clears `timeoutA` and removes `onEndA` — it does not call `cleanupA`).
3. Before `onEndA`/`timeoutA` fire, user re-triggers `openFormModal()` → `openWithTransition(modal)` runs.
4. `openWithTransition` sees `modal._pendingCloseCancel` is set, calls it (`cancelA` runs: `clearTimeout(timeoutA)`, `modal.removeEventListener('transitionend', onEndA)`), then `delete modal._pendingCloseCancel`. Note `cleanupA` is never invoked.
5. `openWithTransition` proceeds: `modal.hidden = false`, and on the next animation frame adds `is-open` back, starting a fresh opacity transition.
6. When that reopen transition ends, there is no `onEndA` listener left to catch it (it was removed in step 4), and `timeoutA` was cleared, so nothing stale fires. The modal that was just reopened stays open, populated, and focused — no flash/hide/wipe.
7. The same trace applies verbatim to `askConfirmation`: if `finish()` (which calls `closeWithTransition(dialog, cleanup)`) is in flight and the dialog is re-opened via a fresh `askConfirmation(message)` call, `openWithTransition(dialog)` cancels the pending listener/timeout the same way before re-showing the dialog.

### Trace 2 — normal close that fully completes (no reopen)

1. `closeWithTransition(backdrop, cleanup)` arms `onEnd`/`timeoutId` and sets `_pendingCloseCancel`.
2. Case A: `transitionend` fires first with `event.target === backdrop` → `onEnd` calls `finish()`, which clears `timeoutId`, removes `onEnd`, deletes `_pendingCloseCancel`, and calls `cleanup()` exactly once. The now-cleared `timeoutId` cannot fire `finish()` again.
3. Case B: `transitionend` never fires (e.g., element removed/no compositing) → `timeoutId` fires `finish()` at 220ms, which removes `onEnd` (so a late/spurious `transitionend` afterward is a no-op) and deletes `_pendingCloseCancel`, calling `cleanup()` exactly once.
4. Either way `cleanup()` runs exactly once, matching pre-fix behavior — unaffected by the new `_pendingCloseCancel` bookkeeping since nothing reopened the backdrop.

### Trace 3 — normal open then close (no overlap)

1. `openWithTransition(backdrop)`: `backdrop._pendingCloseCancel` is undefined (nothing pending), so the new guard is a no-op; `hidden = false` and `is-open` added on next frame as before.
2. Later, `closeWithTransition` runs normally per Trace 2. Unaffected.

### Verification

1. `node --check public/assets/js/modal.js` — passed with no output.
2. `node --test tests/JavaScript/*.test.js` — `pass 15`, `fail 0`, `cancelled 0` (unchanged from before the fix; modal.js has no direct unit tests, this is the sibling-module regression guard).
3. Traces above confirm the stale `onEnd`/timeout from a prior close is always cancelled before a reopen's own transition can trigger it, and that the pre-existing non-overlapping open/close paths still invoke `cleanup()` exactly once.
4. Confirmed `state.activeFormRequest`/`requestCoordinator` handling in `closeModal()` is untouched — it still runs before `closeWithTransition` is called, entirely outside the two helpers that were modified.
