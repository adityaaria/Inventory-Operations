'use strict';

// Printable documents (D-19, ADR-025): the Print button opens the browser print dialog.
(function enablePrint(root) {
    function bind(document) {
        document.querySelectorAll('[data-print]').forEach(button => {
            if (button.dataset.printBound) return;
            button.dataset.printBound = 'true';
            button.addEventListener('click', () => root.print());
        });
    }
    if (typeof module === 'object' && module.exports) {
        module.exports = { bind };
        return;
    }
    if (root.document) root.document.addEventListener('DOMContentLoaded', () => bind(root.document));
})(typeof globalThis === 'object' ? globalThis : this);
