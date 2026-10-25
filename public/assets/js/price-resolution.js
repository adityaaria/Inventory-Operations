'use strict';

/**
 * Live price resolution on the SO and quotation forms (D-19, ADR-025). Forms with `data-price-resolution="<url>"` ask the
 * server which list price a line gets (customer price list tier for product, unit and quantity, else master) whenever the
 * customer, product, unit or quantity changes, and show the price with its source and the next tier. A price the user did
 * not override follows the resolved price; an overridden price is kept (order-items.js then shows its difference and
 * requires a reason). Display only: the server re-resolves every line when the document is saved.
 */
(function exposePriceResolution(root) {
    const DEBOUNCE_MS = 250;

    /** GET URL for one line; quantity is sent only when it is a positive whole number (the server uses 1 otherwise). */
    function resolutionUrl(base, line) {
        const params = new URLSearchParams();
        params.set('customer_id', String(line.customerId ?? ''));
        params.set('product_id', String(line.productId ?? ''));
        if (line.unitCode) params.set('unit_code', String(line.unitCode));
        const quantity = String(line.quantity ?? '').trim();
        if (/^\d+$/.test(quantity) && Number(quantity) > 0) params.set('quantity', quantity);
        return base + (base.includes('?') ? '&' : '?') + params.toString();
    }

    function cents(value) {
        const text = String(value ?? '').trim();
        if (text === '' || !/^\d+(\.\d{1,2})?$/.test(text)) return null;
        const [whole, fraction = ''] = text.split('.');
        return Number(whole) * 100 + Number((fraction + '00').slice(0, 2));
    }

    /** A price follows the list price when it is empty or still equal to the previous list price (not overridden). */
    function shouldFollow(currentPrice, previousList) {
        const current = cents(currentPrice);
        return current === null || (cents(previousList) !== null && current === cents(previousList));
    }

    function rupiah(value) {
        const format = root.InventoryOrderItems?.formatRupiah;
        if (typeof format === 'function') return format(value);
        return 'Rp ' + Number(value).toFixed(2);
    }

    /** " · from 50 karton: Rp 95.000,00" for the next cheaper tier, else empty. */
    function nextTierText(result, format = rupiah) {
        if (!result || !result.next_tier) return '';
        return ' · from ' + result.next_tier.min_quantity + ' ' + result.unit_code + ': ' + format(result.next_tier.price);
    }

    /**
     * Applies a server answer to one order line: the selected unit option carries the list price (order-items.js reads it
     * as the line's list price), a non-overridden price follows it, and the source and next tier are shown. Returns true
     * when the price input was changed.
     */
    function applyResolution(row, result, format = rupiah) {
        const unit = row.querySelector('[data-order-unit]');
        const option = unit && unit.selectedOptions ? unit.selectedOptions[0] : null;
        const price = row.querySelector('[data-order-price]');
        if (!price || !result || typeof result.price !== 'string') return false;
        if (option && option.value !== result.unit_code) return false; // stale answer for another unit
        const product = row.querySelector('[data-order-input="product_id"]');
        if (product && result.product_id !== undefined && String(result.product_id) !== String(product.value)) return false; // or another product
        const previous = option ? (option.dataset.price ?? '') : '';
        const follow = shouldFollow(price.value, previous);
        if (option) option.dataset.price = result.price;
        const source = row.querySelector('[data-order-price-source]');
        if (source) source.textContent = result.label || (result.source === 'PRICE_LIST' ? 'Price list' : 'Master price');
        const next = row.querySelector('[data-order-next-tier]');
        if (next) next.textContent = nextTierText(result, format);
        if (follow) {
            price.value = result.price;
            const reason = row.querySelector('[data-order-reason]');
            if (reason) reason.value = '';
        }
        return follow;
    }

    function showProblem(row, message) {
        const source = row.querySelector('[data-order-price-source]');
        if (source) source.textContent = 'List price unavailable: ' + message;
    }

    function enhanceForm(form, fetchImpl) {
        if (form.dataset.priceResolutionEnhanced) return;
        form.dataset.priceResolutionEnhanced = 'true';
        const base = form.dataset.priceResolution;
        const timers = new WeakMap();
        let sequence = 0;
        const customer = () => form.querySelector('[data-price-customer]')?.value ?? '';
        const rows = () => [...form.querySelectorAll('[data-order-items] [data-order-item]')];

        async function resolve(row, ticket) {
            const product = row.querySelector('[data-order-input="product_id"]')?.value ?? '';
            if (!base || customer() === '' || product === '' || row.dataset.resolutionTicket !== ticket) return;
            const url = resolutionUrl(base, {
                customerId: customer(),
                productId: product,
                unitCode: row.querySelector('[data-order-unit]')?.value ?? '',
                quantity: row.querySelector('[data-order-input="quantity"]')?.value ?? '',
            });
            try {
                const response = await fetchImpl(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } });
                const body = await response.json();
                if (row.dataset.resolutionTicket !== ticket) return; // the line changed after this request was made
                if (!response.ok) { showProblem(row, body && body.error ? body.error : 'HTTP ' + response.status); return; }
                applyResolution(row, body);
                // Lets order-items.js recompute the "differs" hint and reason requirement, and form drafts save the value.
                row.querySelector('[data-order-price]')?.dispatchEvent(new Event('input', { bubbles: true }));
            } catch (error) {
                if (row.dataset.resolutionTicket === ticket) showProblem(row, 'the server will apply it when you save');
            }
        }

        /** Every change takes a new ticket at once, so an answer to a request made before the change is never applied. */
        function schedule(row) {
            const ticket = String(++sequence);
            row.dataset.resolutionTicket = ticket;
            // Never leave the previous product's or quantity's source on screen while the new answer is pending.
            const source = row.querySelector('[data-order-price-source]');
            if (source) source.textContent = 'Checking list price…';
            const next = row.querySelector('[data-order-next-tier]');
            if (next) next.textContent = '';
            clearTimeout(timers.get(row));
            timers.set(row, setTimeout(() => resolve(row, ticket), DEBOUNCE_MS));
        }

        form.addEventListener('change', event => {
            if (event.target.matches?.('[data-price-customer]')) { rows().forEach(schedule); return; }
            const row = event.target.closest?.('[data-order-item]');
            if (row && event.target.matches('[data-order-input="product_id"], [data-order-unit], [data-order-input="quantity"]')) schedule(row);
        });
        form.addEventListener('input', event => {
            const row = event.target.closest?.('[data-order-item]');
            if (row && event.target.matches?.('[data-order-input="quantity"]')) schedule(row);
        });
        // New lines (Add product item) are resolved when they appear.
        if (root.MutationObserver) new root.MutationObserver(() => rows().filter(row => !row.dataset.resolutionTicket).forEach(schedule)).observe(form, { childList: true, subtree: true });
        rows().forEach(schedule);
    }

    function enhanceAll(document, fetchImpl) {
        document.querySelectorAll('form[data-price-resolution]').forEach(form => enhanceForm(form, fetchImpl));
    }

    const api = { DEBOUNCE_MS, resolutionUrl, shouldFollow, nextTierText, applyResolution, enhanceForm, enhanceAll };
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }
    root.InventoryPriceResolution = api;
    if (root.document) root.document.addEventListener('DOMContentLoaded', () => {
        api.enhanceAll(root.document, root.fetch.bind(root));
        // Create forms are also loaded into the modal on list pages.
        if (root.MutationObserver) new root.MutationObserver(() => api.enhanceAll(root.document, root.fetch.bind(root))).observe(root.document.body, { childList: true, subtree: true });
    });
})(typeof globalThis === 'object' ? globalThis : this);
