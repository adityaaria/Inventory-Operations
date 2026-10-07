'use strict';

(function exposeValidation(root, factory) {
    const api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
        return;
    }

    root.InventoryValidation = api;
})(typeof globalThis === 'object' ? globalThis : this, () => {
    function labelFor(field) {
        return String(field.name || 'Field')
            .replace(/[_-]+/g, ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase());
    }

    function validateField(field) {
        const label = labelFor(field);
        const value = String(field.value ?? '').trim();

        if (field.required && field.type === 'checkbox' && !field.checked) {
            return `${label} must be confirmed.`;
        }
        if (field.required && value === '') {
            return `${label} is required.`;
        }
        if (value === '' || field.type !== 'number') {
            return null;
        }

        const number = Number(value);
        if (!Number.isFinite(number)) {
            return `${label} must be a valid number.`;
        }
        if (field.min !== '' && field.min != null && number < Number(field.min)) {
            return `${label} must be at least ${field.min}.`;
        }
        if (field.max !== '' && field.max != null && number > Number(field.max)) {
            return `${label} must be at most ${field.max}.`;
        }

        return null;
    }

    function validateFields(fields) {
        return fields.reduce((errors, field) => {
            if (!field.name || field.disabled || ['button', 'submit', 'reset', 'hidden'].includes(field.type)) {
                return errors;
            }
            const error = validateField(field);
            if (error !== null) {
                errors[field.name] = error;
            }
            return errors;
        }, {});
    }

    return {validateField, validateFields};
});
