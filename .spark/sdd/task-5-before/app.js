'use strict';

(() => {
    const state = {
        confirmedForms: new WeakSet(),
        activeFormRequest: null,
        modalTrigger: null,
    };
    const modal = InventoryModal.create({state});
    const forms = InventoryForms.create({modal, state});
    const tables = InventoryTables.create();

    document.addEventListener('DOMContentLoaded', () => {
        modal.ensureOverlays();
        InventoryNavigation.buildShell();
        tables.enhance();
        InventoryCharts.render();
        enhanceLinks();
        forms.enhanceForms();
        modal.setEnhanceForms(forms.enhanceForms);
    });

    function enhanceLinks() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || link.target || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const href = link.getAttribute('href') || '';
            if (/\/(create|edit)(\?|$)/.test(href)) {
                event.preventDefault();
                modal.openFormModal(href);
                return;
            }
            if (href.startsWith('/') && !href.endsWith('.csv')) modal.setPageLoading(true);
        });
    }
})();
