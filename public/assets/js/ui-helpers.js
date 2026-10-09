'use strict';

(function exposeUiHelpers(root) {
    function needsConfirmation(action) {
        return /(activate|deactivate|submit|approve|cancel|issue|receive|order|delete)/.test(action);
    }

    function matchesFilter(value, term) {
        return String(value).toLowerCase().includes(String(term).trim().toLowerCase());
    }

    // Rupiah cells ("Rp 20.000,00") use dots for thousands and a comma for decimals.
    function numericValue(text) {
        const rupiah = /^(-?)Rp\s?([\d.]+)(?:,(\d+))?$/.exec(text);
        if (rupiah) return Number(rupiah[1] + rupiah[2].replaceAll('.', '') + '.' + (rupiah[3] || '0'));
        return Number(text.replace(/[^0-9.-]/g, ''));
    }

    function compareTableValues(left, right, direction = 'asc') {
        const a = String(left).trim();
        const b = String(right).trim();
        const numericA = numericValue(a);
        const numericB = numericValue(b);
        const bothNumeric = Number.isFinite(numericA)
            && Number.isFinite(numericB)
            && /\d/.test(a)
            && /\d/.test(b);
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

    const api = {compareTableValues, debounce, escapeCsvCell, escapeHtml, matchesFilter, needsConfirmation, resolveCancelTarget};

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }

    root.InventoryUi = api;
})(typeof globalThis === 'object' ? globalThis : this);
