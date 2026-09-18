# UI Polish Enhancement — Design Tokens, Modal Transitions, Button Semantics

Date: 2026-09-11
Status: Approved by user (all three sections)
Owner: Frontend (CSS + Vanilla JS layer)

## Purpose

Three related UI polish items requested by the user, scoped and approved through brainstorming:

1. Design system — expand and consolidate CSS tokens so components stop repeating literal spacing/radius values.
2. Loading state — modal and confirm dialogs currently pop in/out with no transition.
3. Button semantics — 14 "Cancel" links in create/edit views are `<a href>` elements that trigger a full page navigation when clicked inside a modal, instead of just closing the modal.

None of these change layout structure, page hierarchy, or the core color hues already in use. This is a consistency and polish pass on top of the existing custom CSS + Vanilla JS architecture documented in `.docs/PROJECT_PROFILE.md` and `docs/quality/ai-insight-html-css.md`.

## Scope Boundaries

**In scope:**
- New spacing/radius CSS custom properties in `public/assets/css/app.css`, and updating existing selectors to consume them instead of literal values, for: `.button`/`.button-primary`, `.card`/`.metric-card`, `.status-badge`, `.modal-panel`, `.field-error`, `table.data-table`.
- Fade + scale transition for `.modal-backdrop`, `.confirm-backdrop`, and `.modal-panel`, respecting `prefers-reduced-motion`.
- A small change to `modal.js`'s open/close functions so `[hidden]` toggling doesn't skip the transition.
- Converting 14 `<a href="...">Cancel</a>` elements to `<button type="button" data-cancel-href="...">Cancel</button>`, plus one new delegated click handler in `app.js`.

**Out of scope (explicitly not touched):**
- Sidebar/topbar/dashboard grid structure.
- Core color hues (`--primary`, `--accent`, semantic status colors).
- Table filter/sort transitions, full-page navigation transitions (`.page-loading`) — these were considered and explicitly excluded during scoping.
- Any PHP/backend business logic. This is a pure presentation-layer change; no controller, service, repository, or database code is touched.

## Architecture & Components

### 1. Token layer (`public/assets/css/app.css`, top of file)

Add to the existing `:root` block (do not create a second block):

```css
--space-1: 0.25rem;  /* 4px  — tight gaps: icon+label, badge padding */
--space-2: 0.5rem;   /* 8px  — inline gaps, small padding */
--space-3: 0.75rem;  /* 12px — form field gaps */
--space-4: 1rem;     /* 16px — card padding, section gaps */
--space-5: 1.5rem;   /* 24px — page section spacing */
--space-6: 2rem;     /* 32px — page-level spacing */

--radius-sm: 6px;   /* badges, chips, small controls */
--radius-md: 10px;  /* cards, inputs, buttons */
--radius-lg: 16px;  /* modal panel, large surfaces */
```

`--radius: 8px` stays defined (existing consumers keep working) but new/touched selectors use the tiered names. It is not deleted in this pass — removing it is a separate cleanup that risks breaking selectors not touched here.

### 2. Consistency audit method

For each of the six target selector groups, the literal `rem`/`px` values currently hardcoded are located with `grep -n "padding\|margin\|gap\|border-radius" app.css` scoped to that selector's block, then replaced with the nearest matching token. A value that doesn't cleanly match a token tier is left as-is and noted in the plan rather than forced — this pass optimizes for consistency, not for eliminating every literal number.

### 3. Modal transition (`app.css` + `modal.js`)

CSS:

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
.modal-panel {
    transform: scale(0.96);
    opacity: 0;
    transition: transform 0.18s ease, opacity 0.18s ease;
}
.is-open .modal-panel {
    transform: scale(1);
    opacity: 1;
}
@media (prefers-reduced-motion: reduce) {
    .modal-backdrop, .confirm-backdrop, .modal-panel {
        transition-duration: 0.01ms;
    }
}
```

`modal.js` behavior change — the functions that currently do `element.hidden = false` / `element.hidden = true` are updated to:

- **Open:** remove `hidden` first (so the element is in the layout), then on the next animation frame add `is-open` (so the transition actually fires instead of being coalesced with the `hidden` removal in the same paint).
- **Close:** remove `is-open` (starts the fade/scale-out), then set `hidden = true` after the transition ends — listened via `transitionend` on the backdrop, with a `setTimeout` fallback (matching the transition duration + a small margin) in case `transitionend` doesn't fire (e.g., the element was already at its end state).

This touches `openFormModal`, `closeModal`, and the confirmation dialog's show/hide in `modal.js`. The delegated click/keydown listeners (`Escape`, `.modal-close`, focus trap) are unaffected — they already operate on the `hidden` state and continue to.

### 4. Button semantics fix

Markup change (14 files: `views/{categories,customers,products,purchase-orders,sales-orders,suppliers,users,warehouses}/create.php` and the edit.php counterpart for the six that have one):

```html
<!-- before -->
<a href="/products">Cancel</a>

<!-- after -->
<button type="button" class="button" data-cancel-href="/products">Cancel</button>
```

New handler in `app.js`, added next to the existing `enhanceLinks()` function and wired in the same `DOMContentLoaded` block:

```js
function enhanceCancelButtons() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-cancel-href]');
        if (!button) return;
        const insideModal = button.closest('.modal-panel');
        if (insideModal) {
            modal.closeModal();
            return;
        }
        window.location.href = button.dataset.cancelHref;
    });
}
```

This mirrors the existing pattern in `enhanceLinks()` (delegated document-level listener, `closest()` for targeting) rather than introducing a new architectural style.

## Data Flow

No server data flow changes. This is presentation-only:

- Modal open/close: unchanged data flow (`InventoryHttp.fetchHtml` → inject into `.modal-body` → enhance forms) — only the *visual* open/close is touched, not the fetch/abort logic already covered by the JavaScript Decision Record.
- Cancel button: no longer triggers a server request at all when inside a modal (previously it triggered a real navigation `GET /products`, which the browser then had to load and JS had to re-parse). Standalone-page behavior (`GET /products` navigation) is preserved exactly.

## Error Handling

- If `transitionend` never fires (e.g., `prefers-reduced-motion` collapses the duration to near-zero, or a browser quirk), the `setTimeout` fallback in `modal.js` still sets `hidden = true`, so the modal cannot get stuck visually "open but see-through."
- The new `data-cancel-href` handler has no failure mode beyond a normal link navigation — if `insideModal` is falsy, it falls through to `window.location.href`, which behaves exactly like the `<a href>` it replaces.
- No new error states are introduced; existing error handling in `modal.js` (`HttpResponseError`, `NetworkRequestError`, retry button) is untouched.

## Testing

- **CSS:** no automated test coverage exists for CSS (consistent with the project's existing testing strategy — `.docs/TESTING_STRATEGY.md` covers PHP/JS behavior, not visual regression). Verification here is manual: open a representative page and confirm no layout shift or broken spacing on `/products`, `/dashboard`, and one modal.
- **JS behavior (new):** `app.js` itself is not currently structured for unit testing (unlike `http.js`/`ui-helpers.js`, it's a top-level IIFE with no exported seams, and has no existing tests). Rather than test the IIFE directly, the implementation plan will extract the branch decision (modal vs standalone) into a small pure function — e.g., `resolveCancelTarget(button, isInsideModal)` — placed in `ui-helpers.js` alongside `debounce`/`matchesFilter`, which `app.js` then calls. That function gets a `node:test` case; `app.js`'s wiring itself stays covered only by the manual smoke test below, consistent with how the rest of `app.js` is currently verified.
- **Manual smoke test (recorded in verification, not automated):**
  1. Log in, go to `/products`, click "Create Product" → modal opens with visible fade/scale-in.
  2. Click "Cancel" inside the modal → modal closes with fade/scale-out, **no page navigation** (URL stays `/products`).
  3. Directly visit `/products/create` (no modal wrapper) → click "Cancel" → navigates to `/products` as before.
  4. Repeat step 1–2 for at least one other create/edit pair (e.g., `/suppliers/create`) to confirm the fix generalizes, not just for products.
  5. Toggle OS-level "reduce motion" and confirm the modal still opens/closes correctly (just without the animated transition).
- Existing `node --test tests/JavaScript/*.test.js` suite must continue to pass unchanged (15 existing + new cancel-button test).
- `composer test` and `composer analyse` (PHPUnit + PHPStan) must continue to pass — this change touches PHP view files but only markup, not PHP logic, so no PHP test should be affected; running them is a regression guard, not because logic changed.

## Rollout Note

This is a single cohesive frontend change; it does not need to be split into parallel sub-tasks (`dispatching-parallel-agents` was considered and is not warranted — CSS token work, the modal transition, and the button fix all touch the same small set of files and are easier to keep coherent as one sequential implementation plan).
