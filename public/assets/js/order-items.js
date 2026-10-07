'use strict';

(function exposeOrderItems(root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }

    root.InventoryOrderItems = api;
    if (root.document) root.document.addEventListener('DOMContentLoaded', () => {
        api.enhanceAll(root.document);
        // Create forms are also loaded into the modal on list pages.
        if (root.MutationObserver) new root.MutationObserver(() => api.enhanceAll(root.document)).observe(root.document.body, { childList: true, subtree: true });
    });
})(typeof globalThis === 'object' ? globalThis : this, () => {
    const MAX_ITEMS = 100;

    /** Positions whose product already appeared on an earlier line (server rejects duplicates too). */
    function duplicateIndexes(productIds) {
        const seen = new Set();
        return productIds.reduce((duplicates, id, index) => {
            if (id !== '' && seen.has(id)) duplicates.push(index);
            seen.add(id);
            return duplicates;
        }, []);
    }

    function itemName(index, field) {
        return 'items[' + index + '][' + field + ']';
    }

    function enhanceOrderForm(form) {
        if (form.dataset.orderEnhanced) return;
        form.dataset.orderEnhanced = 'true';
        const add = form.querySelector('[data-order-add]');
        const rows = () => [...form.querySelectorAll('[data-order-items] [data-order-item]')];
        let next = rows().length;

        function refresh() {
            const items = rows();
            items.forEach((row, index) => {
                row.querySelector('legend').textContent = 'Product item ' + (index + 1);
                row.querySelector('[data-order-item-message]').textContent = '';
            });
            const duplicates = duplicateIndexes(items.map(row => row.querySelector('[data-order-input="product_id"]').value));
            for (const index of duplicates) items[index].querySelector('[data-order-item-message]').textContent = 'Choose a different product; duplicate products are not allowed.';
            add.disabled = items.length >= MAX_ITEMS;
            form.querySelector('[type="submit"]').disabled = duplicates.length > 0;
        }

        add.addEventListener('click', () => {
            if (rows().length >= MAX_ITEMS) return;
            const row = form.querySelector('[data-order-template]').content.firstElementChild.cloneNode(true);
            for (const control of row.querySelectorAll('[data-order-input]')) control.name = itemName(next, control.dataset.orderInput);
            next++;
            row.querySelector('[data-order-remove]').disabled = false;
            form.querySelector('[data-order-items]').appendChild(row);
            refresh();
            row.querySelector('[data-order-input="product_id"]').focus();
        });
        form.addEventListener('click', event => {
            const remove = event.target.closest('[data-order-remove]');
            if (!remove || remove.disabled) return;
            remove.closest('[data-order-item]').remove();
            add.focus();
            refresh();
        });
        form.addEventListener('change', event => {
            if (event.target.dataset.orderInput === 'product_id') refresh();
        });
        refresh();
    }

    /** Picks to add as hidden inputs: stored selections from other pages, never duplicating on-page boxes. */
    function offPagePicks(stored, onPageProducts) {
        return Object.entries(stored).filter(([product]) => !onPageProducts.includes(product)).map(([, pick]) => pick);
    }

    function enhanceSelection(form) {
        if (form.dataset.selectionEnhanced) return;
        form.dataset.selectionEnhanced = 'true';
        const warehouse = form.querySelector('input[name="warehouse_id"]');
        // Checkboxes live in the table and join this form through the HTML form attribute.
        const boxes = [...form.elements].filter(control => control.name === 'pick[]');
        if (!warehouse || boxes.length === 0) return;
        const key = 'ioms-replenishment:' + warehouse.value;
        const storage = (() => { try { return window.sessionStorage; } catch (error) { return null; } })();
        const read = () => { try { return JSON.parse(storage.getItem(key)) || {}; } catch (error) { return {}; } };
        const write = value => { try { storage.setItem(key, JSON.stringify(value)); } catch (error) { /* selection stays on this page only */ } };
        const product = box => box.value.split(':')[0];
        const summary = document.createElement('p');
        summary.setAttribute('role', 'status');
        summary.dataset.selectionSummary = '';
        form.querySelector('.form-actions')?.prepend(summary);
        const stored = storage ? read() : {};
        for (const box of boxes) if (stored[product(box)]) box.checked = true;
        const render = () => { const count = Object.keys(storage ? read() : {}).length || boxes.filter(box => box.checked).length; summary.textContent = count + ' recommendation(s) selected' + (storage ? ' across pages' : '') + '.'; };
        const onChange = event => {
            const box = event.target;
            if (!storage) return render();
            const current = read();
            if (box.checked && !current[product(box)] && Object.keys(current).length >= MAX_ITEMS) {
                box.checked = false;
                box.setCustomValidity('A purchase order can hold at most ' + MAX_ITEMS + ' products.');
                box.reportValidity();
                box.addEventListener('change', () => box.setCustomValidity(''), { once: true });
                return;
            }
            if (box.checked) current[product(box)] = box.value; else delete current[product(box)];
            write(current);
            render();
        };
        for (const box of boxes) box.addEventListener('change', onChange);
        // Capture phase runs before the shared forms.js handler, so a blocked submit never shows the page loader.
        form.addEventListener('submit', event => {
            form.querySelectorAll('[data-off-page-pick]').forEach(input => input.remove());
            const extra = storage ? offPagePicks(read(), boxes.map(product)) : [];
            if (!boxes.some(box => box.checked) && extra.length === 0) {
                event.preventDefault();
                event.stopImmediatePropagation();
                boxes[0].setCustomValidity('Select at least one recommendation.');
                boxes[0].reportValidity();
                boxes[0].addEventListener('change', () => boxes[0].setCustomValidity(''), { once: true });
                return;
            }
            for (const pick of extra) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden'; hidden.name = 'pick[]'; hidden.value = pick; hidden.dataset.offPagePick = '';
                form.append(hidden);
            }
            if (storage) try { storage.removeItem(key); } catch (error) { /* ignore */ }
        }, true);
        render();
    }

    function enhanceAll(document) {
        document.querySelectorAll('[data-order-form]').forEach(enhanceOrderForm);
        document.querySelectorAll('[data-replenishment-selection]').forEach(enhanceSelection);
    }

    return { MAX_ITEMS, duplicateIndexes, itemName, offPagePicks, enhanceAll };
});
