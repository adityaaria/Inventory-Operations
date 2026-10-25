'use strict';

(function exposeOrderItems(root) {
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

    /** Master price carried by the selected product option (`data-price`); the default line price. */
    function masterPrice(select) {
        return select.selectedOptions[0]?.dataset.price ?? '';
    }

    /** Units of a product option (`data-units`, D-16/ADR-022): [{code, factor, base, purchase_price, selling_price}]. */
    function parseUnits(json) {
        try {
            const units = JSON.parse(json || '[]');
            return Array.isArray(units) ? units.filter(unit => unit && typeof unit.code === 'string' && Number.isInteger(unit.factor) && unit.factor >= 1) : [];
        } catch (error) {
            return [];
        }
    }

    /** Base quantity of a whole unit quantity, or null when the quantity is not a positive whole number. */
    function baseQuantity(quantity, factor) {
        const text = String(quantity ?? '').trim();
        if (!/^\d+$/.test(text) || Number(text) <= 0) return null;
        return Number(text) * (Number(factor) || 1);
    }

    function groupNumber(value) {
        const digits = String(Math.abs(value));
        let grouped = '';
        for (let end = digits.length; end > 0; end -= 3) grouped = digits.slice(Math.max(0, end - 3), end) + (grouped ? '.' + grouped : '');
        return (value < 0 ? '-' : '') + grouped;
    }

    /**
     * Same display as App\Support\QuantityFormatter: "101 pcs", "2 karton (96 pcs)", "2 karton + 5 pcs (101 pcs)".
     * Greedy over the units, largest factor first; the base total is always shown.
     */
    function formatQuantity(base, units, baseCode) {
        const total = groupNumber(base) + ' ' + baseCode;
        let remaining = Math.abs(base);
        const parts = [];
        for (const unit of [...units].filter(unit => !unit.base && unit.factor > 1).sort((a, b) => b.factor - a.factor)) {
            const count = Math.floor(remaining / unit.factor);
            if (count > 0) {
                parts.push(groupNumber(count) + ' ' + unit.code);
                remaining -= count * unit.factor;
            }
        }
        if (parts.length === 0) return total;
        if (remaining > 0) parts.push(groupNumber(remaining) + ' ' + baseCode);
        return (base < 0 ? '-' : '') + parts.join(' + ') + ' (' + total + ')';
    }

    /** Rebuilds a line's unit select from the product option; selects the form's default unit (or keeps a valid choice). */
    function fillUnits(unitSelect, productOption, sales, keep) {
        if (!unitSelect || !productOption) return;
        const units = parseUnits(productOption.dataset.units);
        if (units.length === 0) return;
        const baseCode = productOption.dataset.baseUnit || units.find(unit => unit.base)?.code || '';
        const wanted = keep && units.some(unit => unit.code === keep) ? keep : (productOption.dataset.defaultUnit || baseCode);
        unitSelect.replaceChildren(...units.map(unit => {
            const option = unitSelect.ownerDocument.createElement('option');
            option.value = unit.code;
            option.textContent = unit.base ? unit.code : unit.code + ' (' + unit.factor + ' ' + baseCode + ')';
            option.dataset.factor = String(unit.factor);
            option.dataset.price = sales ? unit.selling_price : unit.purchase_price;
            option.selected = unit.code === wanted;
            return option;
        }));
    }

    /** Whole cents of a plain decimal, or null when empty/invalid (the server treats empty as the master price). */
    function cents(value) {
        const text = String(value ?? '').trim();
        if (text === '' || !/^\d+(\.\d+)?$/.test(text)) return null;
        return Math.round(Number(text) * 100);
    }

    /** True when a typed price differs from the master price (D-13, ADR-019); then a reason is required. */
    function priceDiffers(price, master) {
        const typed = cents(price);
        const base = cents(master);
        return typed !== null && base !== null && typed !== base;
    }

    /** Hint shown under the price: difference to master in % and Rupiah per unit; empty when equal or unknown. */
    function differenceLabel(price, master) {
        if (!priceDiffers(price, master)) return '';
        const delta = cents(price) - cents(master);
        const base = cents(master);
        const percent = base === 0 ? 'n/a' : (delta < 0 ? '-' : '+') + Math.abs(delta * 100 / base).toFixed(2).replace('.', ',') + '%';
        return ' · differs by ' + percent + ' (' + (delta > 0 ? '+' : '') + formatRupiah(delta / 100) + ' per unit); reason required';
    }

    /** Same display as App\Support\Money::rupiah(): "Rp 20.000,00"; empty when there is no price. */
    function formatRupiah(value) {
        const amount = Number(value);
        if (value === '' || value === null || value === undefined || !Number.isFinite(amount)) return '';
        const [whole, cents] = Math.abs(amount).toFixed(2).split('.');
        let grouped = '';
        for (let end = whole.length; end > 0; end -= 3) grouped = whole.slice(Math.max(0, end - 3), end) + (grouped ? '.' + grouped : '');
        return (amount < 0 ? '-' : '') + 'Rp ' + grouped + ',' + cents;
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
        const sales = Boolean(form.querySelector('[data-order-input="selling_price"]'));
        const productOption = row => row.querySelector('[data-order-input="product_id"]').selectedOptions[0];
        const unitSelect = row => row.querySelector('[data-order-unit]');

        /** Master price of the line: the selected unit's price (D-16), else the product option's base price. */
        function lineMaster(row) {
            return unitSelect(row)?.selectedOptions[0]?.dataset.price ?? masterPrice(row.querySelector('[data-order-input="product_id"]'));
        }

        /** "= 96 pcs" under the quantity: what the line takes from or adds to stock, in base units. */
        function updateBaseHint(row) {
            const hint = row.querySelector('[data-order-base-hint]');
            const option = productOption(row);
            if (!hint || !option) return;
            const unit = unitSelect(row)?.selectedOptions[0];
            const factor = Number(unit?.dataset.factor || 1);
            const base = baseQuantity(row.querySelector('[data-order-input="quantity"]')?.value, factor);
            const units = parseUnits(option.dataset.units);
            hint.textContent = base === null || factor === 1 ? '' : '= ' + formatQuantity(base, units, option.dataset.baseUnit || '');
        }

        /** Updates the master hint, difference and reason requirement; never overwrites a typed price. */
        function updatePrice(row) {
            const price = row.querySelector('[data-order-price]');
            if (!price) return;
            const master = lineMaster(row);
            const differs = priceDiffers(price.value, master);
            const masterText = row.querySelector('[data-order-master]');
            if (masterText) masterText.textContent = formatRupiah(master) || '-';
            const masterUnit = row.querySelector('[data-order-master-unit]');
            const unit = unitSelect(row)?.value;
            if (masterUnit) masterUnit.textContent = unit ? ' per ' + unit : '';
            updateBaseHint(row);
            const diff = row.querySelector('[data-order-price-diff]');
            if (diff) diff.textContent = differenceLabel(price.value, master);
            const reason = row.querySelector('[data-order-reason]');
            const field = row.querySelector('[data-order-reason-field]');
            if (reason) {
                reason.required = differs;
                if (differs) reason.minLength = 3; else reason.removeAttribute('minlength');
            }
            if (field) field.hidden = !differs && (!reason || reason.value.trim() === '');
        }

        /** Product change or reset: the line goes back to the product's master price and needs no reason. */
        function useMasterPrice(row) {
            const price = row.querySelector('[data-order-price]');
            if (!price) return;
            price.value = lineMaster(row);
            const reason = row.querySelector('[data-order-reason]');
            if (reason) reason.value = '';
            updatePrice(row);
        }

        /** Product change: the unit list follows the product and starts at the form's default unit. */
        function useProduct(row) {
            fillUnits(unitSelect(row), productOption(row), sales, null);
            useMasterPrice(row);
        }

        function refresh() {
            const items = rows();
            items.forEach((row, index) => {
                row.querySelector('legend').textContent = 'Product item ' + (index + 1);
                row.querySelector('[data-order-item-message]').textContent = '';
                const price = row.querySelector('[data-order-price]');
                const units = unitSelect(row);
                if (units && units.options.length === 0) fillUnits(units, productOption(row), sales, null);
                if (price && price.value.trim() === '') price.value = lineMaster(row);
                updatePrice(row);
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
            const reset = event.target.closest('[data-order-price-reset]');
            if (reset) {
                const row = reset.closest('[data-order-item]');
                useMasterPrice(row);
                // Lets form drafts save the reset value like a typed one.
                row.querySelector('[data-order-price]').dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }
            const remove = event.target.closest('[data-order-remove]');
            if (!remove || remove.disabled) return;
            remove.closest('[data-order-item]').remove();
            add.focus();
            refresh();
        });
        form.addEventListener('change', event => {
            if (event.target.dataset.orderInput === 'product_id') {
                useProduct(event.target.closest('[data-order-item]'));
                refresh();
            } else if (event.target.dataset.orderInput === 'unit_code') {
                useMasterPrice(event.target.closest('[data-order-item]')); // the master price is per unit
            } else if (event.target.dataset.orderInput === 'quantity') {
                updateBaseHint(event.target.closest('[data-order-item]'));
            } else if (event.target.matches?.('[data-order-price], [data-order-reason]')) {
                updatePrice(event.target.closest('[data-order-item]')); // e.g. a restored form draft
            }
        });
        form.addEventListener('input', event => {
            const row = event.target.closest?.('[data-order-item]');
            if (!row) return;
            // Some browsers and scripted input fire only `input` for a select; the new product's master price applies.
            if (event.target.dataset.orderInput === 'product_id') { useProduct(row); refresh(); }
            else if (event.target.dataset.orderInput === 'quantity') updateBaseHint(row);
            else if (event.target.matches('[data-order-price], [data-order-reason]')) updatePrice(row);
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

    const api = { MAX_ITEMS, baseQuantity, cents, differenceLabel, duplicateIndexes, fillUnits, formatQuantity, formatRupiah, itemName, masterPrice, offPagePicks, parseUnits, priceDiffers, enhanceAll };

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
})(typeof globalThis === 'object' ? globalThis : this);
