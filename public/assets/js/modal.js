'use strict';

(function exposeModal(root) {
    root.InventoryModal = {
        create({state}) {
            let enhanceForms = () => {};
            let pageLoadingTimer = null;
            let pageLoadingActive = false;
            root.addEventListener('pageshow', () => setPageLoading(false));
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
                loader.setAttribute('role', 'status');
                loader.setAttribute('aria-live', 'polite');
                loader.setAttribute('aria-hidden', 'true');
                loader.innerHTML = '<span aria-hidden="true"></span><strong class="page-loading-label">Loading page…</strong>';
                return loader;
            }

            function setPageLoading(active) {
                if (active && pageLoadingActive) return;
                pageLoadingActive = active;
                clearTimeout(pageLoadingTimer);
                pageLoadingTimer = null;
                const loader = document.querySelector('.page-loading');
                if (!active) {
                    document.body.classList.remove('is-page-loading');
                    loader?.setAttribute('aria-hidden', 'true');
                    return;
                }
                // Fast navigation completes without flashing an indicator.
                pageLoadingTimer = setTimeout(() => {
                    document.body.classList.add('is-page-loading');
                    loader?.setAttribute('aria-hidden', 'false');
                    pageLoadingTimer = null;
                }, 180);
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
                        <header class="modal-header"><h2 id="confirm-title">Confirm action</h2></header>
                        <div class="modal-body"><p class="confirm-message">Continue this action?</p></div>
                        <div class="confirm-actions modal-footer">
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
                    renderForm(html);
                } catch (error) {
                    if (request.signal.aborted || error.name === 'AbortError') {
                        return;
                    }
                    const sessionMessage = InventoryHttp.sessionFailureMessage(error);
                    body.innerHTML = `
                        <div class="alert" role="alert">
                            <strong>${sessionMessage || 'Unable to load this form.'}</strong>
                            ${sessionMessage ? '<a class="button" href="/login">Sign in</a>' :
                                '<span>Please try again or close this dialog.</span><button type="button" class="button" data-modal-retry>Try again</button>'}
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

            function renderForm(html) {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const remoteMain = doc.querySelector('main.page');
                const body = document.querySelector('.modal-backdrop .modal-body');
                const title = document.querySelector('#modal-title');
                if (!body || !title) return;
                const header = remoteMain?.querySelector('.page-header');
                title.textContent = header?.querySelector('h1')?.textContent?.trim() || 'Form';
                const subtitle = header?.querySelector('.page-subtitle');
                // The page heading/navigation belongs to the page, not the dialog body.
                if (subtitle) {
                    subtitle.classList.add('modal-description');
                    header.replaceWith(subtitle);
                } else {
                    header?.remove();
                }
                body.innerHTML = remoteMain ? remoteMain.innerHTML : html;
                body.querySelectorAll('.form-actions').forEach(actions => {
                    actions.classList.add('modal-footer');
                    const cancel = actions.querySelector('[data-cancel-href]');
                    if (cancel) actions.prepend(cancel);
                });
                enhanceForms(body);
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

            return {askConfirmation, closeModal, ensureOverlays, openFormModal, renderForm, setEnhanceForms, setPageLoading};
        },
    };
})(typeof globalThis === 'object' ? globalThis : this);
