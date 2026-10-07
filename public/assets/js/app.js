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
        enhanceCancelButtons();
        InventoryDialog.enhance();
        forms.enhanceForms();
        modal.setEnhanceForms(forms.enhanceForms);
    });

    function enhanceLinks() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href]');
            if (!link || link.target || link.hasAttribute('download') || event.button !== 0 || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const href = link.getAttribute('href') || '';
            if (/\/(create|edit)(\?|$)/.test(href)) {
                event.preventDefault();
                modal.openFormModal(href);
                return;
            }
            const destination = new URL(href, window.location.href);
            const samePage = destination.pathname === window.location.pathname
                && destination.search === window.location.search && destination.hash;
            if (destination.origin === window.location.origin && !samePage
                && !destination.pathname.endsWith('.csv')) modal.setPageLoading(true);
        });
    }

    function enhanceCancelButtons() {
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-cancel-href]');
            if (!button) return;
            const isInsideModal = Boolean(button.closest('.modal-panel'));
            const target = InventoryUi.resolveCancelTarget(button.dataset.cancelHref, isInsideModal);
            if (target.action === 'close-modal') {
                modal.closeModal();
                return;
            }
            modal.setPageLoading(true);
            window.location.href = target.href;
        });
    }
})();
