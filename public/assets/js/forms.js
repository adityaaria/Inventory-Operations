'use strict';

(function exposeForms(root) {
    root.InventoryForms = {
        create({modal, state}) {
            function enhanceForms(rootElement = document) {
                rootElement.querySelectorAll('form').forEach((form) => {
                    if (form.dataset.enhanced === 'true') return;
                    form.dataset.enhanced = 'true';
                    form.addEventListener('submit', (event) => {
                        if (!validateForm(form)) {
                            event.preventDefault();
                            return;
                        }
                        handleSubmit(event, form);
                    });
                });
            }

            function validateForm(form) {
                clearValidationErrors(form);
                const errors = InventoryValidation.validateFields([...form.elements]);
                const firstInvalid = Object.keys(errors).reduce((first, name) => {
                    const field = [...form.elements].find((element) => element.name === name);
                    if (!field) return first;
                    field.setAttribute('aria-invalid', 'true');
                    const error = document.createElement('span');
                    error.className = 'field-error';
                    error.id = `${form.id || 'form'}-${name}-error`;
                    error.setAttribute('role', 'alert');
                    error.textContent = errors[name];
                    field.insertAdjacentElement('afterend', error);
                    field.setAttribute('aria-describedby', error.id);
                    return first || field;
                }, null);
                if (firstInvalid) firstInvalid.focus();
                return Object.keys(errors).length === 0;
            }

            function clearValidationErrors(form) {
                form.querySelectorAll('.field-error').forEach((error) => error.remove());
                form.querySelectorAll('[aria-invalid="true"]').forEach((field) => {
                    field.removeAttribute('aria-invalid');
                    field.removeAttribute('aria-describedby');
                });
            }

            async function handleSubmit(event, form) {
                const method = (form.getAttribute('method') || 'get').toLowerCase();
                if (method !== 'post') {
                    modal.setPageLoading(true);
                    return;
                }
                const needsConfirmation = InventoryUi.needsConfirmation(form.getAttribute('action') || '');
                if (needsConfirmation && !state.confirmedForms.has(form)) {
                    event.preventDefault();
                    const button = form.querySelector('button[type="submit"], button:not([type])');
                    const message = `Confirm ${(button?.textContent?.trim() || 'continue').toLowerCase()}?`;
                    if (await modal.askConfirmation(message)) {
                        state.confirmedForms.add(form);
                        form.requestSubmit(event.submitter || undefined);
                    }
                    return;
                }
                form.querySelector('[data-submitter-value]')?.remove();
                const submittedData = new FormData(form, event.submitter);
                if (event.submitter?.name) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden'; hidden.name = event.submitter.name;
                    hidden.value = event.submitter.value; hidden.dataset.submitterValue = 'true';
                    form.append(hidden);
                }
                setButtonLoading(form, true);
                if (form.closest('.modal-backdrop')) {
                    event.preventDefault();
                    submitModalForm(form, submittedData);
                    return;
                }
                modal.setPageLoading(true);
            }

            async function submitModalForm(form, submittedData) {
                try {
                    const {html, response} = await InventoryHttp.fetchHtml(form.action, {
                        allowedStatuses: [422], method: 'POST', body: submittedData,
                    });
                    if (response.ok && response.url && response.url !== location.href) {
                        location.href = response.url;
                        return;
                    }
                    if (response.status === 422) {
                        modal.renderForm(html);
                        return;
                    }
                    location.reload();
                } catch (error) {
                    setButtonLoading(form, false);
                    renderSubmitError(form, error);
                }
            }

            function renderSubmitError(form, error) {
                const container = form.closest('.modal-body') || form.parentElement;
                if (!container) return;
                container.querySelector('.form-request-error')?.remove();
                const sessionMessage = InventoryHttp.sessionFailureMessage(error);
                const message = sessionMessage || (error instanceof InventoryHttp.HttpResponseError
                    ? 'The server could not process this request. Please try again.'
                    : 'The request could not be completed. Check your connection and try again.');
                const alert = document.createElement('div');
                alert.className = 'alert form-request-error';
                alert.setAttribute('role', 'alert');
                alert.innerHTML = `<strong>${message}</strong> ${sessionMessage
                    ? '<a class="button" href="/login">Sign in</a>'
                    : '<button type="button" class="button" data-retry-submit>Try again</button>'}`;
                container.insertBefore(alert, form);
                alert.querySelector('[data-retry-submit]')?.addEventListener('click', () => {
                    alert.remove();
                    form.requestSubmit();
                });
                alert.querySelector('[data-retry-submit]')?.focus();
            }

            function setButtonLoading(form, active) {
                const button = form.querySelector('button[type="submit"], button:not([type])');
                if (!button) return;
                if (active) {
                    button.dataset.originalText = button.textContent || '';
                    button.classList.add('is-loading');
                    button.disabled = true;
                    button.textContent = 'Processing';
                } else {
                    button.classList.remove('is-loading');
                    button.disabled = false;
                    button.textContent = button.dataset.originalText || button.textContent || '';
                }
            }

            return {enhanceForms};
        },
    };
})(typeof globalThis === 'object' ? globalThis : this);
