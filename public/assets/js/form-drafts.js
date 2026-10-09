'use strict';

// Browser-local recovery for long create forms. Drafts are scoped to one user and role, expire with the absolute
// session limit (D-08, `data-draft-ttl` seconds, default 8 hours),
// never hold CSRF tokens, operation keys, passwords or count baselines, and are removed once a submission is
// actually sent (the `formdata` event, after client validation and confirmation) and on logout.
// Restoring revalidates permissions, master-data status and current stock on the server before submit;
// the submit itself is still fully validated by the services.
(function exposeFormDrafts(root) {
    const PREFIX = 'ioms-draft:v1:';
    const TTL_MS = 8 * 60 * 60 * 1000;
    const MAX_TTL_MS = 7 * 24 * 60 * 60 * 1000;
    const EXCLUDED = /^(csrf_token|operation_key|password.*|(items\[\d+\]\[)?baseline\]?)$/;
    const ITEM = /^items\[(\d+)\]\[([a-z_]+)\]$/;

    function storageKey(owner, form) {
        return PREFIX + owner + ':' + form;
    }

    function draftable(name, type) {
        return name !== '' && !EXCLUDED.test(name) && !['hidden', 'password', 'file', 'submit', 'button'].includes(type);
    }

    /** Lifetime in ms from a `data-draft-ttl` value in seconds; invalid or out-of-range values fall back to 8 hours. */
    function ttlFrom(seconds) {
        const ms = Number(seconds) * 1000;
        return Number.isFinite(ms) && ms >= 60000 && ms <= MAX_TTL_MS ? ms : TTL_MS;
    }

    function isExpired(savedAt, now, ttl = TTL_MS) {
        return !Number.isFinite(savedAt) || now - savedAt > ttl || savedAt > now + 60000;
    }

    /** Distinct supplementary item indexes in saved order. */
    function itemIndexes(names) {
        return [...new Set(names.map(name => ITEM.exec(name)).filter(Boolean).map(match => Number(match[1])))].sort((a, b) => a - b);
    }

    function remapName(name, mapping) {
        const match = ITEM.exec(name);
        return match && mapping.has(Number(match[1])) ? 'items[' + mapping.get(Number(match[1])) + '][' + match[2] + ']' : name;
    }

    /** Advisory messages from a /drafts/check response; blocking ones stop submission until corrected. */
    function checkMessages(result, lines, label) {
        const messages = [];
        if (result.warehouse && !result.warehouse.active) messages.push({ blocking: true, field: 'warehouse_id', text: 'The selected warehouse is no longer active.' });
        for (const item of result.items || []) {
            const line = lines.find(candidate => candidate.productId === item.product_id);
            if (!line) continue;
            if (!item.active) messages.push({ blocking: true, field: line.field, text: label(line) + ' is no longer active; choose another product.' });
            else if (line.outbound && item.available !== null && line.quantity > item.available) {
                messages.push({ blocking: false, field: line.field, text: 'Only ' + item.available + ' unit(s) of ' + label(line) + ' are in stock in this warehouse now.' });
            }
        }
        return messages;
    }

    function safeStorage(root) {
        try {
            const storage = root.localStorage;
            const probe = PREFIX + 'probe';
            storage.setItem(probe, '1');
            storage.removeItem(probe);
            return storage;
        } catch {
            // Blocked or full storage (privacy mode): drafts are simply disabled.
            return null;
        }
    }

    function clearAll(storage) {
        if (!storage) return;
        try {
            for (let index = storage.length - 1; index >= 0; index--) {
                const key = storage.key(index);
                if (key?.startsWith(PREFIX)) storage.removeItem(key);
            }
        } catch (error) { /* storage unavailable: nothing to clear */ }
    }

    function purgeExpired(storage, now) {
        if (!storage) return;
        try {
            for (let index = storage.length - 1; index >= 0; index--) {
                const key = storage.key(index);
                if (!key?.startsWith(PREFIX)) continue;
                let draft = null;
                try { draft = JSON.parse(storage.getItem(key)); } catch (error) { draft = null; }
                if (!draft || isExpired(draft.savedAt, now, ttlFrom(draft.ttl / 1000))) storage.removeItem(key);
            }
        } catch (error) { /* storage unavailable */ }
    }

    function fieldLabel(control) {
        const label = control.closest('label');
        const caption = label?.querySelector('.field-label');
        return (caption ? caption.textContent : control.name).trim().toLowerCase();
    }

    // Enabled product lines of a restored form, in the shape checkMessages() expects.
    function productLines(form, outbound) {
        return [...form.querySelectorAll('select[name="product_id"], select[name$="[product_id]"]')]
            .filter(select => !select.disabled)
            .map(select => {
                const quantity = form.elements.namedItem(select.name.replace('product_id', 'quantity'));
                return { field: select, productId: Number(select.value), quantity: quantity ? Number(quantity.value) : 0, outbound, label: select.selectedOptions[0] ? select.selectedOptions[0].textContent.trim() : 'This product' };
            });
    }

    function enhance(form, root, storage) {
        const owner = form.dataset.draftOwner;
        const formKey = form.dataset.draft;
        if (!owner || !formKey || form.dataset.draftEnhanced) return;
        form.dataset.draftEnhanced = 'true';
        const key = storageKey(owner, formKey);
        const ttl = ttlFrom(form.dataset.draftTtl);
        const document = form.ownerDocument;
        let timer = null;

        const controls = () => [...form.elements].filter(control => draftable(control.name || '', control.type) && !control.readOnly);
        const read = () => { try { return JSON.parse(storage.getItem(key)); } catch (error) { return null; } };
        const remove = () => { try { storage.removeItem(key); } catch (error) { /* ignore */ } };
        function save() {
            const values = controls().map(control => {
                const unchecked = control.type === 'checkbox' && !control.checked;
                return [control.name, unchecked ? '' : control.value];
            });
            try { storage.setItem(key, JSON.stringify({ savedAt: Date.now(), ttl, values })); } catch (error) { /* quota or privacy mode */ }
        }

        form.addEventListener('input', () => { root.clearTimeout(timer); timer = root.setTimeout(save, 400); });
        form.addEventListener('change', event => { if (event.isTrusted) { root.clearTimeout(timer); timer = root.setTimeout(save, 400); } });
        // Fired only when data is really being sent, so cancelled confirmations or failed validation keep the draft.
        form.addEventListener('formdata', () => { root.clearTimeout(timer); remove(); });

        const draft = read();
        const container = form.closest('main, .modal-body') || form;
        // A server re-render with errors (422) already shows the submitted values; keep them recoverable.
        if (!draft && container.querySelector('.alert-danger')) { save(); return; }
        if (!draft || !Array.isArray(draft.values)) return;
        if (isExpired(draft.savedAt, Date.now(), ttl)) { remove(); return; }

        const banner = document.createElement('div');
        banner.className = 'alert alert-info';
        banner.setAttribute('role', 'status');
        banner.dataset.draftBanner = '';
        const text = document.createElement('p');
        text.textContent = 'An unsaved draft from ' + new Date(draft.savedAt).toLocaleString() + ' was found on this device.';
        const list = document.createElement('ul');
        const actions = document.createElement('div');
        actions.className = 'form-actions';
        const restore = document.createElement('button');
        restore.type = 'button';
        restore.textContent = 'Restore draft';
        const discard = document.createElement('button');
        discard.type = 'button';
        discard.className = 'button button-quiet';
        discard.textContent = 'Discard draft';
        actions.append(restore, discard);
        banner.append(text, list, actions);
        form.prepend(banner);

        discard.addEventListener('click', () => { remove(); banner.remove(); });
        restore.addEventListener('click', async () => {
            actions.remove();
            const unavailable = apply(draft.values);
            text.textContent = 'Draft restored. Checking current permissions, status and stock…';
            for (const label of unavailable) addMessage('The saved ' + label + ' is no longer available and was not restored.');
            await revalidate();
        });

        function addMessage(message) {
            const item = document.createElement('li');
            item.textContent = message;
            list.append(item);
        }

        function setValue(control, value, unavailable) {
            if (control.tagName === 'SELECT') {
                const available = [...control.options].some(candidate => candidate.value === value && !candidate.disabled);
                if (!available) {
                    if (value !== '') unavailable.push(fieldLabel(control));
                    return;
                }
                control.value = value;
            } else if (control.type === 'checkbox') {
                control.checked = value !== '' && value === control.value;
            } else {
                control.value = value;
            }
            control.dispatchEvent(new root.Event('change', { bubbles: true }));
        }

        function apply(values) {
            const unavailable = [];
            const byName = new Map(values);
            const kind = form.elements.namedItem('kind');
            if (kind && byName.has('kind')) setValue(kind, byName.get('kind'), unavailable);
            const add = form.querySelector('[data-order-add], [data-stock-add]');
            const mapping = new Map();
            for (const index of itemIndexes(values.map(([name]) => name))) {
                if (!add || add.disabled) break;
                add.click();
                const rows = form.querySelectorAll('[data-order-item], [data-stock-item]');
                const named = rows[rows.length - 1].querySelector('[name^="items["]');
                const match = named && ITEM.exec(named.name);
                if (match) mapping.set(index, Number(match[1]));
            }
            for (const [name, value] of values) {
                if (name === 'kind' || !draftable(name, '')) continue;
                const control = form.elements.namedItem(remapName(name, mapping));
                if (control?.tagName) setValue(control, value, unavailable);
            }
            return unavailable;
        }

        // Lists every message; blocking ones also stop native submit until the field changes.
        function showMessages(messages, warehouse) {
            for (const message of messages) {
                addMessage(message.text);
                const field = message.field === 'warehouse_id' ? warehouse : message.field;
                if (message.blocking && field) {
                    field.setCustomValidity(message.text);
                    field.addEventListener('change', () => field.setCustomValidity(''), { once: true });
                }
            }
        }

        async function revalidate() {
            const kind = form.elements.namedItem('kind');
            const outbound = formKey === 'sales-order' || (kind && ['Transfer', 'SupplierReturn'].includes(kind.value));
            const warehouse = form.elements.namedItem('warehouse_id');
            const lines = productLines(form, outbound);
            const query = new URLSearchParams({ form: formKey });
            if (warehouse && !warehouse.disabled && warehouse.value !== '') query.set('warehouse_id', warehouse.value);
            for (const line of lines) query.append('product_ids[]', String(line.productId));
            try {
                const response = await root.fetch('/drafts/check?' + query, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' });
                if (response.status === 403 || response.status === 401) {
                    text.textContent = 'You are no longer allowed to submit this form. The draft was kept on this device.';
                    for (const button of form.querySelectorAll('[type="submit"]')) button.disabled = true;
                    return;
                }
                if (!response.ok) throw new Error('check failed');
                const messages = checkMessages(await response.json(), lines, line => line.label);
                showMessages(messages, warehouse);
                text.textContent = messages.length === 0
                    ? 'Draft restored and checked: permissions, status and current stock are fine. Submitting checks everything again.'
                    : 'Draft restored. Review the items below; submitting checks everything again.';
            } catch {
                // Network or server failure: say so; the submit path revalidates anyway.
                text.textContent = 'Draft restored, but it could not be checked now. Submitting still validates permissions, status and stock.';
            }
        }
    }

    function start(root) {
        const document = root.document;
        const storage = safeStorage(root);
        document.addEventListener('submit', event => {
            if (event.target.matches?.('form[action="/logout"]')) clearAll(storage);
        }, true);
        if (!storage) return;
        purgeExpired(storage, Date.now());
        const enhanceAll = () => document.querySelectorAll('form[data-draft]').forEach(form => enhance(form, root, storage));
        enhanceAll();
        // Create forms are also loaded into the modal on list pages.
        if (root.MutationObserver) new root.MutationObserver(enhanceAll).observe(document.body, { childList: true, subtree: true });
    }

    const api = { PREFIX, TTL_MS, ttlFrom, storageKey, draftable, isExpired, itemIndexes, remapName, checkMessages, clearAll, purgeExpired, start };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }

    root.InventoryFormDrafts = api;
    if (root.document) root.document.addEventListener('DOMContentLoaded', () => api.start(root));
})(typeof globalThis === 'object' ? globalThis : this);
