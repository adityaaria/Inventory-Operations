'use strict';

(function exposeUiHelpers(root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }

    root.InventoryUi = api;
})(typeof globalThis === 'object' ? globalThis : this, () => {
    function needsConfirmation(action) {
        return /(activate|deactivate|submit|approve|cancel|issue|receive|order|delete)/.test(action);
    }

    function matchesFilter(value, term) {
        return String(value).toLowerCase().includes(String(term).trim().toLowerCase());
    }

    function compareTableValues(left, right, direction = 'asc') {
        const a = String(left).trim();
        const b = String(right).trim();
        const numericA = Number(a.replace(/[^0-9.-]/g, ''));
        const numericB = Number(b.replace(/[^0-9.-]/g, ''));
        const bothNumeric = Number.isFinite(numericA)
            && Number.isFinite(numericB)
            && /[0-9]/.test(a)
            && /[0-9]/.test(b);
        const result = bothNumeric ? numericA - numericB : a.localeCompare(b);

        return direction === 'asc' ? result : -result;
    }

    function escapeCsvCell(value) {
        let text = String(value).replace(/\s+/g, ' ').trim();
        if (/^[\u0000-\u0020]*[=+\-@]/.test(text)) text = "'" + text;
        return `"${text.replaceAll('"', '""')}"`;
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    const defaultTimerApi = {clearTimeout, setTimeout};

    function debounce(callback, delay = 200, timerApi = defaultTimerApi) {
        let timerId = null;
        const debounced = (...args) => {
            if (timerId !== null) timerApi.clearTimeout(timerId);
            timerId = timerApi.setTimeout(() => {
                timerId = null;
                callback(...args);
            }, delay);
        };
        debounced.cancel = () => {
            if (timerId !== null) timerApi.clearTimeout(timerId);
            timerId = null;
        };
        return debounced;
    }

    function resolveCancelTarget(cancelHref, isInsideModal) {
        return isInsideModal ? {action: 'close-modal'} : {action: 'navigate', href: cancelHref};
    }

    return {compareTableValues, debounce, escapeCsvCell, escapeHtml, matchesFilter, needsConfirmation, resolveCancelTarget};
});
