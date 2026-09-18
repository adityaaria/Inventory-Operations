'use strict';

(function exposeModal(root) {
    root.InventoryModal = {
        create({state}) {
            let enhanceForms = () => {};
            const requestCoordinator = InventoryHttp.createRequestCoordinator();

            function setEnhanceForms(handler) {
                enhanceForms = handler;
            }

            function ensureOverlays() {
                if (!document.querySelector('.page-loading')) {
                    document.body.append(createPageLoader());
                }
                if (!document.querySelector('.modal-backdrop')) {
                    document.body.append(createModal());
                }
                if (!document.querySelector('.confirm-backdrop')) {
                    document.body.append(createConfirmDialog());
                }
            }

            function createPageLoader() {
                const loader = document.createElement('div');
                loader.className = 'page-loading';
                loader.setAttribute('aria-hidden', 'true');
                loader.innerHTML = '<span></span><strong>Loading</strong>';
                return loader;
            }

            function setPageLoading(active) {
                document.body.classList.toggle('is-page-loading', active);
            }

            function openWithTransition(backdrop) {
                if (backdrop._pendingCloseCancel) {
                    backdrop._pendingCloseCancel();
                    delete backdrop._pendingCloseCancel;
                }
                backdrop.hidden = false;
                requestAnimationFrame(() => backdrop.classList.add('is-open'));
            }

            function closeWithTransition(backdrop, cleanup) {
                if (!backdrop.classList.contains('is-open')) {
                    cleanup();
                    return;
                }
                backdrop.classList.remove('is-open');
                const finish = () => {
                    clearTimeout(timeoutId);
                    backdrop.removeEventListener('transitionend', onEnd);
                    delete backdrop._pendingCloseCancel;
                    cleanup();
                };
                // 220ms = the 180ms CSS transition above plus a small safety margin,
                // in case transitionend never fires (e.g. the element was removed).
                const timeoutId = setTimeout(finish, 220);
                function onEnd(event) {
                    if (event.target !== backdrop) {
                        return;
                    }
                    finish();
                }
                backdrop.addEventListener('transitionend', onEnd);
                backdrop._pendingCloseCancel = () => {
                    clearTimeout(timeoutId);
                    backdrop.removeEventListener('transitionend', onEnd);
                };
            }

            function createModal() {
                const modal = document.createElement('div');
                modal.className = 'modal-backdrop';
                modal.hidden = true;
                modal.innerHTML = `
                    <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="modal-title" tabindex="-1">
                        <header class="modal-header">
                            <h2 id="modal-title">Form</h2>
                            <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                        </header>
                        <div class="modal-body" aria-live="polite"></div>
                    </section>
                `;
                modal.addEventListener('click', (event) => {
                    if (event.target === modal || event.target.closest('.modal-close')) {
                        closeModal();
                    }
                });
                document.addEventListener('keydown', (event) => {
                    if (modal.hidden) {
                        return;
                    }
                    if (event.key === 'Escape') {
                        closeModal();
                        return;
                    }
                    if (event.key === 'Tab') {
                        trapFocus(modal, event);
                    }
                });
                return modal;
            }

            function trapFocus(container, event) {
                const focusable = [...container.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')]
                    .filter((element) => !element.disabled && !element.hidden);
                if (focusable.length === 0) {
                    event.preventDefault();
                    return;
                }
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }

            function createConfirmDialog() {
                const dialog = document.createElement('div');
                dialog.className = 'confirm-backdrop';
                dialog.hidden = true;
                dialog.innerHTML = `
                    <section class="confirm-panel" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
                        <h2 id="confirm-title">Confirm action</h2>
                        <p class="confirm-message">Continue this action?</p>
                        <div class="confirm-actions">
                            <button type="button" class="button confirm-cancel">Cancel</button>
                            <button type="button" class="button-primary confirm-submit">Continue</button>
                        </div>
                    </section>
                `;
                return dialog;
            }

            async function openFormModal(href) {
                const modal = document.querySelector('.modal-backdrop');
                const body = modal?.querySelector('.modal-body');
                const title = modal?.querySelector('#modal-title');
                if (!modal || !body || !title) {
                    location.href = href;
                    return;
                }

                state.modalTrigger = document.activeElement;
                openWithTransition(modal);
                document.body.classList.add('has-modal');
                modal.setAttribute('aria-busy', 'true');
                body.innerHTML = '<div class="loading-card"><span></span><strong>Loading form</strong></div>';
                modal.querySelector('.modal-panel')?.focus();
                const request = requestCoordinator.start();
                state.activeFormRequest = request;

                try {
                    const {html} = await InventoryHttp.fetchHtml(href, {signal: request.signal});
                    if (!request.isCurrent()) {
                        return;
                    }
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const remoteMain = doc.querySelector('main.page');
                    const remoteTitle = remoteMain?.querySelector('h1')?.textContent?.trim() || 'Form';
                    title.textContent = remoteTitle;
                    body.innerHTML = remoteMain ? remoteMain.innerHTML : html;
                    enhanceForms(body);
                } catch (error) {
                    if (request.signal.aborted || error.name === 'AbortError') {
                        return;
                    }
                    body.innerHTML = `
                        <div class="alert" role="alert">
                            <strong>Unable to load this form.</strong>
                            <span>Please try again or close this dialog.</span>
                            <button type="button" class="button" data-modal-retry>Try again</button>
                        </div>
                    `;
                    body.querySelector('[data-modal-retry]')?.addEventListener('click', () => openFormModal(href));
                } finally {
                    if (request.isCurrent()) {
                        modal.setAttribute('aria-busy', 'false');
                        request.finish();
                        if (state.activeFormRequest === request) {
                            state.activeFormRequest = null;
                        }
                    }
                }
            }

            function closeModal() {
                requestCoordinator.cancel();
                state.activeFormRequest = null;
                const modal = document.querySelector('.modal-backdrop');
                if (!modal) {
                    return;
                }
                closeWithTransition(modal, () => {
                    modal.hidden = true;
                    document.body.classList.remove('has-modal');
                    const body = modal.querySelector('.modal-body');
                    if (body) {
                        body.innerHTML = '';
                    }
                    if (state.modalTrigger && typeof state.modalTrigger.focus === 'function') {
                        state.modalTrigger.focus();
                    }
                    state.modalTrigger = null;
                });
            }

            function askConfirmation(message) {
                const dialog = document.querySelector('.confirm-backdrop');
                if (!dialog) {
                    return Promise.resolve(window.confirm(message));
                }
                dialog.querySelector('.confirm-message').textContent = message;
                openWithTransition(dialog);

                return new Promise((resolve) => {
                    const cancel = dialog.querySelector('.confirm-cancel');
                    const submit = dialog.querySelector('.confirm-submit');
                    const finish = (answer) => {
                        closeWithTransition(dialog, () => {
                            dialog.hidden = true;
                        });
                        cancel.removeEventListener('click', onCancel);
                        submit.removeEventListener('click', onSubmit);
                        resolve(answer);
                    };
                    const onCancel = () => finish(false);
                    const onSubmit = () => finish(true);
                    cancel.addEventListener('click', onCancel);
                    submit.addEventListener('click', onSubmit);
                    submit.focus();
                });
            }

            return {askConfirmation, closeModal, ensureOverlays, openFormModal, setEnhanceForms, setPageLoading};
        },
    };
})(typeof globalThis === 'object' ? globalThis : this);
